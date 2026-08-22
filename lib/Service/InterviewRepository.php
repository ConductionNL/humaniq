<?php

/**
 * Interview Repository
 *
 * The Interview/Application/Vacancy read/write collaborator for
 * interview-scheduling, extracted from `InterviewCalendarService` (the
 * `OfferApplicationRepository`/`OfferEsignService` precedent): owns
 * resolving one Interview by id, loading the full Interview/Application/
 * Vacancy sets via OpenRegister's ObjectService, the AVG-safe SUMMARY
 * resolution (design.md D5 — loads ONLY candidateName/vacancyId/title,
 * never email/phone/cvFile/motivation/talentPoolOptIn/rejectedDate/
 * retentionExpiryDate), and the field-merge save that carries every
 * existing Interview field forward unchanged when persisting
 * `calendarEventUid` (OpenRegister's `saveObject()` update path is
 * PUT-semantic -- the exact trap fixed on receipt-ocr/offer-esign).
 * `InterviewCalendarService`/`InterviewSyncEngine` keep every business
 * decision (status dispatch, upsert/remove logic); this class only ever
 * reads or writes register objects.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes Interview/Application/Vacancy objects for interview-scheduling.
 */
class InterviewRepository {

	/**
	 * @var string
	 */
	private const INTERVIEW_SCHEMA = 'Interview';

	/**
	 * @var string
	 */
	private const APPLICATION_SCHEMA = 'Application';

	/**
	 * @var string
	 */
	private const VACANCY_SCHEMA = 'Vacancy';

	/**
	 * ObjectService findAll() page cap, mirroring LeaveCalendarService::LIMIT.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Find one Interview by id, or null when it cannot be loaded/does not
	 * exist.
	 *
	 * @param string $id The Interview id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id): ?array {
		try {
			$row = $this->objectService()->find(id: $id, register: $this->register(), schema: self::INTERVIEW_SCHEMA);
		} catch (Throwable $e) {
			$this->logger->info('InterviewRepository: kon Interview ' . $id . ' niet laden: ' . $e->getMessage());
			return null;
		}

		return $row === null ? null : $this->toArray($row);
	}//end find()

	/**
	 * Load every Interview (capped), as plain arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function loadAllInterviews(): array {
		return $this->loadAll(self::INTERVIEW_SCHEMA);
	}//end loadAllInterviews()

	/**
	 * Build the id-keyed Application index (candidateName + vacancyId),
	 * consumed by `resolveSummary()` for the AVG-safe SUMMARY (design.md
	 * D5). Loads ONLY candidateName/vacancyId — email/phone/cvFile/
	 * motivation/talentPoolOptIn/rejectedDate/retentionExpiryDate are never
	 * read into this index, so they cannot reach the render path at all.
	 *
	 * @return array<string, array<string, string>>
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-005
	 */
	public function loadApplicationIndex(): array {
		$byId = [];
		foreach ($this->loadAll(self::APPLICATION_SCHEMA) as $application) {
			$id = (string)($application['id'] ?? $application['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$byId[$id] = [
				'candidateName' => (string)($application['candidateName'] ?? ''),
				'vacancyId' => (string)($application['vacancyId'] ?? ''),
			];
		}

		return $byId;
	}//end loadApplicationIndex()

	/**
	 * Build the id-keyed Vacancy title index, consumed by `resolveSummary()`.
	 *
	 * @return array<string, string>
	 */
	public function loadVacancyTitleIndex(): array {
		$byId = [];
		foreach ($this->loadAll(self::VACANCY_SCHEMA) as $vacancy) {
			$id = (string)($vacancy['id'] ?? $vacancy['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$byId[$id] = (string)($vacancy['title'] ?? '');
		}

		return $byId;
	}//end loadVacancyTitleIndex()

	/**
	 * Resolve the AVG-safe SUMMARY text (design.md D5): `Sollicitatiegesprek
	 * — {candidateName} ({vacancyTitle})`, falling back to `Sollicitatiegesprek
	 * — kandidaat` when the Application cannot be resolved, so a deleted/
	 * unreadable Application never leaks a raw id into the calendar.
	 *
	 * @param array<string, array<string, string>> $applicationsById The Application index.
	 * @param array<string, string> $vacanciesById The Vacancy title index.
	 * @param string $applicationId The Interview's applicationId.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-005
	 */
	public function resolveSummary(array $applicationsById, array $vacanciesById, string $applicationId): string {
		$application = ($applicationsById[$applicationId] ?? null);
		if ($application === null) {
			return 'Sollicitatiegesprek — kandidaat';
		}

		$candidateName = trim($application['candidateName']);
		if ($candidateName === '') {
			$candidateName = 'kandidaat';
		}

		$vacancyTitle = trim((string)($vacanciesById[$application['vacancyId']] ?? ''));
		if ($vacancyTitle === '') {
			$vacancyTitle = 'vacature';
		}

		return 'Sollicitatiegesprek — ' . $candidateName . ' (' . $vacancyTitle . ')';
	}//end resolveSummary()

	/**
	 * Persist the derived `calendarEventUid` back onto the Interview
	 * (design.md D3): carries every existing field forward unchanged --
	 * OpenRegister's `saveObject()` update path is PUT-semantic, so a
	 * property absent from the payload would otherwise be nulled (the
	 * `OfferApplicationRepository::save()` field-merge idiom). A write
	 * failure is logged and swallowed -- the calendar write itself already
	 * succeeded, so the caller's outcome stays `created`/`updated`; the
	 * next sync's defensive `getCalendarObjectByUID` probe will find the
	 * event and retry the persist.
	 *
	 * @param array<string, mixed> $interview The current Interview (pre-write).
	 * @param string $id The Interview id.
	 * @param string $uid The derived `calendarEventUid` to persist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 */
	public function persistCalendarEventUid(array $interview, string $id, string $uid): void {
		$payload = $interview;
		unset($payload['@self']);
		$payload['calendarEventUid'] = $uid;

		try {
			$this->objectService()->saveObject(
				object: $payload,
				register: $this->register(),
				schema: self::INTERVIEW_SCHEMA,
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('InterviewRepository: kon calendarEventUid niet persisteren voor Interview ' . $id . ': ' . $e->getMessage());
		}

	}//end persistCalendarEventUid()

	/**
	 * Load and normalise every object of a schema via OpenRegister's
	 * ObjectService, degrading to an empty list on any resolution failure.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT]);
		} catch (Throwable $e) {
			$this->logger->warning('InterviewRepository: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to arrays.
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end normaliseRows()

	/**
	 * Normalise a single ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured humaniq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
