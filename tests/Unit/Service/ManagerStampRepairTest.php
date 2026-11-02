<?php

/**
 * ManagerStampRepair unit tests
 *
 * The restamp pass over the real gateway and the shared manager resolution,
 * on a fake object store: which records are open, which stamps change, the
 * reference date per schema, the dry run and a second run that changes
 * nothing.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ManagerStampRepair;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Restamping open records.
 *
 * @covers \OCA\Humaniq\Service\ManagerStampRepair
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\OrgResolutionService
 */
class ManagerStampRepairTest extends TestCase {

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The write marker the repair uses.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * Every info line logged, with its context.
	 *
	 * @var list<array{message: string, context: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Whether each save ran inside the write marker.
	 *
	 * @var list<bool>
	 */
	private array $savedInternally = [];

	/**
	 * The gateway over the fake store.
	 *
	 * @var HoursRegisterGateway
	 */
	private HoursRegisterGateway $gateway;

	/**
	 * The subject.
	 *
	 * @var ManagerStampRepair
	 */
	private ManagerStampRepair $repair;

	/**
	 * Sanne manages Consultancy and sits in it, Pieter manages Directie
	 * above it, Tim is placed in Consultancy. Until June Tim sat in Support,
	 * managed by Kees. Everyone is in ADM-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->marker = new InternalWriteMarker();
		$marker = $this->marker;
		$saved = &$this->savedInternally;
		$this->store = new class($marker, $saved) extends FakeObjectStore {
			/**
			 * The record id whose save throws, if any.
			 *
			 * @var string|null
			 */
			public ?string $failOn = null;

			/**
			 * A schema whose reads ignore `offset`, so paging never ends.
			 *
			 * @var string|null
			 */
			public ?string $offsetIgnoredFor = null;

			/**
			 * The schema the next read is for.
			 *
			 * @var string
			 */
			private string $readSchema = '';

			/**
			 * Remember the schema, then set it.
			 *
			 * @param mixed $schema The schema slug.
			 *
			 * @return FakeObjectStore
			 */
			public function setSchema(mixed $schema): FakeObjectStore {
				$this->readSchema = (string)$schema;

				return parent::setSchema($schema);
			}//end setSchema()

			/**
			 * Records whether a save runs inside the marker.
			 *
			 * @param InternalWriteMarker $marker The marker.
			 * @param list<bool> $saved Collects one flag per save.
			 */
			public function __construct(private InternalWriteMarker $marker, private array &$saved) {
				parent::__construct();
			}//end __construct()

			/**
			 * Read a page, or always the first page for the stuck schema.
			 *
			 * @param array<string, mixed> $config The query.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->offsetIgnoredFor !== null && $this->readSchema === $this->offsetIgnoredFor) {
					$config['offset'] = 0;
				}

				return parent::findAll($config, $_rbac, $_multitenancy);
			}//end findAll()

			/**
			 * Record the marker, then save.
			 *
			 * @param array<string, mixed>|object $object The payload.
			 * @param array<int, string>|null $extend Ignored.
			 * @param mixed $register Ignored.
			 * @param mixed $schema The schema slug.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return \OCA\OpenRegister\Db\ObjectEntity
			 */
			public function saveObject(
				array|object $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): \OCA\OpenRegister\Db\ObjectEntity {
				if ($this->failOn !== null && $uuid === $this->failOn) {
					throw new \RuntimeException('validation failed');
				}

				$this->saved[] = $this->marker->isInternal();

				return parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy);
			}//end saveObject()
		};

		$this->store->seed('Employee', 'emp-sanne', ['nextcloudUserId' => 'sanne', 'administrationId' => 'ADM-1']);
		$this->store->seed('Employee', 'emp-pieter', ['nextcloudUserId' => 'pieter', 'administrationId' => 'ADM-1']);
		$this->store->seed('Employee', 'emp-tim', ['nextcloudUserId' => 'tim', 'administrationId' => 'ADM-1']);
		$this->store->seed('Employee', 'emp-kees', ['nextcloudUserId' => 'kees', 'administrationId' => 'ADM-1']);
		$this->store->seed('OrgUnit', 'unit-consultancy', ['managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie', 'administrationId' => 'ADM-1']);
		$this->store->seed('OrgUnit', 'unit-directie', ['managerId' => 'emp-pieter', 'administrationId' => 'ADM-1']);
		$this->store->seed('OrgUnit', 'unit-support', ['managerId' => 'emp-kees', 'administrationId' => 'ADM-1']);
		$this->store->seed('OrgAssignment', 'as-sanne', ['employeeId' => 'emp-sanne', 'orgUnitId' => 'unit-consultancy', 'endDate' => '']);
		$this->store->seed('OrgAssignment', 'as-tim-old', ['employeeId' => 'emp-tim', 'orgUnitId' => 'unit-support', 'startDate' => '2025-01-01', 'endDate' => '2026-05-31']);
		$this->store->seed('OrgAssignment', 'as-tim', ['employeeId' => 'emp-tim', 'orgUnitId' => 'unit-consultancy', 'startDate' => '2026-06-01', 'endDate' => '']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$this->gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = ['message' => $message, 'context' => $context];
		});

		$this->repair = $this->repairWith(logger: $logger);
	}//end setUp()

	/**
	 * Seed one open record of every schema for an employee.
	 *
	 * @param string $employeeId The employee.
	 * @param string $stamp The managerUserId to stamp.
	 *
	 * @return void
	 */
	private function seedOpenRecords(string $employeeId, string $stamp): void {
		$base = ['employeeId' => $employeeId, 'managerUserId' => $stamp];
		$this->store->seed('EmployeeChangeRequest', 'cr-1', $base + ['status' => 'ingediend']);
		$this->store->seed('SideActivity', 'sa-1', $base + ['status' => 'gemeld']);
		$this->store->seed('EmployeeRelationsCase', 'erc-1', $base + ['status' => 'in-behandeling', 'openedOn' => '2026-10-01']);
		$this->store->seed('CommuteArrangement', 'ca-1', $base + ['status' => 'submitted', 'startDate' => '2026-10-01']);
		$this->store->seed('Expense', 'ex-1', $base + ['status' => 'submitted', 'category' => 'travel', 'expenseDate' => '2026-10-01']);
	}//end seedOpenRecords()

	/**
	 * The stamp of one stored record.
	 *
	 * @param string $schema The schema.
	 * @param string $id The record.
	 *
	 * @return mixed
	 */
	private function stampOf(string $schema, string $id): mixed {
		$record = ($this->store->state->objects[$schema][$id] ?? []);

		return array_key_exists('managerUserId', $record) === true ? $record['managerUserId'] : 'missing';
	}//end stampOf()

	/**
	 * A stamp naming the employee themself is rewritten to the manager
	 * above, on every open schema, inside the write marker, and logged.
	 *
	 * @return void
	 */
	public function testASelfStampOnAnOpenRecordIsRestamped(): void {
		$this->seedOpenRecords('emp-sanne', 'sanne');
		$this->store->state->objects['EmployeeChangeRequest']['cr-1']['@self'] = ['id' => 'cr-1', 'created' => '2026-10-01T09:00:00+00:00'];

		$report = $this->repair->run(false);

		foreach (['EmployeeChangeRequest' => 'cr-1', 'SideActivity' => 'sa-1', 'EmployeeRelationsCase' => 'erc-1', 'CommuteArrangement' => 'ca-1', 'Expense' => 'ex-1'] as $schema => $id) {
			self::assertSame('pieter', $this->stampOf($schema, $id), $schema);
			self::assertSame(['inspected' => 1, 'open' => 1, 'changed' => 1, 'failed' => 0], $report[$schema], $schema);
		}

		self::assertSame([true, true, true, true, true], $this->savedInternally);
		self::assertArrayNotHasKey('@self', $this->store->state->saves[0]['payload']);
		self::assertSame('ingediend', $this->store->state->saves[0]['payload']['status']);
		self::assertSame('cr-1', $this->store->state->saves[0]['uuid']);
		self::assertCount(5, $this->logged);
		self::assertSame(
			['schema' => 'EmployeeChangeRequest', 'id' => 'cr-1', 'old' => 'sanne', 'new' => 'pieter', 'dryRun' => false],
			$this->logged[0]['context']
		);
		self::assertSame('humaniq: manager stamp restamped', $this->logged[0]['message']);
	}//end testASelfStampOnAnOpenRecordIsRestamped()

	/**
	 * Decided, closed and draft records keep their stamp.
	 *
	 * @return void
	 */
	public function testClosedAndDecidedRecordsKeepTheirStamp(): void {
		$base = ['employeeId' => 'emp-sanne', 'managerUserId' => 'sanne'];
		$this->store->seed('EmployeeChangeRequest', 'cr-done', $base + ['status' => 'goedgekeurd']);
		$this->store->seed('EmployeeChangeRequest', 'cr-draft', $base + ['status' => 'concept']);
		$this->store->seed('SideActivity', 'sa-done', $base + ['status' => 'akkoord']);
		$this->store->seed('SideActivity', 'sa-ended', $base + ['status' => 'beeindigd']);
		$this->store->seed('EmployeeRelationsCase', 'erc-closed', $base + ['status' => 'afgesloten']);
		$this->store->seed('CommuteArrangement', 'ca-approved', $base + ['status' => 'approved']);
		$this->store->seed('CommuteArrangement', 'ca-draft', $base + ['status' => 'draft']);
		$this->store->seed('CommuteArrangement', 'ca-none', $base);
		$this->store->seed('Expense', 'ex-meals-draft', $base + ['status' => 'draft', 'category' => 'meals']);
		$this->store->seed('Expense', 'ex-approved', $base + ['status' => 'approved', 'category' => 'travel']);
		$this->store->seed('Expense', 'ex-rejected', $base + ['status' => 'rejected', 'category' => 'software']);
		$this->store->seed('Expense', 'ex-paid', $base + ['status' => 'reimbursed', 'category' => 'training']);

		$report = $this->repair->run(false);

		self::assertSame([], $this->store->state->saves);
		self::assertSame(['inspected' => 2, 'open' => 0, 'changed' => 0, 'failed' => 0], $report['EmployeeChangeRequest']);
		self::assertSame(['inspected' => 2, 'open' => 0, 'changed' => 0, 'failed' => 0], $report['SideActivity']);
		self::assertSame(['inspected' => 1, 'open' => 0, 'changed' => 0, 'failed' => 0], $report['EmployeeRelationsCase']);
		self::assertSame(['inspected' => 3, 'open' => 0, 'changed' => 0, 'failed' => 0], $report['CommuteArrangement']);
		self::assertSame(['inspected' => 4, 'open' => 0, 'changed' => 0, 'failed' => 0], $report['Expense']);
	}//end testClosedAndDecidedRecordsKeepTheirStamp()

	/**
	 * A record without a status takes its schema's default state.
	 *
	 * @return void
	 */
	public function testARecordWithoutAStatusTakesTheDefaultState(): void {
		$base = ['employeeId' => 'emp-sanne', 'managerUserId' => 'sanne'];
		$this->store->seed('EmployeeChangeRequest', 'cr-1', $base);
		$this->store->seed('SideActivity', 'sa-1', $base + ['status' => ' ']);
		$this->store->seed('EmployeeRelationsCase', 'erc-1', $base);
		$this->store->seed('Expense', 'ex-1', $base + ['category' => 'travel']);

		$report = $this->repair->run(false);

		self::assertSame('pieter', $this->stampOf('EmployeeChangeRequest', 'cr-1'));
		self::assertSame('pieter', $this->stampOf('SideActivity', 'sa-1'));
		self::assertSame('pieter', $this->stampOf('EmployeeRelationsCase', 'erc-1'));
		self::assertSame('sanne', $this->stampOf('Expense', 'ex-1'));
		self::assertSame(0, $report['Expense']['open']);
	}//end testARecordWithoutAStatusTakesTheDefaultState()

	/**
	 * A current stamp, an empty stamp and a record without an employee are
	 * not written.
	 *
	 * @return void
	 */
	public function testCurrentAndEmptyStampsAreLeftAlone(): void {
		$this->store->seed('EmployeeChangeRequest', 'cr-current', ['employeeId' => 'emp-tim', 'managerUserId' => ' sanne ', 'status' => 'ingediend']);
		$this->store->seed('EmployeeChangeRequest', 'cr-empty', ['employeeId' => 'emp-sanne', 'managerUserId' => '', 'status' => 'ingediend']);
		$this->store->seed('EmployeeChangeRequest', 'cr-null', ['employeeId' => 'emp-sanne', 'managerUserId' => null, 'status' => 'ingediend']);
		$this->store->seed('EmployeeChangeRequest', 'cr-nobody', ['employeeId' => '', 'managerUserId' => 'sanne', 'status' => 'ingediend']);

		$report = $this->repair->run(false);

		self::assertSame([], $this->store->state->saves);
		self::assertSame(['inspected' => 4, 'open' => 4, 'changed' => 0, 'failed' => 0], $report['EmployeeChangeRequest']);
		self::assertSame([], $this->logged);
	}//end testCurrentAndEmptyStampsAreLeftAlone()

	/**
	 * A stamp the resolution no longer confirms becomes null when nobody
	 * resolves, and the manager it gives when someone does.
	 *
	 * @return void
	 */
	public function testAStampThatNoLongerResolvesIsCleared(): void {
		$this->store->seed('EmployeeChangeRequest', 'cr-1', ['employeeId' => 'emp-sanne', 'managerUserId' => 'kees', 'status' => 'ingediend']);
		$this->store->state->objects['OrgUnit']['unit-directie']['administrationId'] = 'ADM-2';
		$this->store->seed('EmployeeChangeRequest', 'cr-2', ['employeeId' => 'emp-tim', 'managerUserId' => 'kees', 'status' => 'ingediend']);

		$this->repair->run(false);

		self::assertNull($this->stampOf('EmployeeChangeRequest', 'cr-1'));
		self::assertSame('sanne', $this->stampOf('EmployeeChangeRequest', 'cr-2'));
		self::assertNull($this->logged[0]['context']['new']);
	}//end testAStampThatNoLongerResolvesIsCleared()

	/**
	 * Each record is resolved for its own date: the case's opening date,
	 * the arrangement's start date, the claim's date, and the creation date
	 * for change requests, side activities and records without that field.
	 *
	 * @return void
	 */
	public function testEachRecordIsResolvedForItsOwnDate(): void {
		$may = ['employeeId' => 'emp-tim', 'managerUserId' => 'tim'];
		$this->store->seed('EmployeeRelationsCase', 'erc-may', $may + ['openedOn' => '2026-05-15', '@self' => ['created' => '2026-10-01T00:00:00+00:00']]);
		$this->store->seed('CommuteArrangement', 'ca-may', $may + ['status' => 'submitted', 'startDate' => '2026-05-15', '@self' => ['created' => '2026-10-01T00:00:00+00:00']]);
		$this->store->seed('Expense', 'ex-may', $may + ['status' => 'submitted', 'category' => 'travel', 'expenseDate' => '2026-05-15T12:00:00', '@self' => ['created' => '2026-10-01T00:00:00+00:00']]);
		$this->store->seed('EmployeeChangeRequest', 'cr-may', $may + ['status' => 'ingediend', '@self' => ['created' => '2026-05-15T08:00:00+00:00']]);
		$this->store->seed('SideActivity', 'sa-may', $may + ['status' => 'gemeld', 'startDate' => '2026-10-01', '@self' => ['created' => ' 2026-05-15T08:00:00+00:00']]);
		$this->store->seed('EmployeeRelationsCase', 'erc-fallback', $may + ['openedOn' => 'unknown', '@self' => ['created' => '2026-05-20T00:00:00+00:00']]);
		$this->store->seed('EmployeeRelationsCase', 'erc-today', $may + ['openedOn' => '', '@self' => ['created' => 'yesterday']]);
		$this->store->seed('EmployeeRelationsCase', 'erc-prefixed', $may + ['openedOn' => 'x2026-10-01', '@self' => ['created' => '2026-05-15T00:00:00+00:00']]);

		$this->repair->run(false);

		self::assertSame('kees', $this->stampOf('EmployeeRelationsCase', 'erc-may'));
		self::assertSame('kees', $this->stampOf('CommuteArrangement', 'ca-may'));
		self::assertSame('kees', $this->stampOf('Expense', 'ex-may'));
		self::assertSame('kees', $this->stampOf('EmployeeChangeRequest', 'cr-may'));
		self::assertSame('kees', $this->stampOf('SideActivity', 'sa-may'));
		self::assertSame('kees', $this->stampOf('EmployeeRelationsCase', 'erc-fallback'));
		self::assertSame('sanne', $this->stampOf('EmployeeRelationsCase', 'erc-today'));
		self::assertSame('kees', $this->stampOf('EmployeeRelationsCase', 'erc-prefixed'));
	}//end testEachRecordIsResolvedForItsOwnDate()

	/**
	 * A dry run counts and logs what would change, and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$this->seedOpenRecords('emp-sanne', 'sanne');

		$report = $this->repair->run(true);

		self::assertSame([], $this->store->state->saves);
		self::assertSame('sanne', $this->stampOf('Expense', 'ex-1'));
		self::assertSame(1, $report['Expense']['changed']);
		self::assertCount(5, $this->logged);
		self::assertTrue($this->logged[0]['context']['dryRun']);
		self::assertSame('humaniq: manager stamp would be restamped (dry run)', $this->logged[0]['message']);
	}//end testADryRunWritesNothing()

	/**
	 * A second run finds nothing left to change.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->seedOpenRecords('emp-sanne', 'sanne');
		$this->repair->run(false);
		$saves = count($this->store->state->saves);

		$report = $this->repair->run(false);

		self::assertSame(5, $saves);
		self::assertCount(5, $this->store->state->saves);
		foreach ($report as $counts) {
			self::assertSame(0, $counts['changed']);
		}
	}//end testASecondRunChangesNothing()

	/**
	 * Open claims of every category are restamped, each for its own date.
	 *
	 * @return void
	 */
	public function testOpenClaimsOfEveryCategoryAreRestamped(): void {
		$categories = ['travel', 'accommodation', 'meals', 'supplies', 'software', 'training', 'other'];
		foreach ($categories as $category) {
			$this->store->seed('Expense', 'ex-' . $category, ['employeeId' => 'emp-sanne', 'managerUserId' => 'sanne', 'status' => 'submitted', 'category' => $category]);
		}

		$this->store->seed('Expense', 'ex-uncategorised', ['employeeId' => 'emp-tim', 'managerUserId' => 'tim', 'status' => 'submitted', 'expenseDate' => '2026-05-15']);

		$report = $this->repair->run(false);

		foreach ($categories as $category) {
			self::assertSame('pieter', $this->stampOf('Expense', 'ex-' . $category), $category);
		}

		self::assertSame('kees', $this->stampOf('Expense', 'ex-uncategorised'));
		self::assertSame(['inspected' => 8, 'open' => 8, 'changed' => 8, 'failed' => 0], $report['Expense']);
	}//end testOpenClaimsOfEveryCategoryAreRestamped()

	/**
	 * A record that cannot be saved is logged and counted as failed, and
	 * the pass carries on with the next record and the next schema.
	 *
	 * @return void
	 */
	public function testAFailingRecordIsCountedAndThePassCarriesOn(): void {
		$errors = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$errors): void {
			$errors[] = ['message' => $message, 'context' => $context];
		});
		$repair = $this->repairWith(logger: $logger);
		$this->seedOpenRecords('emp-sanne', 'sanne');
		$this->store->seed('EmployeeChangeRequest', 'cr-2', ['employeeId' => 'emp-sanne', 'managerUserId' => 'sanne', 'status' => 'ingediend']);
		$this->store->failOn = 'cr-1';

		$report = $repair->run(false);

		self::assertSame('sanne', $this->stampOf('EmployeeChangeRequest', 'cr-1'));
		self::assertSame('pieter', $this->stampOf('EmployeeChangeRequest', 'cr-2'));
		self::assertSame(['inspected' => 2, 'open' => 2, 'changed' => 1, 'failed' => 1], $report['EmployeeChangeRequest']);
		foreach (['SideActivity' => 'sa-1', 'EmployeeRelationsCase' => 'erc-1', 'CommuteArrangement' => 'ca-1', 'Expense' => 'ex-1'] as $schema => $id) {
			self::assertSame('pieter', $this->stampOf($schema, $id), $schema);
		}

		self::assertSame(
			[['message' => 'humaniq: manager stamp could not be restamped', 'context' => ['schema' => 'EmployeeChangeRequest', 'id' => 'cr-1', 'error' => 'validation failed']]],
			$errors
		);
	}//end testAFailingRecordIsCountedAndThePassCarriesOn()

	/**
	 * Every page is read: open records past the first 500 rows, and an
	 * assignment past the first 500 assignments, all count.
	 *
	 * @return void
	 */
	public function testMoreThan500RowsAreAllRead(): void {
		for ($i = 0; $i < 600; $i++) {
			$this->store->seed('Expense', sprintf('ex-old-%03d', $i), ['employeeId' => 'emp-sanne', 'managerUserId' => 'sanne', 'status' => 'reimbursed']);
			$this->store->seed('OrgAssignment', sprintf('as-other-%03d', $i), ['employeeId' => 'emp-other-' . $i, 'orgUnitId' => 'unit-support']);
		}

		$sanne = $this->store->state->objects['OrgAssignment']['as-sanne'];
		unset($this->store->state->objects['OrgAssignment']['as-sanne']);
		$this->store->state->objects['OrgAssignment']['as-sanne'] = $sanne;
		$this->store->seed('Expense', 'ex-last', ['employeeId' => 'emp-sanne', 'managerUserId' => 'sanne', 'status' => 'submitted', 'category' => 'meals']);

		$report = $this->repair->run(false);

		self::assertSame('pieter', $this->stampOf('Expense', 'ex-last'));
		self::assertSame(['inspected' => 601, 'open' => 1, 'changed' => 1, 'failed' => 0], $report['Expense']);
	}//end testMoreThan500RowsAreAllRead()

	/**
	 * When a lookup read is cut off, a stamp is never cleared to null: the
	 * record is counted as failed and keeps its stamp. A manager that does
	 * resolve is still written.
	 *
	 * @return void
	 */
	public function testAStampIsNotClearedWhenALookupWasCutOff(): void {
		for ($i = 0; $i < 500; $i++) {
			$this->store->seed('OrgAssignment', sprintf('as-other-%03d', $i), ['employeeId' => 'emp-other-' . $i, 'orgUnitId' => 'unit-support']);
		}

		$tim = $this->store->state->objects['OrgAssignment']['as-tim'];
		$sanne = $this->store->state->objects['OrgAssignment']['as-sanne'];
		unset($this->store->state->objects['OrgAssignment']['as-tim'], $this->store->state->objects['OrgAssignment']['as-sanne']);
		$this->store->state->objects['OrgAssignment'] = ['as-sanne' => $sanne] + $this->store->state->objects['OrgAssignment'];
		$this->store->state->objects['OrgAssignment']['as-tim'] = $tim;
		$this->store->offsetIgnoredFor = 'OrgAssignment';
		$this->store->seed('EmployeeChangeRequest', 'cr-tim', ['employeeId' => 'emp-tim', 'managerUserId' => 'kees', 'status' => 'ingediend']);
		$this->store->seed('EmployeeChangeRequest', 'cr-sanne', ['employeeId' => 'emp-sanne', 'managerUserId' => 'sanne', 'status' => 'ingediend']);

		$report = $this->repair->run(false);

		self::assertSame('kees', $this->stampOf('EmployeeChangeRequest', 'cr-tim'));
		self::assertSame('pieter', $this->stampOf('EmployeeChangeRequest', 'cr-sanne'));
		self::assertSame(['inspected' => 2, 'open' => 2, 'changed' => 1, 'failed' => 1], $report['EmployeeChangeRequest']);
	}//end testAStampIsNotClearedWhenALookupWasCutOff()

	/**
	 * A schema read that stopped before the end counts as failed.
	 *
	 * @return void
	 */
	public function testACutOffSchemaReadCountsAsFailed(): void {
		for ($i = 0; $i < 500; $i++) {
			$this->store->seed('SideActivity', sprintf('sa-done-%03d', $i), ['employeeId' => 'emp-sanne', 'managerUserId' => 'sanne', 'status' => 'akkoord']);
		}

		$this->store->offsetIgnoredFor = 'SideActivity';

		$report = $this->repair->run(false);

		self::assertSame(HoursRegisterGateway::MAX_ROWS, $report['SideActivity']['inspected']);
		self::assertSame(1, $report['SideActivity']['failed']);
		self::assertSame(0, $report['Expense']['failed']);
	}//end testACutOffSchemaReadCountsAsFailed()

	/**
	 * A repair over the test's store with another logger.
	 *
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return ManagerStampRepair
	 */
	private function repairWith(LoggerInterface $logger): ManagerStampRepair {
		return new ManagerStampRepair(gateway: $this->gateway, marker: $this->marker, logger: $logger, orgResolution: new OrgResolutionService());
	}//end repairWith()

}//end class
