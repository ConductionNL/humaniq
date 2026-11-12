<?php

/**
 * Golden test for the interview calendar sync.
 *
 * Runs InterviewCalendarService::sync() and syncOne() over fixed Interview,
 * Application and Vacancy rows against a recording CalDAV backend and
 * ObjectService. The cases take every exit of the per-interview sync:
 * scheduled interviews created, unchanged, rescheduled and self-healed,
 * missing or unparseable dates, the `from` bound, cancelled with and
 * without a stored or existing event, completed and unknown statuses,
 * every backend call that throws, a failed UID write, orphan cleanup and
 * a single-interview sync. Every outcome row, every backend call and
 * every saved object is compared with
 * tests/fixtures/golden/interview-sync.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CalendarSettings;
use OCA\Humaniq\Service\InterviewCalendarService;
use OCA\Humaniq\Service\InterviewRepository;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden cases for the interview calendar sync.
 *
 * @covers \OCA\Humaniq\Service\InterviewCalendarService
 * @covers \OCA\Humaniq\Service\InterviewSyncEngine
 * @uses   \OCA\Humaniq\Service\InterviewSyncRun
 * @uses   \OCA\Humaniq\Service\InterviewCalendarTarget
 * @uses   \OCA\Humaniq\Service\CalendarTarget
 * @uses   \OCA\Humaniq\Service\InterviewIcsRenderer
 * @uses   \OCA\Humaniq\Service\InterviewOrphanReconciler
 * @uses   \OCA\Humaniq\Service\InterviewRepository
 * @uses   \OCA\Humaniq\Service\PreparedInterviewEvent
 * @uses   \OCA\Humaniq\Support\ResolvesObjectService
 * @uses   \OCA\Humaniq\Support\NormalisesRows
 * @uses   \OCA\Humaniq\Support\ReadsIcsValues
 * @uses \OCA\Humaniq\Service\CalDavBackendResolver
 * @uses \OCA\Humaniq\Service\IcsText
 *
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */
class InterviewSyncGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/interview-sync.json';

	/**
	 * Every backend call and saved object of the current case, in order.
	 *
	 * @var list<mixed>
	 */
	public array $calls = [];

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = array_keys(self::scenarios());
		return array_combine($names, array_map(static fn (string $n): array => [$n], $names));

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $case The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $case): void {
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = json_encode($this->free(self::scenarios()[$case]), $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode((string)$actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * Run one scenario: each call's outcome, then every recorded call.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function free(array $s): array {
		$this->calls = [];
		$store = $this->store($s['rows'], ($s['failSave'] ?? false));
		$backend = $this->backend(($s['objects'] ?? []), ($s['fail'] ?? []));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class): object => ($class === 'OCA\OpenRegister\Service\ObjectService') ? $store : $backend
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$calendars = new CalendarSettings(AppConfigValues::answer($this->createMock(IAppConfig::class), ['interview_calendar_principal' => 'principals/users/hr', 'interview_calendar_uri' => 'sollicitaties']));

		$service = new InterviewCalendarService($container, $calendars, new InterviewRepository($container, $settings, new NullLogger()), new NullLogger());
		$results = [];
		foreach ($s['calls'] as $call) {
			$results[] = ($call[0] === 'one') ? $service->syncOne($call[1]) : $service->sync($call[1] ?? null);
		}

		$out = ['results' => $results, 'calls' => $this->calls];
		$json = (string)json_encode($out, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
		return json_decode((string)preg_replace('/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:(now)', $json), true);

	}//end free()

	/**
	 * A recording ObjectService over seeded rows; a missing object throws
	 * DoesNotExistException, as OpenRegister does.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Rows by schema.
	 * @param bool $failSave Whether every save throws.
	 *
	 * @return object
	 */
	private function store(array $rows, bool $failSave): object {
		$test = $this;
		return new class($rows, $failSave, $test) {
			/**
			 * The schema of the next read.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<array<string, mixed>>> $rows Rows by schema.
			 * @param bool $failSave Whether every save throws.
			 * @param InterviewSyncGoldenTest $test The test recording calls.
			 */
			public function __construct(private readonly array $rows, private readonly bool $failSave, private readonly InterviewSyncGoldenTest $test) {

			}//end __construct()

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options The options.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return ($this->rows[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param string $id The id.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): array {
				foreach (($this->rows[(string)$schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				throw new DoesNotExistException('Object not found');
			}//end find()

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string|null $uuid The id.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->calls[] = ['saveObject', $register, $schema, $uuid, $_rbac, $_multitenancy, $object];
				if ($this->failSave === true) {
					throw new RuntimeException('save refused');
				}

				return $object;
			}//end saveObject()
		};

	}//end store()

	/**
	 * A recording CalDAV backend over seeded objects. `$fail` names the
	 * methods that throw, optionally only for one argument.
	 *
	 * @param array<string, string> $objects ICS text by object URI.
	 * @param array<string, string> $fail Method name to the argument it throws for, or '*'.
	 *
	 * @return object
	 */
	private function backend(array $objects, array $fail): object {
		$test = $this;
		return new class($objects, $fail, $test) {
			/**
			 * @param array<string, string> $objects ICS text by object URI.
			 * @param array<string, string> $fail Method name to the argument it throws for, or '*'.
			 * @param InterviewSyncGoldenTest $test The test recording calls.
			 */
			public function __construct(private array $objects, private readonly array $fail, private readonly InterviewSyncGoldenTest $test) {

			}//end __construct()

			/**
			 * Record a call and throw when it is set to fail.
			 *
			 * @param string $method The method.
			 * @param array<int, mixed> $args The arguments.
			 *
			 * @return void
			 */
			private function record(string $method, array $args): void {
				$this->test->calls[] = array_merge([$method], $args);
				$when = ($this->fail[$method] ?? null);
				if ($when === '*' || ($when !== null && in_array($when, $args, true) === true)) {
					throw new RuntimeException($method . ' refused');
				}
			}//end record()

			/**
			 * @param mixed $principal The principal.
			 * @param mixed $uri The calendar URI.
			 *
			 * @return array<string, mixed>
			 */
			public function getCalendarByUri($principal, $uri) {
				return ['id' => 7, 'uri' => $uri];
			}//end getCalendarByUri()

			/**
			 * @param mixed $principalUri The principal.
			 * @param mixed $uid The UID.
			 * @param mixed $calendarUri The calendar URI.
			 *
			 * @return string|null
			 */
			public function getCalendarObjectByUID($principalUri, $uid, $calendarUri = null) {
				$this->record('getCalendarObjectByUID', [$principalUri, $uid, $calendarUri]);
				foreach ($this->objects as $objectUri => $ics) {
					if (str_contains($ics, 'UID:' . $uid . "\r\n") === true) {
						return $calendarUri . '/' . $objectUri;
					}
				}

				return null;
			}//end getCalendarObjectByUID()

			/**
			 * @param mixed $calendarId The calendar id.
			 * @param mixed $objectUri The object URI.
			 * @param mixed $calendarData The ICS.
			 *
			 * @return string
			 */
			public function createCalendarObject($calendarId, $objectUri, $calendarData) {
				$this->record('createCalendarObject', [$calendarId, $objectUri, $calendarData]);
				$this->objects[$objectUri] = $calendarData;
				return '"etag"';
			}//end createCalendarObject()

			/**
			 * @param mixed $calendarId The calendar id.
			 * @param mixed $objectUri The object URI.
			 * @param mixed $calendarData The ICS.
			 *
			 * @return string
			 */
			public function updateCalendarObject($calendarId, $objectUri, $calendarData) {
				$this->record('updateCalendarObject', [$calendarId, $objectUri, $calendarData]);
				$this->objects[$objectUri] = $calendarData;
				return '"etag"';
			}//end updateCalendarObject()

			/**
			 * @param mixed $calendarId The calendar id.
			 * @param mixed $objectUri The object URI.
			 *
			 * @return void
			 */
			public function deleteCalendarObject($calendarId, $objectUri) {
				$this->record('deleteCalendarObject', [$calendarId, $objectUri]);
				unset($this->objects[$objectUri]);
			}//end deleteCalendarObject()

			/**
			 * @param mixed $calendarId The calendar id.
			 * @param mixed $objectUri The object URI.
			 *
			 * @return array<string, mixed>|null
			 */
			public function getCalendarObject($calendarId, $objectUri) {
				$this->record('getCalendarObject', [$calendarId, $objectUri]);
				return isset($this->objects[$objectUri]) === true ? ['calendardata' => $this->objects[$objectUri]] : null;
			}//end getCalendarObject()

			/**
			 * @param mixed $calendarId The calendar id.
			 *
			 * @return list<array<string, string>>
			 */
			public function getCalendarObjects($calendarId) {
				$this->record('getCalendarObjects', [$calendarId]);
				return array_map(static fn (string $uri): array => ['uri' => $uri], array_keys($this->objects));
			}//end getCalendarObjects()
		};

	}//end backend()

	/**
	 * An Interview row.
	 *
	 * @param string $id The id.
	 * @param array<string, mixed> $extra Other fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function interview(string $id, array $extra = []): array {
		return ($extra + ['id' => $id, 'applicationId' => 'app-1', 'scheduledStart' => '2026-08-03T10:00:00+00:00', 'scheduledEnd' => '2026-08-03T11:00:00+00:00', 'interviewers' => 'Els Bakker', 'location' => 'Kamer 4', 'status' => 'scheduled', 'calendarEventUid' => null]);

	}//end interview()

	/**
	 * An existing calendar object's ICS.
	 *
	 * @param string $uid The UID.
	 * @param string $start DTSTART.
	 * @param string $summary SUMMARY.
	 *
	 * @return string
	 */
	private static function ics(string $uid, string $start = '20260803T100000Z', string $summary = 'Sollicitatiegesprek: Sam de Vries (Backend Developer)'): string {
		return "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTART:" . $start . "\r\nDTEND:20260803T110000Z\r\nSUMMARY:" . $summary . "\r\nLOCATION:Kamer 4\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

	}//end ics()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$people = ['job-application' => [['id' => 'app-1', 'candidateName' => 'Sam de Vries', 'vacancyId' => 'vac-1', 'email' => 'sam@example.com'], ['@self' => ['id' => 'app-2'], 'candidateName' => ' ', 'vacancyId' => 'vac-9']], 'Vacancy' => [['id' => 'vac-1', 'title' => 'Backend Developer']]];
		$stored = self::interview('i1', ['calendarEventUid' => 'hrmq-interview-i1']);

		return [
			'create and stamp' => ['rows' => ($people + ['Interview' => [self::interview('i1'), self::interview('i2', ['applicationId' => 'gone', 'location' => '', '@self' => ['id' => 'i2']]), self::interview('i3', ['applicationId' => 'app-2'])]]), 'calls' => [['sync']]],
			'unchanged' => ['rows' => ($people + ['Interview' => [$stored]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1')], 'calls' => [['sync']]],
			'self-heal unchanged' => ['rows' => ($people + ['Interview' => [self::interview('i1')]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1')], 'calls' => [['sync']]],
			'rescheduled' => ['rows' => ($people + ['Interview' => [$stored, self::interview('i2', ['scheduledStart' => '2026-08-04T09:00:00+00:00'])]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1', '20260802T100000Z'), 'hrmq-interview-i2.ics' => self::ics('hrmq-interview-i2')], 'calls' => [['sync']]],
			'own uid kept' => ['rows' => ($people + ['Interview' => [self::interview('i1', ['calendarEventUid' => 'extern-77'])]]), 'objects' => ['extern-77.ics' => self::ics('extern-77', '20260801T100000Z')], 'calls' => [['sync']]],
			'missing and bad dates' => ['rows' => ($people + ['Interview' => [self::interview('i1', ['scheduledEnd' => ' ']), self::interview('i2', ['scheduledStart' => 'morgen']), self::interview('i3', ['scheduledEnd' => 'later'])]]), 'calls' => [['sync']]],
			'from bound' => ['rows' => ($people + ['Interview' => [self::interview('i1'), self::interview('i2', ['scheduledStart' => '2026-09-01T10:00:00+00:00', 'scheduledEnd' => '2026-09-01T11:00:00+00:00'])]]), 'calls' => [['sync', '2026-08-15']]],
			'cancelled' => ['rows' => ($people + ['Interview' => [self::interview('i1', ['status' => 'cancelled', 'calendarEventUid' => 'hrmq-interview-i1']), self::interview('i2', ['status' => 'cancelled']), self::interview('i3', ['status' => 'cancelled', 'calendarEventUid' => 'hrmq-interview-i3'])]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1')], 'calls' => [['sync']]],
			'completed and unknown' => ['rows' => ($people + ['Interview' => [self::interview('i1', ['status' => 'completed']), self::interview('i2', ['status' => 'planned']), self::interview('', ['@self' => []])]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1')], 'calls' => [['sync']]],
			'probe fails' => ['rows' => ($people + ['Interview' => [self::interview('i1'), self::interview('i2', ['status' => 'cancelled', 'calendarEventUid' => 'hrmq-interview-i2'])]]), 'fail' => ['getCalendarObjectByUID' => '*'], 'calls' => [['sync']]],
			'create fails' => ['rows' => ($people + ['Interview' => [self::interview('i1')]]), 'fail' => ['createCalendarObject' => 'hrmq-interview-i1.ics'], 'calls' => [['sync']]],
			'update fails' => ['rows' => ($people + ['Interview' => [self::interview('i1')]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1', '20260801T100000Z')], 'fail' => ['updateCalendarObject' => 'hrmq-interview-i1.ics'], 'calls' => [['sync']]],
			'fetch fails' => ['rows' => ($people + ['Interview' => [$stored]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1')], 'fail' => ['getCalendarObject' => '*'], 'calls' => [['sync']]],
			'delete fails' => ['rows' => ($people + ['Interview' => [self::interview('i1', ['status' => 'cancelled', 'calendarEventUid' => 'hrmq-interview-i1'])]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1')], 'fail' => ['deleteCalendarObject' => 'hrmq-interview-i1.ics'], 'calls' => [['sync']]],
			'uid write fails' => ['rows' => ($people + ['Interview' => [self::interview('i1')]]), 'failSave' => true, 'calls' => [['sync']]],
			'orphans' => ['rows' => ($people + ['Interview' => [$stored]]), 'objects' => ['hrmq-interview-i1.ics' => self::ics('hrmq-interview-i1'), 'hrmq-interview-old.ics' => self::ics('hrmq-interview-old'), 'privé.ics' => self::ics('privé')], 'calls' => [['sync']]],
			'second sync is a no-op' => ['rows' => ($people + ['Interview' => [$stored]]), 'calls' => [['sync'], ['sync']]],
			'second sync heals the uid' => ['rows' => ($people + ['Interview' => [self::interview('i1')]]), 'calls' => [['sync'], ['sync']]],
			'sync one' => ['rows' => ($people + ['Interview' => [self::interview('i1'), self::interview('i2', ['status' => 'completed'])]]), 'calls' => [['one', 'i1'], ['one', 'i2'], ['one', 'nope']]],
		];

	}//end scenarios()

}//end class
