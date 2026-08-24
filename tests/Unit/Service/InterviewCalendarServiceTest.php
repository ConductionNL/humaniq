<?php

/**
 * Unit tests for InterviewCalendarService.
 *
 * Pins the interview-scheduling contract: the D1 duck-typed CalDavBackend
 * upsert, the D3 STORED-calendarEventUid idempotency (first sync creates
 * and stamps the UID, a no-op resync writes nothing, a rescheduled
 * Interview updates the SAME event and never duplicates), the D4
 * cancellation-removes/completion-keeps-history split plus orphan
 * reconciliation, the D5 AVG privacy boundary (SUMMARY carries only
 * candidateName/vacancyTitle, no email/phone/cvFile/motivation/
 * talentPoolOptIn/rejectedDate/retentionExpiryDate, no ATTENDEE/ORGANIZER
 * ever), the D6/REQ-INTV-006 skipped-no-calendar degradation paths, the
 * REQ-INTV-007 --from bound, and the PUT-semantic write guard (persisting
 * calendarEventUid carries every other Interview field forward unchanged).
 * Drives the service through fake ObjectService and CalDavBackend doubles
 * (fake collaborators, not fakes of the logic under test) since neither
 * OpenRegister's real ObjectService nor OCA\DAV's CalDavBackend is
 * available in this standalone suite.
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\InterviewCalendarService;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A duck-typed CalDavBackend double: one in-memory calendar, keyed by
 * object URI, recording every create/update/delete call for assertions.
 * Named distinctly from `LeaveCalendarServiceTest`'s own fake of the same
 * shape to avoid a class-redeclaration collision in this shared namespace.
 */
class InterviewFakeCalDavBackend {

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
class InterviewIncompleteCalDavBackend {

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
 * Tests for InterviewCalendarService.
 *
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md
 */
class InterviewCalendarServiceTest extends TestCase {

	private const PRINCIPAL = 'principals/users/recruiting';

	private const CALENDAR_URI = 'interviews';

	/**
	 * Build a fake ObjectService double: `findAll()`/`find()` read the
	 * seeded rows for the current schema, and `saveObject()` mutates the
	 * SAME in-memory row set it was seeded with, so a caller that reuses
	 * the returned fake across two `service()` builds observes the
	 * persisted `calendarEventUid` on the second run (the
	 * `OfferApplicationRepository::save()` field-merge idiom under test).
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
			 * @var array<int, array{object: array<string, mixed>, schema: string, uuid: string|null}>
			 */
			public array $saveObjectCalls = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				private array $rowsBySchema,
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

			/**
			 * @param string $id The object id.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): ?array {
				$targetSchema = $schema ?? $this->schema;
				foreach (($this->rowsBySchema[$targetSchema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * @param array<string, mixed> $object The payload (should carry every field forward — PUT-semantic guard).
			 * @param string $register Register slug (unused by the fake).
			 * @param string $schema Schema name.
			 * @param string|null $uuid The object id.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saveObjectCalls[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];

				$id = ($uuid ?? (string)($object['id'] ?? ''));
				$object['id'] = $id;

				$rows = ($this->rowsBySchema[$schema] ?? []);
				$found = false;
				foreach ($rows as $idx => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$rows[$idx] = $object;
						$found = true;
						break;
					}
				}

				if ($found === false) {
					$rows[] = $object;
				}

				$this->rowsBySchema[$schema] = $rows;

				return $object;
			}//end saveObject()

			/**
			 * Test convenience: mutate one row directly (e.g. simulate a
			 * reschedule or a status change between two sync() calls) while
			 * keeping this same fake instance (and any already-persisted
			 * calendarEventUid) alive.
			 *
			 * @param string $schema Schema name.
			 * @param string $id The object id.
			 * @param array<string, mixed> $overrides Fields to overwrite.
			 *
			 * @return void
			 */
			public function updateRow(string $schema, string $id, array $overrides): void {
				$rows = ($this->rowsBySchema[$schema] ?? []);
				foreach ($rows as $idx => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$rows[$idx] = array_merge($row, $overrides);
					}
				}

				$this->rowsBySchema[$schema] = $rows;

			}//end updateRow()

		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired InterviewCalendarService plus its fake
	 * ObjectService and CalDavBackend doubles.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param string $principal Configured `interview_calendar_principal` (empty = unconfigured).
	 * @param string $calendarUri Configured `interview_calendar_uri` (empty = unconfigured).
	 * @param bool $calendarExists Whether the fake calendar resolves.
	 * @param object|null $backendOverride A CalDavBackend double to use instead of InterviewFakeCalDavBackend.
	 * @param object|null $objectServiceOverride A fake ObjectService to reuse instead of building a fresh one (carries persisted writes forward across two sync() calls).
	 *
	 * @return array{0: InterviewCalendarService, 1: InterviewFakeCalDavBackend|object, 2: object}
	 */
	private function service(
		array $rowsBySchema = [],
		string $principal = self::PRINCIPAL,
		string $calendarUri = self::CALENDAR_URI,
		bool $calendarExists = true,
		?object $backendOverride = null,
		?object $objectServiceOverride = null,
	): array {
		$fakeObjects = ($objectServiceOverride ?? $this->fakeObjectService($rowsBySchema));
		$fakeBackend = ($backendOverride ?? new InterviewFakeCalDavBackend($principal, $calendarUri, 1, $calendarExists));

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
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getInterviewCalendarPrincipal')->willReturn($principal);
		$settings->method('getInterviewCalendarUri')->willReturn($calendarUri);

		$logger = $this->createMock(LoggerInterface::class);

		return [new InterviewCalendarService($container, $settings, $logger), $fakeBackend, $fakeObjects];
	}//end service()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function scheduledInterview(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'intv-1',
				'applicationId' => 'app-1',
				'scheduledStart' => '2026-08-03T10:00:00+00:00',
				'scheduledEnd' => '2026-08-03T11:00:00+00:00',
				'interviewers' => 'Els Bakker',
				'mode' => 'onsite',
				'location' => 'Kamer 4',
				'status' => 'scheduled',
				'calendarEventUid' => null,
			],
			$overrides
		);

	}//end scheduledInterview()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function application(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'app-1',
				'candidateName' => 'Sam de Vries',
				'email' => 'sam@example.com',
				'phone' => '0612345678',
				'cvFile' => 'cv.pdf',
				'motivation' => 'Ik wil heel graag deze rol vervullen vanwege mijn ervaring.',
				'talentPoolOptIn' => true,
				'rejectedDate' => null,
				'retentionExpiryDate' => null,
				'vacancyId' => 'vac-1',
				'status' => 'gesprek',
			],
			$overrides
		);

	}//end application()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function vacancy(array $overrides = []): array {
		return array_merge(['id' => 'vac-1', 'title' => 'Backend Developer'], $overrides);
	}//end vacancy()

	/**
	 * @param array<int, array<string, mixed>> $results The sync() result rows.
	 * @param string $sourceId The Interview id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findOutcome(array $results, string $sourceId): ?array {
		foreach ($results as $row) {
			if ($row['sourceId'] === $sourceId) {
				return $row;
			}
		}

		return null;
	}//end findOutcome()

	/**
	 * REQ-INTV-006 Scenario "Unconfigured instance skips cleanly".
	 *
	 * @return void
	 */
	public function testUnconfiguredInstanceSkipsCleanly(): void {
		[$service, $backend] = $this->service(
			['Interview' => [$this->scheduledInterview()]],
			principal: '',
			calendarUri: ''
		);

		$results = $service->sync();

		$this->assertCount(1, $results);
		$this->assertSame('skipped-no-calendar', $results[0]['status']);
		$this->assertSame([], $backend->created);

	}//end testUnconfiguredInstanceSkipsCleanly()

	/**
	 * REQ-INTV-006 Scenario "Misconfigured calendar URI".
	 *
	 * @return void
	 */
	public function testMisconfiguredCalendarUriSkipsCleanly(): void {
		[$service, $backend] = $this->service(
			['Interview' => [$this->scheduledInterview()]],
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
			['Interview' => [$this->scheduledInterview()]],
			backendOverride: new InterviewIncompleteCalDavBackend()
		);

		$results = $service->sync();

		$this->assertCount(1, $results);
		$this->assertSame('skipped-no-calendar', $results[0]['status']);

	}//end testAbsentOrIncompleteBackendSkipsCleanly()

	/**
	 * REQ-INTV-003 Scenario "First sync creates and stamps the UID". Also
	 * pins the PUT-semantic write guard: the persisted payload carries
	 * every other Interview field forward unchanged.
	 *
	 * @return void
	 */
	public function testFirstSyncCreatesAndStampsTheUid(): void {
		[$service, $backend, $objects] = $this->service(
			[
				'Interview' => [$this->scheduledInterview()],
				'Application' => [$this->application()],
				'Vacancy' => [$this->vacancy()],
			]
		);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'intv-1');
		$this->assertSame('created', $outcome['status']);

		$this->assertTrue($backend->hasObject('hrmq-interview-intv-1.ics'));
		$ics = $backend->calendarData('hrmq-interview-intv-1.ics');
		$this->assertStringContainsString('UID:hrmq-interview-intv-1', $ics);
		$this->assertStringContainsString('DTSTART:20260803T100000Z', $ics);
		$this->assertStringContainsString('DTEND:20260803T110000Z', $ics);
		$this->assertStringContainsString('SUMMARY:Sollicitatiegesprek — Sam de Vries (Backend Developer)', $ics);

		// The stored calendarEventUid was persisted back onto the Interview.
		$this->assertCount(1, $objects->saveObjectCalls);
		$saved = $objects->saveObjectCalls[0];
		$this->assertSame('Interview', $saved['schema']);
		$this->assertSame('intv-1', $saved['uuid']);
		$this->assertSame('hrmq-interview-intv-1', $saved['object']['calendarEventUid']);

		// PUT-semantic guard: every other field was carried forward, not nulled.
		$this->assertSame('app-1', $saved['object']['applicationId']);
		$this->assertSame('2026-08-03T10:00:00+00:00', $saved['object']['scheduledStart']);
		$this->assertSame('2026-08-03T11:00:00+00:00', $saved['object']['scheduledEnd']);
		$this->assertSame('Els Bakker', $saved['object']['interviewers']);
		$this->assertSame('onsite', $saved['object']['mode']);
		$this->assertSame('Kamer 4', $saved['object']['location']);
		$this->assertSame('scheduled', $saved['object']['status']);

	}//end testFirstSyncCreatesAndStampsTheUid()

	/**
	 * REQ-INTV-003 Scenario "Second consecutive sync with no changes is a
	 * no-op": zero calendar writes AND zero further saveObject() calls once
	 * the UID is already stored.
	 *
	 * @return void
	 */
	public function testSecondConsecutiveSyncIsANoOp(): void {
		$rows = [
			'Interview' => [$this->scheduledInterview()],
			'Application' => [$this->application()],
			'Vacancy' => [$this->vacancy()],
		];
		[$service, $backend, $objects] = $this->service($rows);
		$service->sync();

		$this->assertCount(1, $backend->created);
		$this->assertCount(1, $objects->saveObjectCalls);

		[$service2, ] = $this->service(objectServiceOverride: $objects, backendOverride: $backend);
		$results = $service2->sync();

		$outcome = $this->findOutcome($results, 'intv-1');
		$this->assertSame('unchanged', $outcome['status']);
		// Zero calendar writes on the no-op run.
		$this->assertCount(0, $backend->updated);
		$this->assertCount(1, $backend->created);
		// The UID was already stored -- no further persist attempt.
		$this->assertCount(1, $objects->saveObjectCalls);

	}//end testSecondConsecutiveSyncIsANoOp()

	/**
	 * REQ-INTV-003 Scenario "Rescheduling updates the same event, never
	 * duplicates".
	 *
	 * @return void
	 */
	public function testReschedulingUpdatesTheSameEventNeverDuplicates(): void {
		$rows = [
			'Interview' => [$this->scheduledInterview()],
			'Application' => [$this->application()],
			'Vacancy' => [$this->vacancy()],
		];
		[$service, $backend, $objects] = $this->service($rows);
		$service->sync();

		$objects->updateRow(
			'Interview',
			'intv-1',
			[
				'scheduledStart' => '2026-08-03T14:00:00+00:00',
				'scheduledEnd' => '2026-08-03T15:00:00+00:00',
			]
		);

		[$service2, ] = $this->service(objectServiceOverride: $objects, backendOverride: $backend);
		$results = $service2->sync();

		$outcome = $this->findOutcome($results, 'intv-1');
		$this->assertSame('updated', $outcome['status']);
		$this->assertCount(1, $backend->updated);

		$ics = $backend->calendarData('hrmq-interview-intv-1.ics');
		$this->assertStringContainsString('DTSTART:20260803T140000Z', $ics);
		$this->assertStringContainsString('DTEND:20260803T150000Z', $ics);
		$this->assertStringContainsString('UID:hrmq-interview-intv-1', $ics);

		// Still exactly one hrmq-interview-intv-1 object -- never duplicated.
		$this->assertCount(1, $backend->getCalendarObjects(1));

	}//end testReschedulingUpdatesTheSameEventNeverDuplicates()

	/**
	 * REQ-INTV-004 Scenario "Cancelling an Interview removes its event".
	 *
	 * @return void
	 */
	public function testCancellingRemovesTheEvent(): void {
		$rows = [
			'Interview' => [$this->scheduledInterview()],
			'Application' => [$this->application()],
			'Vacancy' => [$this->vacancy()],
		];
		[$service, $backend, $objects] = $this->service($rows);
		$service->sync();
		$this->assertTrue($backend->hasObject('hrmq-interview-intv-1.ics'));

		// A manually-created, non-humaniq event on the same calendar must
		// survive the cancellation-driven removal.
		$backend->seedObject('manual-event.ics', 'manual-uid', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

		$objects->updateRow('Interview', 'intv-1', ['status' => 'cancelled']);

		[$service2, ] = $this->service(objectServiceOverride: $objects, backendOverride: $backend);
		$results = $service2->sync();

		$outcome = $this->findOutcome($results, 'intv-1');
		$this->assertSame('removed', $outcome['status']);
		$this->assertFalse($backend->hasObject('hrmq-interview-intv-1.ics'));
		$this->assertTrue($backend->hasObject('manual-event.ics'));

	}//end testCancellingRemovesTheEvent()

	/**
	 * A cancelled Interview whose calendarEventUid was never stored (it
	 * never reached the calendar) is a no-op, not a failure.
	 *
	 * @return void
	 */
	public function testCancellingWithNoStoredUidIsANoOp(): void {
		[$service, $backend] = $this->service(['Interview' => [$this->scheduledInterview(['status' => 'cancelled'])]]);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'intv-1');
		$this->assertSame('unchanged', $outcome['status']);
		$this->assertSame([], $backend->deleted);

	}//end testCancellingWithNoStoredUidIsANoOp()

	/**
	 * REQ-INTV-004 Scenario "Completing an Interview leaves its event in
	 * place".
	 *
	 * @return void
	 */
	public function testCompletingLeavesTheEventInPlace(): void {
		$rows = [
			'Interview' => [$this->scheduledInterview()],
			'Application' => [$this->application()],
			'Vacancy' => [$this->vacancy()],
		];
		[$service, $backend, $objects] = $this->service($rows);
		$service->sync();

		$objects->updateRow('Interview', 'intv-1', ['status' => 'completed']);

		[$service2, ] = $this->service(objectServiceOverride: $objects, backendOverride: $backend);
		$results = $service2->sync();

		$this->assertTrue($backend->hasObject('hrmq-interview-intv-1.ics'));
		$outcome = $this->findOutcome($results, 'intv-1');
		$this->assertSame('unchanged', $outcome['status']);
		// Never touched -- no update call at all, not even a no-op diff write.
		$this->assertCount(0, $backend->updated);

	}//end testCompletingLeavesTheEventInPlace()

	/**
	 * REQ-INTV-004 Scenario "Deleted Interview is reconciled".
	 *
	 * @return void
	 */
	public function testDeletedInterviewIsReconciled(): void {
		[$service, $backend] = $this->service([]);
		// An event for an Interview that no longer exists in the register.
		$backend->seedObject('hrmq-interview-gone-1.ics', 'hrmq-interview-gone-1', "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:hrmq-interview-gone-1\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'gone-1');
		$this->assertSame('removed', $outcome['status']);
		$this->assertFalse($backend->hasObject('hrmq-interview-gone-1.ics'));

	}//end testDeletedInterviewIsReconciled()

	/**
	 * @return void
	 */
	public function testOrphanReconciliationNeverTouchesNonHumaniqEvents(): void {
		[$service, $backend] = $this->service([]);
		$backend->seedObject('someone-elses-event.ics', 'random-uid', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

		$service->sync();

		$this->assertTrue($backend->hasObject('someone-elses-event.ics'));
		$this->assertSame([], $backend->deleted);

	}//end testOrphanReconciliationNeverTouchesNonHumaniqEvents()

	/**
	 * REQ-INTV-005 Scenario "Summary carries name and role, nothing else
	 * from the Application".
	 *
	 * @return void
	 */
	public function testSummaryCarriesNameAndRoleNothingElseFromApplication(): void {
		[$service, $backend] = $this->service(
			[
				'Interview' => [$this->scheduledInterview()],
				'Application' => [$this->application()],
				'Vacancy' => [$this->vacancy()],
			]
		);
		$service->sync();

		$ics = $backend->calendarData('hrmq-interview-intv-1.ics');
		$this->assertStringContainsString('SUMMARY:Sollicitatiegesprek — Sam de Vries (Backend Developer)', $ics);
		$this->assertStringNotContainsString('sam@example.com', $ics);
		$this->assertStringNotContainsString('0612345678', $ics);
		$this->assertStringNotContainsString('cv.pdf', $ics);
		$this->assertStringNotContainsString('heel graag', $ics);
		$this->assertStringNotContainsString('ATTENDEE', $ics);
		$this->assertStringNotContainsString('ORGANIZER', $ics);

	}//end testSummaryCarriesNameAndRoleNothingElseFromApplication()

	/**
	 * REQ-INTV-005 Scenario "Interviewers never become scheduling
	 * attendees".
	 *
	 * @return void
	 */
	public function testInterviewersNeverBecomeAttendees(): void {
		[$service, $backend] = $this->service(
			[
				'Interview' => [$this->scheduledInterview(['interviewers' => 'Els Bakker, Jan Smit'])],
				'Application' => [$this->application()],
				'Vacancy' => [$this->vacancy()],
			]
		);
		$service->sync();

		$ics = $backend->calendarData('hrmq-interview-intv-1.ics');
		$this->assertStringContainsString('DESCRIPTION:Els Bakker\\, Jan Smit', $ics);
		$this->assertStringNotContainsString('ATTENDEE', $ics);
		$this->assertStringNotContainsString('ORGANIZER', $ics);

	}//end testInterviewersNeverBecomeAttendees()

	/**
	 * REQ-INTV-005 Scenario "Unresolvable Application falls back safely".
	 *
	 * @return void
	 */
	public function testUnresolvableApplicationFallsBackSafely(): void {
		[$service, $backend] = $this->service(['Interview' => [$this->scheduledInterview(['applicationId' => 'app-ghost'])]]);
		$service->sync();

		$ics = $backend->calendarData('hrmq-interview-intv-1.ics');
		$this->assertStringContainsString('SUMMARY:Sollicitatiegesprek — kandidaat', $ics);
		$this->assertStringNotContainsString('app-ghost', $ics);

	}//end testUnresolvableApplicationFallsBackSafely()

	/**
	 * REQ-INTV-007 Scenario "Bounded sync".
	 *
	 * @return void
	 */
	public function testBoundedSyncOnlyUpsertsInterviewsStartingOnOrAfterFrom(): void {
		$rows = [
			'Interview' => [
				$this->scheduledInterview(['id' => 'intv-march', 'scheduledStart' => '2026-03-20T09:00:00+00:00', 'scheduledEnd' => '2026-03-20T10:00:00+00:00']),
				$this->scheduledInterview(['id' => 'intv-august', 'scheduledStart' => '2026-08-03T10:00:00+00:00', 'scheduledEnd' => '2026-08-03T11:00:00+00:00']),
			],
			'Application' => [$this->application()],
			'Vacancy' => [$this->vacancy()],
		];

		[$service, $backend, $objects] = $this->service($rows);
		$service->sync();
		$this->assertTrue($backend->hasObject('hrmq-interview-intv-march.ics'));
		$this->assertTrue($backend->hasObject('hrmq-interview-intv-august.ics'));

		// A change to the march interview that a bounded re-sync must NOT pick up.
		$objects->updateRow('Interview', 'intv-march', ['scheduledStart' => '2026-04-15T09:00:00+00:00', 'scheduledEnd' => '2026-04-15T10:00:00+00:00']);

		[$service2, ] = $this->service(objectServiceOverride: $objects, backendOverride: $backend);
		$results = $service2->sync('2026-06-01');

		$this->assertNull($this->findOutcome($results, 'intv-march'));
		$this->assertNotNull($this->findOutcome($results, 'intv-august'));

		// The march event still exists, untouched, NOT updated to the new
		// (out-of-window) start time.
		$this->assertTrue($backend->hasObject('hrmq-interview-intv-march.ics'));
		$this->assertStringContainsString('DTSTART:20260320T090000Z', $backend->calendarData('hrmq-interview-intv-march.ics'));

	}//end testBoundedSyncOnlyUpsertsInterviewsStartingOnOrAfterFrom()

	/**
	 * REQ-INTV-007 Scenario "Failure surfaces in the exit code".
	 *
	 * @return void
	 */
	public function testFailureSurfacesInTheOutcomeStatus(): void {
		$backend = new InterviewFakeCalDavBackend(self::PRINCIPAL, self::CALENDAR_URI);
		$backend->failCreateForUri = ['hrmq-interview-intv-1.ics'];

		[$service, ] = $this->service(
			[
				'Interview' => [$this->scheduledInterview()],
				'Application' => [$this->application()],
				'Vacancy' => [$this->vacancy()],
			],
			backendOverride: $backend
		);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'intv-1');
		$this->assertSame('failed', $outcome['status']);
		$this->assertStringContainsString('simulated create failure', $outcome['message']);

	}//end testFailureSurfacesInTheOutcomeStatus()

	/**
	 * @return void
	 */
	public function testMissingScheduledDatesFailClosedInsteadOfCrashing(): void {
		$interview = $this->scheduledInterview(['scheduledStart' => '', 'scheduledEnd' => '']);
		[$service, ] = $this->service(['Interview' => [$interview]]);

		$results = $service->sync();

		$outcome = $this->findOutcome($results, 'intv-1');
		$this->assertSame('failed', $outcome['status']);

	}//end testMissingScheduledDatesFailClosedInsteadOfCrashing()

	/**
	 * @return void
	 */
	public function testSyncOneSkipsWhenUnconfigured(): void {
		[$service] = $this->service(
			['Interview' => [$this->scheduledInterview()]],
			principal: '',
			calendarUri: ''
		);

		$outcome = $service->syncOne('intv-1');

		$this->assertSame('skipped-no-calendar', $outcome['status']);

	}//end testSyncOneSkipsWhenUnconfigured()

	/**
	 * REQ-INTV-008 Scenario "Admin/HR user syncs one interview from the
	 * detail page" — the service side of the guarded manifest action.
	 *
	 * @return void
	 */
	public function testSyncOneUpsertsExactlyOneInterview(): void {
		[$service, $backend] = $this->service(
			[
				'Interview' => [$this->scheduledInterview()],
				'Application' => [$this->application()],
				'Vacancy' => [$this->vacancy()],
			]
		);

		$outcome = $service->syncOne('intv-1');

		$this->assertSame('created', $outcome['status']);
		$this->assertSame('intv-1', $outcome['sourceId']);
		$this->assertTrue($backend->hasObject('hrmq-interview-intv-1.ics'));

	}//end testSyncOneUpsertsExactlyOneInterview()

	/**
	 * @return void
	 */
	public function testSyncOneReturnsFailedForUnknownInterview(): void {
		[$service] = $this->service([]);

		$outcome = $service->syncOne('intv-ghost');

		$this->assertSame('failed', $outcome['status']);

	}//end testSyncOneReturnsFailedForUnknownInterview()

}//end class
