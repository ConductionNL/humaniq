<?php

/**
 * Unit tests for LearniqCredentialListener.
 *
 * A credential learniq issues to a learner whose Nextcloud account belongs to
 * an employee becomes one attended TrainingRecord (source learniq, the
 * credential's id), a repeat event writes nothing, an account that belongs to
 * no employee is skipped, and a credential outside learniq's register is
 * ignored. Driven through the real HoursRegisterGateway over the shared
 * FakeObjectStore, with learniq's own Credential, LearnerProfile and Course
 * field names, and the written record validated against the TrainingRecord
 * schema in the register fragment.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\LearniqCredentialListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeRegisterMapper;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Credentials from learniq on the personnel file.
 */
class LearniqCredentialListenerTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	private const LEARNER = '6f1c2b8e-1111-4000-8000-000000000001';

	private const STRANGER = '6f1c2b8e-1111-4000-8000-000000000002';

	private const COURSE = '6f1c2b8e-2222-4000-8000-000000000001';

	private const CREDENTIAL = '6f1c2b8e-3333-4000-8000-000000000001';

	/**
	 * The id FakeRegisterMapper gives learniq's register.
	 *
	 * @var string
	 */
	private const LEARNIQ_REGISTER_ID = '100';

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * What the listener logged.
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * The listener under test.
	 *
	 * @var LearniqCredentialListener
	 */
	private LearniqCredentialListener $listener;

	/**
	 * Seed an employee a.visser, their learner profile, a course and a stranger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('Employee', self::EMPLOYEE, ['firstName' => 'Anna', 'lastName' => 'Visser', 'nextcloudUserId' => 'a.visser', 'administrationId' => 'ADM-001']);
		$this->store->seed('learner-profile', self::LEARNER, ['ncUserId' => 'a.visser', 'tenant_id' => 'gemeente']);
		$this->store->seed('learner-profile', self::STRANGER, ['ncUserId' => 'student.42', 'tenant_id' => 'gemeente']);
		$this->store->seed('course', self::COURSE, ['code' => 'PRIV-01', 'name' => 'Privacy basics']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$container = new FakeContainer([
			'OCA\OpenRegister\Service\ObjectService' => $this->store,
			'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			'OCA\OpenRegister\Db\RegisterMapper' => new FakeRegisterMapper(['learniq']),
		]);
		$gateway = new HoursRegisterGateway(container: $container, settingsService: $settings, orgResolution: new OrgResolutionService());
		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/**
			 * @param list<string> $lines Where to write.
			 */
			public function __construct(private array &$lines) {
			}//end __construct()

			/**
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array<mixed>       $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->lines[] = (string)$message;
			}//end log()
		};

		$this->listener = new LearniqCredentialListener(container: $container, gateway: $gateway, logger: $logger);
	}//end setUp()

	/**
	 * A credential as learniq's CredentialIssuanceHandler saves it.
	 *
	 * @param string      $uuid      The credential id.
	 * @param string      $learnerId The learner profile.
	 * @param string|null $register  The register id the entity carries.
	 *
	 * @return ObjectEntity
	 */
	private function credential(string $uuid, string $learnerId, ?string $register=self::LEARNIQ_REGISTER_ID): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema('credential');
		$entity->setRegister($register);
		$entity->setObject(
			[
				'learnerId' => $learnerId,
				'courseId' => self::COURSE,
				'kind' => 'certificate',
				'issuedAt' => '2026-09-29T10:15:00+00:00',
				'expiresAt' => '2028-09-29T10:15:00+00:00',
				'issuerDid' => 'did:web:learniq.example',
				'signature' => 'sig',
				'openbadges3Payload' => [],
				'tenant_id' => 'gemeente',
			]
		);
		return $entity;
	}//end credential()

	/**
	 * The training records written.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function records(): array {
		return array_values($this->store->state->objects['TrainingRecord'] ?? []);
	}//end records()

	/**
	 * A privacy course completed in learniq lands on the personnel file, once.
	 *
	 * @return void
	 */
	public function testACompletedCourseLandsOnThePersonnelFileOnce(): void {
		$this->listener->handle(new ObjectCreatedEvent($this->credential(self::CREDENTIAL, self::LEARNER)));
		$this->listener->handle(new ObjectCreatedEvent($this->credential(self::CREDENTIAL, self::LEARNER)));

		$records = $this->records();
		self::assertCount(1, $records, 'A second event for the same credential writes nothing.');
		self::assertSame(self::EMPLOYEE, $records[0]['employeeId']);
		self::assertSame('Privacy basics', $records[0]['title']);
		self::assertSame('gevolgd', $records[0]['status']);
		self::assertSame('2026-09-29', $records[0]['completedOn']);
		self::assertSame('2028-09-29', $records[0]['validUntil']);
		self::assertSame('learniq', $records[0]['source']);
		self::assertSame(self::CREDENTIAL, $records[0]['sourceRef']);

		$payload = $records[0];
		unset($payload['id']);
		self::assertSame([], RegisterSchemaValidator::errors('TrainingRecord', $payload), (string)json_encode($payload));
	}//end testACompletedCourseLandsOnThePersonnelFileOnce()

	/**
	 * An account that belongs to no employee is skipped and logged.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutAnEmployeeIsSkippedAndLogged(): void {
		$this->listener->handle(new ObjectCreatedEvent($this->credential(self::CREDENTIAL, self::STRANGER)));

		self::assertSame([], $this->records());
		self::assertNotSame([], array_filter($this->logged, static fn (string $line): bool => str_contains($line, 'student.42')));
	}//end testAnAccountWithoutAnEmployeeIsSkippedAndLogged()

	/**
	 * A credential in another register is not learniq's and is ignored.
	 *
	 * @return void
	 */
	public function testACredentialOutsideLearniqIsIgnored(): void {
		$this->listener->handle(new ObjectCreatedEvent($this->credential(self::CREDENTIAL, self::LEARNER, '999')));

		self::assertSame([], $this->records());
	}//end testACredentialOutsideLearniqIsIgnored()

}//end class
