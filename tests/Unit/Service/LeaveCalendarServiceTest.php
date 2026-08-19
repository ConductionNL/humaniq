<?php

/**
 * Unit tests for LeaveCalendarService.
 *
 * Pins the leave-calendar-nc contract: the D1 duck-typed CalDavBackend
 * upsert (create-then-update-never-duplicate, scoped to the configured
 * calendar via getCalendarObjectByUID's third argument), the D2 no-op
 * diffing that keeps a repeated sync idempotent, the D4 rolling/closed sick
 * spans, the D5 AVG privacy boundary (no reason/leaveType/DESCRIPTION/
 * ATTENDEE ever reaches a rendered ICS), the D6/REQ-LC-006
 * skipped-no-calendar degradation paths, and the REQ-LC-007 --from bound.
 * Drives the service through fake ObjectService and CalDavBackend doubles
 * (fake collaborators, not fakes of the service logic under test) since
 * neither OpenRegister's real ObjectService nor OCA\DAV's CalDavBackend is
 * available in this standalone suite.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\LeaveCalendarService;
use OCA\Hrmq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A duck-typed CalDavBackend double: one in-memory calendar, keyed by
 * object URI, recording every create/update/delete call for assertions.
 */
class FakeCalDavBackend {

	/**
	 * @var array<string, array{uid: string, calendardata: string}>
	 */
	private array $objects = [];

	/**
	 * @var array<int, string>
	 */
	public array $created = [];

	/**
	 * @var array<int, string>
	 */
	public array $updated = [];

	/**
	 * @var array<int, string>
	 */
	public array $deleted = [];

	/**
	 * @var array<int, string>
	 */
	public array $failCreateForUri = [];

	/**
	 * @var array<int, string>
	 */
	public array $failUpdateForUri = [];

	/**
	 * @param string $principal The configured CalDAV principal.
	 * @param string $uri The configured calendar URI.
	 * @param int $calendarId The fake calendar's numeric id.
	 * @param bool $calendarExists Whether getCalendarByUri() resolves.
	 */
	public function __construct(
		private readonly string $principal,
		private readonly string $uri,
		private readonly int $calendarId = 1,
		private readonly bool $calendarExists = true,
	) {

	}//end __construct()

	/**
	 * Seed a pre-existing calendar object (for orphan/no-op-diff fixtures).
	 *
	 * @param string $objectUri The object URI.
	 * @param string $uid The VEVENT UID.
	 * @param string $calendarData The raw ICS text.
	 *
	 * @return void
	 */
	public function seedObject(string $objectUri, string $uid, string $calendarData): void {
		$this->objects[$objectUri] = [
			'uid' => $uid,
			'calendardata' => $calendarData,
		];

	}//end seedObject()

	/**
	 * @param string $objectUri The object URI.
	 *
	 * @return bool
	 */
	public function hasObject(string $objectUri): bool {
		return isset($this->objects[$objectUri]);
	}//end hasObject()

	/**
	 * @param string $objectUri The object URI.
	 *
	 * @return string|null
	 */
	public function calendarData(string $objectUri): ?string {
		return $this->objects[$objectUri]['calendardata'] ?? null;
	}//end calendarData()

	/**
	 * @param string $principal The principal to match.
	 * @param string $uri The calendar URI to match.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getCalendarByUri($principal, $uri) {
		if ($this->calendarExists === false) {
			return null;
		}

		if ($principal !== $this->principal || $uri !== $this->uri) {
			return null;
		}

		return [
			'id' => $this->calendarId,
			'uri' => $this->uri,
		];

	}//end getCalendarByUri()

	/**
	 * @param string $principalUri The principal (unused by the fake — single-calendar fixture).
	 * @param string $uid The VEVENT UID to find.
	 * @param string|null $calendarUri The calendar URI scope.
	 *
	 * @return string|null
	 */
	public function getCalendarObjectByUID($principalUri, $uid, $calendarUri = null) {
		if ($calendarUri !== null && $calendarUri !== $this->uri) {
			return null;
		}

		foreach ($this->objects as $objectUri => $object) {
			if ($object['uid'] === $uid) {
				return $this->uri . '/' . $objectUri;
			}
		}

		return null;
	}//end getCalendarObjectByUID()

	/**
	 * @param mixed $calendarId The calendar id.
	 * @param string $objectUri The object URI.
	 * @param string $calendarData The raw ICS text.
	 *
	 * @return string
	 */
	public function createCalendarObject($calendarId, $objectUri, $calendarData) {
		if (in_array($objectUri, $this->failCreateForUri, true) === true) {
			throw new \RuntimeException('simulated create failure');
		}

		$this->objects[$objectUri] = [
			'uid' => $this->extractUid($calendarData),
			'calendardata' => $calendarData,
		];
		$this->created[] = $objectUri;

		return '"etag-created"';
	}//end createCalendarObject()

	/**
	 * @param mixed $calendarId The calendar id.
	 * @param string $objectUri The object URI.
	 * @param string $calendarData The raw ICS text.
	 *
	 * @return string
	 */
	public function updateCalendarObject($calendarId, $objectUri, $calendarData) {
		if (in_array($objectUri, $this->failUpdateForUri, true) === true) {
			throw new \RuntimeException('simulated update failure');
		}

		$this->objects[$objectUri] = [
			'uid' => $this->extractUid($calendarData),
			'calendardata' => $calendarData,
		];
		$this->updated[] = $objectUri;

		return '"etag-updated"';
	}//end updateCalendarObject()

	/**
	 * @param mixed $calendarId The calendar id.
	 * @param string $objectUri The object URI.
	 *
	 * @return void
	 */
	public function deleteCalendarObject($calendarId, $objectUri) {
		unset($this->objects[$objectUri]);
		$this->deleted[] = $objectUri;

	}//end deleteCalendarObject()

	/**
	 * @param mixed $calendarId The calendar id.
	 * @param string $objectUri The object URI.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getCalendarObject($calendarId, $objectUri) {
		if (isset($this->objects[$objectUri]) === false) {
			return null;
		}

		return ['calendardata' => $this->objects[$objectUri]['calendardata']];
	}//end getCalendarObject()

	/**
	 * @param mixed $calendarId The calendar id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getCalendarObjects($calendarId) {
		$out = [];
		foreach (array_keys($this->objects) as $objectUri) {
			$out[] = ['uri' => $objectUri];
		}

		return $out;
	}//end getCalendarObjects()

	/**
	 * @param string $ics The raw ICS text.
	 *
	 * @return string
	 */
	private function extractUid(string $ics): string {
		foreach (preg_split('/\r\n|\n/', $ics) as $line) {
			if (str_starts_with($line, 'UID:') === true) {
				return substr($line, 4);
			}
		}

		return '';
	}//end extractUid()

}//end class

/**
 * A CalDavBackend double missing a required method — exercises the D1
 * `resolveBackend()` method_exists probe's degrade-to-skip path.
 */
class IncompleteCalDavBackend {

	/**
	 * @param string $principal The principal (unused).
	 * @param string $uri The calendar URI (unused).
	 *
	 * @return array<string, mixed>
	 */
	public function getCalendarByUri($principal, $uri) {
		return ['id' => 1, 'uri' => $uri];
	}//end getCalendarByUri()

}//end class

/**
 * Tests for LeaveCalendarService.
 *
 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md
 */
class LeaveCalendarServiceTest extends TestCase {

	private const PRINCIPAL = 'principals/users/hr';

	private const CALENDAR_URI = 'team';

	/**
	 * Build a fake ObjectService double: `findAll()` returns the seeded rows
	 * for the current schema (the PayrollGLPostServiceTest idiom, read-only
	 * here since LeaveCalendarService never writes back to the register).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rowsBySchema = []): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				private readonly array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired LeaveCalendarService plus its fake ObjectService
	 * and CalDavBackend doubles.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param string $principal Configured `leave_calendar_principal` (empty = unconfigured).
	 * @param string $calendarUri Configured `leave_calendar_uri` (empty = unconfigured).
	 * @param bool $calendarExists Whether the fake calendar resolves.
	 * @param object|null $backendOverride A CalDavBackend double to use instead of FakeCalDavBackend.
	 *
	 * @return array{0: LeaveCalendarService, 1: FakeCalDavBackend|object}
	 */
	private function service(
		array $rowsBySchema = [],
		string $principal = self::PRINCIPAL,
		string $calendarUri = self::CALENDAR_URI,
		bool $calendarExists = true,
		?object $backendOverride = null,
	): array {
		$fakeObjects = $this->fakeObjectService($rowsBySchema);
		$fakeBackend = ($backendOverride ?? new FakeCalDavBackend($principal, $calendarUri, 1, $calendarExists));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($fakeObjects, $fakeBackend) {
				if ($class === 'OCA\OpenRegister\Service\ObjectService') {
					return $fakeObjects;
				}

				if ($class === 'OCA\DAV\CalDAV\CalDavBackend') {
					return $fakeBackend;
				}

				throw new \RuntimeException('unexpected container->get(' . $class . ')');
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getLeaveCalendarPrincipal')->willReturn($principal);
		$settings->method('getLeaveCalendarUri')->willReturn($calendarUri);

		$logger = $this->createMock(LoggerInterface::class);

		return [new LeaveCalendarService($container, $settings, $logger), $fakeBackend];
	}//end service()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function approvedLeave(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'leave-1',
				'employeeId' => 'emp-1',
				'leaveType' => 'holiday',
				'startDate' => '2026-08-03',
				'endDate' => '2026-08-14',
				'status' => 'approved',
				'reason' => null,
			],
			$overrides
		);

	}//end approvedLeave()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-1',
				'firstName' => 'Sam',
				'lastName' => 'Jansen',
			],
			$overrides
		);

	}//end employee()

	/**
	 * @param array<int, array<string, mixed>> $results The sync() result rows.
	 * @param string $type `leave` or `sick`.
	 * @param string $sourceId The source id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findOutcome(array $results, string $type, string $sourceId): ?array {
		foreach ($results as $row) {
			if ($row['type'] === $type && $row['sourceId'] === $sourceId) {
				return $row;
			}
		}

		return null;
	}//end findOutcome()

	/**
	 * @return void
	 */
	public function testUnconfiguredInstanceSkipsCleanly(): void {
		[$service, $backend] = $this->service(
			['LeaveRequest' => [$this->approvedLeave()]],
			principal: '',
			calendarUri: ''
		);

		$results = $service->sync();

		$this->assertCount(1, $results);
		$this->assertSame('skipped-no-calendar', $results[0]['status']);
		$this->assertSame([], $backend->created);

	}//end testUnconfiguredInstanceSkipsCleanly()

	/**
	 * @return void
	 */
	public function testMisconfiguredCalendarUriSkipsCleanly(): void {
		[$service, $backend] = $this->service(
			['LeaveRequest' => [$this->approvedLeave()]],
			calendarExists: false
		);

		$results = $service->sync();

		$this->assertCount(1, $results);
		$this->assertSame('skipped-no-calendar', $results[0]['status']);
		$this->assertSame([], $backend->created);

	}//end testMisconfiguredCalendarUriSkipsCleanly()

	/**
	 * @return void
	 */
	public function testAbsentOrIncompleteBackendSkipsCleanly(): void {
		[$service] = $this->service(
			['LeaveRequest' => [$this->approvedLeave()]],
			backendOverride: new IncompleteCalDavBackend()
		);

		$results = $service->sync();

		$this->assertCount(1, $results);
		$this->assertSame('skipped-no-calendar', $results[0]['status']);

	}//end testAbsentOrIncompleteBackendSkipsCleanly()

	/**
	 * @return void
	 */
	public function testCalendarConfiguredLaterSupersedesThePriorSkip(): void {
		[$skippedService] = $this->service(
			['LeaveRequest' => [$this->approvedLeave()]],
			principal: '',
			calendarUri: ''
		);
		$skipped = $skippedService->sync();
		$this->assertSame('skipped-no-calendar', $skipped[0]['status']);

		[$service, $backend] = $this->service(['LeaveRequest' => [$this->approvedLeave()], 'Employee' => [$this->employee()]]);
		$results = $service->sync();

		$this->assertSame('created', $this->findOutcome($results, 'leave', 'leave-1')['status']);
		$this->assertCount(1, $backend->created);

	}//end testCalendarConfiguredLaterSupersedesThePriorSkip()

	/**
	 * @return void
	 */
	public function testApprovalLandsOnTheCalendar(): void {
		[$service, $backend] = $this->service(
			[
				'LeaveRequest' => [$this->approvedLeave()],
				'Employee' => [$this->employee()],
			]
		);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'leave', 'leave-1');
		$this->assertSame('created', $outcome['status']);

		$this->assertTrue($backend->hasObject('hrmq-leave-leave-1.ics'));
		$ics = $backend->calendarData('hrmq-leave-leave-1.ics');
		$this->assertStringContainsString('UID:hrmq-leave-leave-1', $ics);
		$this->assertStringContainsString('DTSTART;VALUE=DATE:20260803', $ics);
		$this->assertStringContainsString('DTEND;VALUE=DATE:20260815', $ics);
		$this->assertStringContainsString('SUMMARY:Verlof — Sam Jansen', $ics);

	}//end testApprovalLandsOnTheCalendar()

	/**
	 * @return void
	 */
	public function testResyncAfterADateChangeUpdatesNeverDuplicates(): void {
		$rows = [
			'LeaveRequest' => [$this->approvedLeave()],
			'Employee' => [$this->employee()],
		];
		[$service, $backend] = $this->service($rows);
		$service->sync();

		$this->assertCount(1, $backend->created);

		$rows['LeaveRequest'][0]['endDate'] = '2026-08-21';
		[$service2, ] = $this->service($rows, backendOverride: $backend);
		$results = $service2->sync();

		$outcome = $this->findOutcome($results, 'leave', 'leave-1');
		$this->assertSame('updated', $outcome['status']);
		$this->assertCount(1, $backend->updated);

		$ics = $backend->calendarData('hrmq-leave-leave-1.ics');
		$this->assertStringContainsString('DTEND;VALUE=DATE:20260822', $ics);

		// Still exactly one hrmq-leave-leave-1 object -- never duplicated.
		$objects = $backend->getCalendarObjects(1);
		$this->assertCount(1, $objects);

	}//end testResyncAfterADateChangeUpdatesNeverDuplicates()

	/**
	 * @return void
	 */
	public function testRunningTheSyncTwiceInARowChangesNothingOnTheSecondRun(): void {
		$rows = [
			'LeaveRequest' => [$this->approvedLeave()],
			'Employee' => [$this->employee()],
		];
		[$service, $backend] = $this->service($rows);
		$service->sync();

		$this->assertCount(1, $backend->created);
		$this->assertCount(0, $backend->updated);

		[$service2, ] = $this->service($rows, backendOverride: $backend);
		$results = $service2->sync();

		$outcome = $this->findOutcome($results, 'leave', 'leave-1');
		$this->assertSame('unchanged', $outcome['status']);
		// The second sync never called update -- no etag churn on a no-op.
		$this->assertCount(0, $backend->updated);

	}//end testRunningTheSyncTwiceInARowChangesNothingOnTheSecondRun()

	/**
	 * @return void
	 */
	public function testRejectionClearsTheCalendar(): void {
		$rows = [
			'LeaveRequest' => [$this->approvedLeave()],
			'Employee' => [$this->employee()],
		];
		[$service, $backend] = $this->service($rows);
		$service->sync();
		$this->assertTrue($backend->hasObject('hrmq-leave-leave-1.ics'));

		// Seed a manually-created, non-hrmq event on the same calendar -- it
		// must survive the reject-driven removal (REQ-LC-005 scenario).
		$backend->seedObject('manual-event.ics', 'manual-uid', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

		$rows['LeaveRequest'][0]['status'] = 'rejected';
		[$service2, ] = $this->service($rows, backendOverride: $backend);
		$results = $service2->sync();

		$outcome = $this->findOutcome($results, 'leave', 'leave-1');
		$this->assertSame('removed', $outcome['status']);
		$this->assertFalse($backend->hasObject('hrmq-leave-leave-1.ics'));
		$this->assertTrue($backend->hasObject('manual-event.ics'));

	}//end testRejectionClearsTheCalendar()

	/**
	 * @return void
	 */
	public function testRemovalOfANonApprovedLeaveWithNoExistingEventIsANoOp(): void {
		[$service, $backend] = $this->service(['LeaveRequest' => [$this->approvedLeave(['status' => 'draft'])]]);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'leave', 'leave-1');
		$this->assertSame('unchanged', $outcome['status']);
		$this->assertSame([], $backend->deleted);

	}//end testRemovalOfANonApprovedLeaveWithNoExistingEventIsANoOp()

	/**
	 * @return void
	 */
	public function testDeletedSourceIsReconciled(): void {
		[$service, $backend] = $this->service([]);
		// An event for a LeaveRequest that no longer exists in the register.
		$backend->seedObject('hrmq-leave-gone-1.ics', 'hrmq-leave-gone-1', "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:hrmq-leave-gone-1\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'leave', 'gone-1');
		$this->assertSame('removed', $outcome['status']);
		$this->assertFalse($backend->hasObject('hrmq-leave-gone-1.ics'));

	}//end testDeletedSourceIsReconciled()

	/**
	 * @return void
	 */
	public function testOrphanReconciliationNeverTouchesNonHrmqEvents(): void {
		[$service, $backend] = $this->service([]);
		$backend->seedObject('someone-elses-event.ics', 'random-uid', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

		$service->sync();

		$this->assertTrue($backend->hasObject('someone-elses-event.ics'));
		$this->assertSame([], $backend->deleted);

	}//end testOrphanReconciliationNeverTouchesNonHrmqEvents()

	/**
	 * @return void
	 */
	public function testOpenSickCaseRollsForward(): void {
		$sick = [
			'id' => 'sick-1',
			'employeeId' => 'emp-1',
			'firstSickDay' => '2026-05-25',
			'recoveredDate' => null,
			'status' => 'gemeld',
		];
		[$service, $backend] = $this->service(['SickLeaveCase' => [$sick], 'Employee' => [$this->employee()]]);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'sick', 'sick-1');
		$this->assertSame('created', $outcome['status']);

		$ics = $backend->calendarData('hrmq-sick-sick-1.ics');
		$this->assertStringContainsString('DTSTART;VALUE=DATE:20260525', $ics);

		$expectedDtend = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->format('Ymd');
		$this->assertStringContainsString('DTEND;VALUE=DATE:' . $expectedDtend, $ics);
		$this->assertStringContainsString('SUMMARY:Afwezig — Sam Jansen', $ics);

	}//end testOpenSickCaseRollsForward()

	/**
	 * @return void
	 */
	public function testRecoveredCaseKeepsItsFinalFootprint(): void {
		$sick = [
			'id' => 'sick-2',
			'employeeId' => 'emp-1',
			'firstSickDay' => '2026-05-04',
			'recoveredDate' => '2026-05-08',
			'status' => 'hersteld',
		];
		$rows = ['SickLeaveCase' => [$sick], 'Employee' => [$this->employee()]];
		[$service, $backend] = $this->service($rows);
		$service->sync();

		$ics = $backend->calendarData('hrmq-sick-sick-2.ics');
		$this->assertStringContainsString('DTEND;VALUE=DATE:20260509', $ics);

		// A second sync (the case is still `hersteld` -- completed history) must
		// keep the event, not remove it (design.md D4).
		[$service2, ] = $this->service($rows, backendOverride: $backend);
		$results = $service2->sync();

		$this->assertTrue($backend->hasObject('hrmq-sick-sick-2.ics'));
		$outcome = $this->findOutcome($results, 'sick', 'sick-2');
		$this->assertSame('unchanged', $outcome['status']);

	}//end testRecoveredCaseKeepsItsFinalFootprint()

	/**
	 * @return void
	 */
	public function testHersteldWithoutRecoveredDateIsSkippedNotGuessed(): void {
		$sick = [
			'id' => 'sick-3',
			'employeeId' => 'emp-1',
			'firstSickDay' => '2026-05-04',
			'recoveredDate' => null,
			'status' => 'hersteld',
		];
		[$service, $backend] = $this->service(['SickLeaveCase' => [$sick]]);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'sick', 'sick-3');
		$this->assertSame('skipped', $outcome['status']);
		$this->assertFalse($backend->hasObject('hrmq-sick-sick-3.ics'));

	}//end testHersteldWithoutRecoveredDateIsSkippedNotGuessed()

	/**
	 * @return void
	 */
	public function testSickEventIsReasonFree(): void {
		$sick = [
			'id' => 'sick-4',
			'employeeId' => 'emp-1',
			'firstSickDay' => '2026-05-25',
			'recoveredDate' => null,
			'status' => 'gemeld',
		];
		[$service, $backend] = $this->service(['SickLeaveCase' => [$sick], 'Employee' => [$this->employee()]]);
		$service->sync();

		$ics = $backend->calendarData('hrmq-sick-sick-4.ics');
		$this->assertStringContainsString('SUMMARY:Afwezig — Sam Jansen', $ics);
		$this->assertStringNotContainsString('DESCRIPTION', $ics);
		$this->assertStringNotContainsString('ATTENDEE', $ics);
		$this->assertStringNotContainsString('ORGANIZER', $ics);

	}//end testSickEventIsReasonFree()

	/**
	 * @return void
	 */
	public function testLeaveTypeAndReasonNeverReachTheCalendar(): void {
		$leave = $this->approvedLeave(
			[
				'leaveType' => 'care',
				'reason' => 'Zorg voor ziek kind, langdurig, met medische complicaties',
			]
		);
		[$service, $backend] = $this->service(['LeaveRequest' => [$leave], 'Employee' => [$this->employee()]]);
		$service->sync();

		$ics = $backend->calendarData('hrmq-leave-leave-1.ics');
		$this->assertStringContainsString('SUMMARY:Verlof — Sam Jansen', $ics);
		$this->assertStringNotContainsString('care', $ics);
		$this->assertStringNotContainsString('Zorg', $ics);
		$this->assertStringNotContainsString('DESCRIPTION', $ics);
		$this->assertStringNotContainsString('ATTENDEE', $ics);
		$this->assertStringNotContainsString('ORGANIZER', $ics);

	}//end testLeaveTypeAndReasonNeverReachTheCalendar()

	/**
	 * @return void
	 */
	public function testUnresolvableEmployeeFallsBackToTheRoleWordNotARawId(): void {
		[$service, $backend] = $this->service(['LeaveRequest' => [$this->approvedLeave(['employeeId' => 'emp-ghost'])]]);
		$service->sync();

		$ics = $backend->calendarData('hrmq-leave-leave-1.ics');
		$this->assertStringContainsString('SUMMARY:Verlof — medewerker', $ics);
		$this->assertStringNotContainsString('emp-ghost', $ics);

	}//end testUnresolvableEmployeeFallsBackToTheRoleWordNotARawId()

	/**
	 * @return void
	 */
	public function testSummaryEscapesRfc5545SpecialCharacters(): void {
		$employee = $this->employee(['firstName' => 'A;B,C\\D', 'lastName' => 'X']);
		[$service, $backend] = $this->service(['LeaveRequest' => [$this->approvedLeave()], 'Employee' => [$employee]]);
		$service->sync();

		$ics = $backend->calendarData('hrmq-leave-leave-1.ics');
		$this->assertStringContainsString('SUMMARY:Verlof — A\\;B\\,C\\\\D X', $ics);

	}//end testSummaryEscapesRfc5545SpecialCharacters()

	/**
	 * @return void
	 */
	public function testBoundedSyncOnlyUpsertsSourcesEndingOnOrAfterFrom(): void {
		$rows = [
			'LeaveRequest' => [
				$this->approvedLeave(['id' => 'leave-march', 'startDate' => '2026-03-20', 'endDate' => '2026-03-31']),
				$this->approvedLeave(['id' => 'leave-august', 'startDate' => '2026-08-03', 'endDate' => '2026-08-14']),
			],
			'Employee' => [$this->employee()],
		];

		// First, an unbounded sync creates both events.
		[$service, $backend] = $this->service($rows);
		$service->sync();
		$this->assertTrue($backend->hasObject('hrmq-leave-leave-march.ics'));
		$this->assertTrue($backend->hasObject('hrmq-leave-leave-august.ics'));

		// A change to the march leave that a bounded re-sync must NOT pick up.
		$rows['LeaveRequest'][0]['endDate'] = '2026-04-15';
		[$service2, ] = $this->service($rows, backendOverride: $backend);
		$results = $service2->sync('2026-06-01');

		$this->assertNull($this->findOutcome($results, 'leave', 'leave-march'));
		$this->assertNotNull($this->findOutcome($results, 'leave', 'leave-august'));

		// The march event still exists, untouched by reconciliation, and was
		// NOT updated to the new (out-of-window) endDate.
		$this->assertTrue($backend->hasObject('hrmq-leave-leave-march.ics'));
		$this->assertStringContainsString('DTEND;VALUE=DATE:20260401', $backend->calendarData('hrmq-leave-leave-march.ics'));

	}//end testBoundedSyncOnlyUpsertsSourcesEndingOnOrAfterFrom()

	/**
	 * @return void
	 */
	public function testFailureSurfacesInTheOutcomeStatus(): void {
		$backend = new FakeCalDavBackend(self::PRINCIPAL, self::CALENDAR_URI);
		$backend->failCreateForUri = ['hrmq-leave-leave-1.ics'];

		[$service, ] = $this->service(
			['LeaveRequest' => [$this->approvedLeave()], 'Employee' => [$this->employee()]],
			backendOverride: $backend
		);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'leave', 'leave-1');
		$this->assertSame('failed', $outcome['status']);
		$this->assertStringContainsString('simulated create failure', $outcome['message']);

	}//end testFailureSurfacesInTheOutcomeStatus()

	/**
	 * @return void
	 */
	public function testMissingDatesFailClosedInsteadOfCrashing(): void {
		$leave = $this->approvedLeave(['startDate' => '', 'endDate' => '']);
		[$service, ] = $this->service(['LeaveRequest' => [$leave]]);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'leave', 'leave-1');
		$this->assertSame('failed', $outcome['status']);

	}//end testMissingDatesFailClosedInsteadOfCrashing()

}//end class
