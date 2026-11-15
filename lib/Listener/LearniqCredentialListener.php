<?php

/**
 * Humaniq LearniqCredentialListener
 *
 * Takes a credential learniq issues back onto the personnel file
 * (talent-training-and-lms D4). learniq's CredentialIssuanceHandler saves a
 * `credential` object in its own register when a learner completes a course;
 * OpenRegister dispatches `ObjectCreatedEvent` for it. When the learner's
 * Nextcloud account (LearnerProfile.ncUserId) belongs to an employee, this
 * listener writes one TrainingRecord: status `gevolgd`, the issue date as
 * completion date, the expiry as validity, source `learniq` and the
 * credential's id as `sourceRef`. A second event for the same credential
 * finds that record and writes nothing; an account that belongs to no
 * employee is logged and skipped. humaniq only reads what learniq publishes;
 * it never writes learniq's register.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes a TrainingRecord for a credential learniq issued to an employee.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-003
 */
class LearniqCredentialListener implements IEventListener {

	/**
	 * The register slugs learniq answers to, the current one first
	 * (FleetAppId's candidates for `learniq`).
	 *
	 * @var list<string>
	 */
	public const LEARNIQ_REGISTERS = ['learniq', 'scholiq'];

	/**
	 * learniq's credential schema slug.
	 *
	 * @var string
	 */
	public const CREDENTIAL_SLUG = 'credential';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface   $container Reaches OpenRegister's services.
	 * @param HoursRegisterGateway $gateway   Reads and writes humaniq's register.
	 * @param LoggerInterface      $logger    Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Write the training record for a newly issued learniq credential.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-003
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::CREDENTIAL_SLUG) {
			return;
		}

		$this->takeOver(entity: $entity);
	}//end handle()

	/**
	 * Take over one credential of learniq's register; a failure is logged and
	 * never reaches the write that raised the event.
	 *
	 * @param object $entity The credential's ObjectEntity.
	 *
	 * @return void
	 */
	private function takeOver(object $entity): void {
		try {
			$register = $this->learniqRegisterOf((string)$entity->getRegister());
			if ($register === null) {
				return;
			}

			$this->record(credential: $this->issued(entity: $entity, register: $register));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: LearniqCredentialListener could not take over a learniq credential', ['exception' => $e->getMessage()]);
		}
	}//end takeOver()

	/**
	 * The issued credential of an entity in learniq's register.
	 *
	 * @param object $entity   The credential's ObjectEntity.
	 * @param string $register learniq's register slug.
	 *
	 * @return IssuedCredential
	 */
	private function issued(object $entity, string $register): IssuedCredential {
		$credential = new IssuedCredential();
		$credential->id = (string)$entity->getUuid();
		$credential->data = ($entity->getObject() ?? []);
		$credential->register = $register;

		return $credential;
	}//end issued()

	/**
	 * Write the record for one credential, unless it exists or the learner is no employee.
	 *
	 * @param IssuedCredential $credential The credential.
	 *
	 * @return void
	 */
	private function record(IssuedCredential $credential): void {
		$employee = $this->employeeOf(credential: $credential);
		if ($employee === null || $this->isRecorded(credential: $credential) === true) {
			return;
		}

		$this->gateway->save($this->trainingRecord(credential: $credential, employee: $employee), 'TrainingRecord');
	}//end record()

	/**
	 * The employee the credential's learner account belongs to, or null
	 * (logged) when it belongs to none.
	 *
	 * @param IssuedCredential $credential The credential.
	 *
	 * @return array<string, mixed>|null
	 */
	private function employeeOf(IssuedCredential $credential): ?array {
		$learner = $this->learniqObject(id: (string)($credential->data['learnerId'] ?? ''), schema: 'learner-profile', register: $credential->register);
		$account = trim((string)($learner['ncUserId'] ?? ''));
		$employee = $this->gateway->findEmployeeByUserId($account);
		if ($employee === null) {
			$this->logger->info('humaniq: learniq credential ' . $credential->id . ' skipped, account "' . $account . '" belongs to no employee');
		}

		return $employee;
	}//end employeeOf()

	/**
	 * Whether learniq's record of the credential is already on the personnel file.
	 *
	 * @param IssuedCredential $credential The credential.
	 *
	 * @return bool
	 */
	private function isRecorded(IssuedCredential $credential): bool {
		foreach ($this->gateway->findFiltered('TrainingRecord', ['sourceRef' => $credential->id]) as $existing) {
			if (($existing['source'] ?? null) === 'learniq') {
				return true;
			}
		}

		return false;
	}//end isRecorded()

	/**
	 * The TrainingRecord of the credential for the employee.
	 *
	 * @param IssuedCredential     $credential The credential.
	 * @param array<string, mixed> $employee   The employee.
	 *
	 * @return array<string, mixed>
	 */
	private function trainingRecord(IssuedCredential $credential, array $employee): array {
		return [
			'employeeId' => (string)$employee['id'],
			'title' => $this->courseTitle(credential: $credential),
			'provider' => 'learniq',
			'status' => 'gevolgd',
			'completedOn' => $this->day($credential->data['issuedAt'] ?? null),
			'validUntil' => $this->day($credential->data['expiresAt'] ?? null),
			'studiekostenbeding' => false,
			'source' => 'learniq',
			'sourceRef' => $credential->id,
			'administrationId' => isset($employee['administrationId']) === true ? (string)$employee['administrationId'] : null,
		];
	}//end trainingRecord()

	/**
	 * The name of the credential's course, or the fallback title.
	 *
	 * @param IssuedCredential $credential The credential.
	 *
	 * @return string
	 */
	private function courseTitle(IssuedCredential $credential): string {
		$course = $this->learniqObject(id: (string)($credential->data['courseId'] ?? ''), schema: 'course', register: $credential->register);
		$title = trim((string)($course['name'] ?? ''));

		return $title !== '' ? $title : 'Certificaat uit learniq';
	}//end courseTitle()

	/**
	 * The learniq register slug a register id belongs to, or null when it is
	 * not learniq's (or learniq is not installed).
	 *
	 * @param string $registerId The register id the credential carries.
	 *
	 * @return string|null
	 */
	private function learniqRegisterOf(string $registerId): ?string {
		// ADR-083: establish availability before reaching into OpenRegister.
		if ($registerId === '' || class_exists('OCA\OpenRegister\Db\RegisterMapper') === false) {
			return null;
		}

		$ids = $this->container->get('OCA\OpenRegister\Db\RegisterMapper')->findIdsBySlugs(self::LEARNIQ_REGISTERS);
		foreach (self::LEARNIQ_REGISTERS as $slug) {
			$matches = array_map('strval', ($ids[strtolower($slug)] ?? []));
			if (in_array($registerId, $matches, true) === true || strtolower($registerId) === $slug) {
				return $slug;
			}
		}

		return null;
	}//end learniqRegisterOf()

	/**
	 * One object of learniq's register, read without RBAC because the
	 * listener runs as whoever issued the credential.
	 *
	 * @param string $id       The object id.
	 * @param string $schema   The schema slug.
	 * @param string $register The register slug.
	 *
	 * @return array<string, mixed>
	 */
	private function learniqObject(string $id, string $schema, string $register): array {
		if ($id === '') {
			return [];
		}

		try {
			$entity = (clone $this->container->get('OCA\OpenRegister\Service\ObjectService'))->find(
				id: $id,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return [];
		}

		$data = $entity?->getObject();

		return is_array($data) === true ? $data : [];
	}//end learniqObject()

	/**
	 * The `YYYY-MM-DD` day of an ISO 8601 moment, or null.
	 *
	 * @param mixed $moment The moment.
	 *
	 * @return string|null
	 */
	private function day(mixed $moment): ?string {
		$text = trim((string)($moment ?? ''));
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text) !== 1) {
			return null;
		}

		return substr($text, 0, 10);
	}//end day()

}//end class
