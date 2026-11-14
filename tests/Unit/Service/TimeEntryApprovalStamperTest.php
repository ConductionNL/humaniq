<?php

/**
 * TimeEntryApprovalStamper unit tests
 *
 * The copy of a timesheet's approval state onto its entries
 * (time-entry-approval-state D1-D3): the stamp per status, carrying an edge
 * down (approve with approvedAt, reopen clearing it), writing only drifted
 * entries, writing them under the internal-write marker, a failed save
 * reported rather than thrown, and the repair-wide restamp.
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
 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TimeEntryApprovalStamper;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the timesheet-to-entry approval stamp.
 */
class TimeEntryApprovalStamperTest extends TestCase {

	/**
	 * The in-memory register; records whether each save ran internally.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The internal-writer marker.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The subject.
	 *
	 * @var TimeEntryApprovalStamper
	 */
	private TimeEntryApprovalStamper $stamper;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->marker = new InternalWriteMarker();
		$marker = $this->marker;
		$this->store = new class($marker) extends FakeObjectStore {

			/**
			 * Whether the marker was set, per save, in order. An ArrayObject
			 * because the gateway clones the store per use.
			 *
			 * @var \ArrayObject<int, bool>
			 */
			public \ArrayObject $internal;

			/**
			 * UUIDs whose save throws (shared across clones, as above).
			 *
			 * @var \ArrayObject<int, string>
			 */
			public \ArrayObject $failOn;

			/**
			 * Constructor.
			 *
			 * @param InternalWriteMarker $marker The marker to observe.
			 */
			public function __construct(private readonly InternalWriteMarker $marker) {
				parent::__construct();
				$this->internal = new \ArrayObject();
				$this->failOn = new \ArrayObject();
			}//end __construct()

			/**
			 * Record the marker, then save (or fail).
			 *
			 * @param array<string, mixed>|object $object The payload.
			 * @param array<int, string>|null $extend Extend (ignored).
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return ObjectEntity The saved entity.
			 */
			public function saveObject(
				array|object $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ObjectEntity {
				$this->internal[] = $this->marker->isInternal();
				if (in_array($uuid, $this->failOn->getArrayCopy(), true) === true) {
					throw new \RuntimeException('register down');
				}

				return parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy);
			}//end saveObject()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->stamper = new TimeEntryApprovalStamper(gateway: $gateway, marker: $this->marker, logger: $this->logger);
	}//end setUp()

	/**
	 * Seed Visser's September timesheet with three entries.
	 *
	 * @param string $status The timesheet status.
	 * @param string|null $approvedAt The timesheet's approvedAt.
	 *
	 * @return array<string, mixed> The timesheet payload.
	 */
	private function seedVisser(string $status, ?string $approvedAt = null): array {
		$timesheet = ['employeeId' => 'employee-visser', 'period' => '2026-09', 'status' => $status, 'approvedAt' => $approvedAt];
		$this->store->seed('Timesheet', 'ts-visser', $timesheet);
		foreach (['e1' => 8.0, 'e2' => 4.5, 'e3' => 2.0] as $id => $hours) {
			$this->store->seed('TimeEntry', $id, ['timesheetId' => 'ts-visser', 'hours' => $hours, 'approvalState' => 'submitted', 'approvedAt' => null]);
		}

		$this->store->seed('TimeEntry', 'other', ['timesheetId' => 'ts-other', 'hours' => 1.0, 'approvalState' => 'draft']);

		return $timesheet;
	}//end seedVisser()

	/**
	 * The stamp follows the status; approvedAt only on approved; an unknown
	 * status reads as draft.
	 *
	 * @return void
	 */
	public function testTheStampFollowsTheStatus(): void {
		$this->assertSame(
			['approvalState' => 'approved', 'approvedAt' => '2026-10-01T09:00:00Z'],
			$this->stamper->stampFor(['status' => 'approved', 'approvedAt' => '2026-10-01T09:00:00Z'])
		);
		$this->assertSame(
			['approvalState' => 'rejected', 'approvedAt' => null],
			$this->stamper->stampFor(['status' => 'Rejected', 'approvedAt' => '2026-10-01T09:00:00Z']),
			'A rejection stamps approvedAt on the timesheet, never on the entry.'
		);
		$this->assertSame(['approvalState' => 'submitted', 'approvedAt' => null], $this->stamper->stampFor(['status' => 'submitted']));
		$this->assertSame(['approvalState' => 'draft', 'approvedAt' => null], $this->stamper->stampFor(['status' => 'archived']));
		$this->assertSame(['approvalState' => 'draft', 'approvedAt' => null], $this->stamper->stampFor([]));
		$this->assertSame(['approvalState' => 'approved', 'approvedAt' => null], $this->stamper->stampFor(['status' => 'approved', 'approvedAt' => ' ']));
	}//end testTheStampFollowsTheStatus()

	/**
	 * Approving a timesheet approves its three entries with the approval
	 * time, under the internal-write marker, and touches no other entry.
	 *
	 * @return void
	 */
	public function testApprovingATimesheetApprovesItsEntries(): void {
		$timesheet = $this->seedVisser('approved', '2026-10-01T09:00:00Z');

		$report = $this->stamper->carryDown('ts-visser', $timesheet);

		$this->assertSame(['inspected' => 3, 'changed' => 3, 'failed' => 0], $report);
		foreach (['e1', 'e2', 'e3'] as $id) {
			$entry = $this->store->state->objects['TimeEntry'][$id];
			$this->assertSame('approved', $entry['approvalState']);
			$this->assertSame('2026-10-01T09:00:00Z', $entry['approvedAt']);
			$this->assertSame('ts-visser', $entry['timesheetId'], 'The rest of the entry is kept.');
		}

		$this->assertSame('draft', $this->store->state->objects['TimeEntry']['other']['approvalState']);
		$this->assertSame([true, true, true], $this->store->internal->getArrayCopy(), 'Every entry save runs as an internal write.');
		$this->assertFalse($this->marker->isInternal(), 'The marker is released afterwards.');
		$this->assertSame(['TimeEntry'], array_values(array_unique(array_column($this->store->state->saves, 'schema'))), 'Only entries are written, never the timesheet.');
	}//end testApprovingATimesheetApprovesItsEntries()

	/**
	 * Reopening takes the approval back: draft and no approval time.
	 *
	 * @return void
	 */
	public function testReopeningTakesTheApprovalBack(): void {
		$this->seedVisser('approved', '2026-10-01T09:00:00Z');
		$this->stamper->carryDown('ts-visser', ['status' => 'approved', 'approvedAt' => '2026-10-01T09:00:00Z']);

		$report = $this->stamper->carryDown('ts-visser', ['status' => 'draft', 'approvedAt' => null]);

		$this->assertSame(3, $report['changed']);
		foreach (['e1', 'e2', 'e3'] as $id) {
			$this->assertSame('draft', $this->store->state->objects['TimeEntry'][$id]['approvalState']);
			$this->assertNull($this->store->state->objects['TimeEntry'][$id]['approvedAt']);
		}
	}//end testReopeningTakesTheApprovalBack()

	/**
	 * An entry already carrying the stamp is not written again.
	 *
	 * @return void
	 */
	public function testACurrentEntryIsNotWritten(): void {
		$this->seedVisser('submitted');

		$report = $this->stamper->carryDown('ts-visser', ['status' => 'submitted']);

		$this->assertSame(['inspected' => 3, 'changed' => 0, 'failed' => 0], $report);
		$this->assertSame([], $this->store->state->saves);
	}//end testACurrentEntryIsNotWritten()

	/**
	 * A failed save is counted and logged, the other entries still get the
	 * stamp, and nothing is thrown.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsReportedNotThrown(): void {
		$this->seedVisser('rejected');
		$this->store->failOn->append('e2');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('could not be stamped'), $this->callback(fn (array $context): bool => $context['id'] === 'e2'));

		$report = $this->stamper->carryDown('ts-visser', ['status' => 'rejected']);

		$this->assertSame(['inspected' => 3, 'changed' => 2, 'failed' => 1], $report);
		$this->assertSame('rejected', $this->store->state->objects['TimeEntry']['e1']['approvalState']);
		$this->assertSame('submitted', $this->store->state->objects['TimeEntry']['e2']['approvalState']);
		$this->assertFalse($this->marker->isInternal(), 'A throwing save still releases the marker.');
	}//end testAFailedSaveIsReportedNotThrown()

	/**
	 * An empty timesheet id reads nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyTimesheetIdIsANoOp(): void {
		$this->seedVisser('approved', '2026-10-01T09:00:00Z');

		$this->assertSame(['inspected' => 0, 'changed' => 0, 'failed' => 0], $this->stamper->carryDown(' ', ['status' => 'approved']));
		$this->assertSame([], $this->store->state->saves);
	}//end testAnEmptyTimesheetIdIsANoOp()

	/**
	 * The repair-wide restamp heals a drifted entry from its own timesheet
	 * and leaves an entry without a resolvable parent alone.
	 *
	 * @return void
	 */
	public function testRestampAllHealsADriftedEntry(): void {
		$this->seedVisser('approved', '2026-10-01T09:00:00Z');
		$this->store->seed('Timesheet', 'ts-other', ['status' => 'draft']);
		$this->store->seed('TimeEntry', 'orphan', ['timesheetId' => 'ts-gone', 'approvalState' => 'approved']);
		$this->store->seed('TimeEntry', 'loose', ['approvalState' => 'submitted']);

		$report = $this->stamper->restampAll();

		$this->assertSame(['inspected' => 4, 'changed' => 3, 'failed' => 0], $report, 'Three Visser entries drifted; "other" is current.');
		$this->assertSame('approved', $this->store->state->objects['TimeEntry']['e3']['approvalState']);
		$this->assertSame('2026-10-01T09:00:00Z', $this->store->state->objects['TimeEntry']['e3']['approvedAt']);
		$this->assertSame('approved', $this->store->state->objects['TimeEntry']['orphan']['approvalState'], 'No authority, no write.');
		$this->assertSame('submitted', $this->store->state->objects['TimeEntry']['loose']['approvalState']);
	}//end testRestampAllHealsADriftedEntry()

}//end class
