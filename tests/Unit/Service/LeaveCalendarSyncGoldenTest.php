<?php

/**
 * Golden test for LeaveCalendarService::sync().
 *
 * Runs whole syncs over fixed LeaveRequest, SickLeaveCase and Employee rows
 * against a recording CalDAV backend that can fail any call per object URI.
 * Between them the cases take every create, update, unchanged, remove,
 * skip and failure exit of the upsert, the removal and the orphan
 * reconciliation, plus the run-level skips. Every outcome row, every backend
 * call with its arguments and every log line is compared with
 * tests/fixtures/golden/leave-calendar-sync.json. Set RECORD_GOLDEN=1 to
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
 * @spec openspec/specs/leave-calendar-nc/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CalendarSettings;
use OCA\Humaniq\Service\LeaveCalendarService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A CalDAV backend double that records every call and fails on request.
 */
class RecordingCalDavBackend {

	/**
	 * Every call, in call order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Stored objects keyed by object URI: uid and raw ICS.
	 *
	 * @var array<string, array{uid: string, data: mixed}>
	 */
	public array $objects = [];

	/**
	 * Failing calls: method name => list of object URIs or UIDs ('*' fails every call).
	 *
	 * @var array<string, list<string>>
	 */
	public array $fail = [];

	/**
	 * @param string $principal The principal (unused).
	 * @param string $uri The calendar URI.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getCalendarByUri($principal, $uri) {
		$this->log('getCalendarByUri', [$principal, $uri]);
		return ($uri === 'team') ? ['id' => 7, 'uri' => $uri] : null;
	}//end getCalendarByUri()

	/**
	 * @param string $principalUri The principal.
	 * @param string $uid The VEVENT UID.
	 * @param string|null $calendarUri The calendar URI scope.
	 *
	 * @return string|null
	 */
	public function getCalendarObjectByUID($principalUri, $uid, $calendarUri = null) {
		$this->log('getCalendarObjectByUID', [$principalUri, $uid, $calendarUri], $uid);
		foreach ($this->objects as $objectUri => $object) {
			if ($object['uid'] === $uid) {
				return $calendarUri . '/' . $objectUri;
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
		$this->log('createCalendarObject', [$calendarId, $objectUri, $calendarData], $objectUri);
		$this->objects[$objectUri] = ['uid' => substr($objectUri, 0, -4), 'data' => $calendarData];
		return '"etag"';
	}//end createCalendarObject()

	/**
	 * @param mixed $calendarId The calendar id.
	 * @param string $objectUri The object URI.
	 * @param string $calendarData The raw ICS text.
	 *
	 * @return string
	 */
	public function updateCalendarObject($calendarId, $objectUri, $calendarData) {
		$this->log('updateCalendarObject', [$calendarId, $objectUri, $calendarData], $objectUri);
		$this->objects[$objectUri]['data'] = $calendarData;
		return '"etag"';
	}//end updateCalendarObject()

	/**
	 * @param mixed $calendarId The calendar id.
	 * @param string $objectUri The object URI.
	 *
	 * @return void
	 */
	public function deleteCalendarObject($calendarId, $objectUri) {
		$this->log('deleteCalendarObject', [$calendarId, $objectUri], $objectUri);
		unset($this->objects[$objectUri]);
	}//end deleteCalendarObject()

	/**
	 * @param mixed $calendarId The calendar id.
	 * @param string $objectUri The object URI.
	 *
	 * @return mixed
	 */
	public function getCalendarObject($calendarId, $objectUri) {
		$this->log('getCalendarObject', [$calendarId, $objectUri], $objectUri);
		if (isset($this->objects[$objectUri]) === false) {
			return null;
		}

		$data = $this->objects[$objectUri]['data'];
		return ($data === 'not-an-array') ? 'x' : ['calendardata' => $data];
	}//end getCalendarObject()

	/**
	 * @param mixed $calendarId The calendar id.
	 *
	 * @return array<int, mixed>
	 */
	public function getCalendarObjects($calendarId) {
		$this->log('getCalendarObjects', [$calendarId], '');
		$out = [];
		foreach (array_keys($this->objects) as $objectUri) {
			$out[] = ['uri' => $objectUri];
		}

		$out[] = 'not-an-array';
		return $out;
	}//end getCalendarObjects()

	/**
	 * Record one call, then throw when it is set to fail.
	 *
	 * @param string $method The method.
	 * @param array<int, mixed> $args The arguments.
	 * @param string|null $key The object URI or UID the failure list is matched on.
	 *
	 * @return void
	 */
	private function log(string $method, array $args, ?string $key = null): void {
		$this->calls[] = ['call' => $method, 'args' => $args];
		$failing = ($this->fail[$method] ?? []);
		if ($key !== null && (in_array('*', $failing, true) === true || in_array($key, $failing, true) === true)) {
			throw new \RuntimeException($method . ' failed for ' . $key);
		}
	}//end log()

}//end class

/**
 * Golden cases for LeaveCalendarService::sync().
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md
 */
class LeaveCalendarSyncGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/leave-calendar-sync.json';

	/**
	 * The log lines of the current case.
	 *
	 * @var list<array<string, string>>
	 */
	private array $logs = [];

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = [
			'full sync',
			'full sync from a bound',
			'second sync changes nothing',
			'every write fails',
			'every lookup fails',
			'stored events unreadable',
			'calendar listing fails',
			'unconfigured',
			'calendar not found',
			'backend lacks a method',
			'backend not resolvable',
			'register unavailable',
		];
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
		$actual = json_encode($this->observe($case), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$actual = $this->normalise((string)$actual);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode($actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $actual);

	}//end testGolden()

	/**
	 * Replace the run-time dates (DTSTAMP, today + 1) with fixed tokens.
	 *
	 * @param string $json The observed JSON.
	 *
	 * @return string
	 */
	private function normalise(string $json): string {
		$json = (string)preg_replace('/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:(now)', $json);
		$tomorrow = (new \DateTimeImmutable(gmdate('Y-m-d')))->modify('+1 day')->format('Ymd');
		return str_replace($tomorrow, '(tomorrow)', $json);

	}//end normalise()

	/**
	 * Run one case: its sync calls, the outcomes, the backend calls and the logs.
	 *
	 * @param string $case The case name.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(string $case): array {
		$backend = new RecordingCalDavBackend();
		$this->seed($backend);
		$config = ['principal' => 'principals/users/hr', 'uri' => 'team', 'backend' => $backend, 'register' => true];
		$from = null;

		switch ($case) {
			case 'full sync from a bound':
				$from = '2026-03-01';
				break;
			case 'every write fails':
				$backend->fail = ['createCalendarObject' => ['*'], 'updateCalendarObject' => ['*'], 'deleteCalendarObject' => ['*']];
				break;
			case 'every lookup fails':
				$backend->fail = ['getCalendarObjectByUID' => ['*']];
				break;
			case 'stored events unreadable':
				$backend->fail = ['getCalendarObject' => ['hrmq-leave-l-same.ics']];
				$backend->objects['hrmq-sick-s-closed.ics']['data'] = 'not-an-array';
				break;
			case 'calendar listing fails':
				$backend->fail = ['getCalendarObjects' => ['*']];
				break;
			case 'unconfigured':
				$config['uri'] = '';
				break;
			case 'calendar not found':
				$config['uri'] = 'other';
				break;
			case 'backend lacks a method':
				$config['backend'] = new class {
					/**
					 * @return array<string, mixed>
					 */
					public function getCalendarByUri() {
						return ['id' => 1];
					}//end getCalendarByUri()
				};
				break;
			case 'backend not resolvable':
				$config['backend'] = null;
				break;
			case 'register unavailable':
				$config['register'] = false;
				break;
		}//end switch

		$service = $this->service($config);
		$runs = [$service->sync($from)];
		if ($case === 'second sync changes nothing') {
			$backend->calls = [];
			$runs[] = $service->sync($from);
		}

		return ['runs' => $runs, 'calls' => $backend->calls, 'stored' => array_keys($backend->objects), 'logs' => $this->logs];

	}//end observe()

	/**
	 * Seed the calendar with an unchanged, a stale, a removable and two orphan events.
	 *
	 * @param RecordingCalDavBackend $backend The backend.
	 *
	 * @return void
	 */
	private function seed(RecordingCalDavBackend $backend): void {
		$event = static fn (string $start, string $end, string $summary): string => "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:" . $start . "\r\nDTEND;VALUE=DATE:" . $end . "\r\nSUMMARY:" . $summary . "\r\nEND:VEVENT\r\n";
		$backend->objects = [
			'hrmq-leave-l-same.ics' => ['uid' => 'hrmq-leave-l-same', 'data' => $event('20260302', '20260307', 'Verlof — Ann de Vries\, jr.')],
			'hrmq-leave-l-moved.ics' => ['uid' => 'hrmq-leave-l-moved', 'data' => $event('20260101', '20260102', 'Verlof — Bob')],
			'hrmq-leave-l-nosummary.ics' => ['uid' => 'hrmq-leave-l-nosummary', 'data' => "DTSTART:20260401\r\nDTEND:20260402\r\n"],
			'hrmq-leave-l-rejected.ics' => ['uid' => 'hrmq-leave-l-rejected', 'data' => $event('20260101', '20260102', 'x')],
			'hrmq-sick-s-closed.ics' => ['uid' => 'hrmq-sick-s-closed', 'data' => $event('20260210', '20260216', 'Afwezig — Bob')],
			'hrmq-leave-gone.ics' => ['uid' => 'hrmq-leave-gone', 'data' => 'x'],
			'hrmq-sick-gone.ics' => ['uid' => 'hrmq-sick-gone', 'data' => 'x'],
			'hrmq-leave-noext' => ['uid' => 'hrmq-leave-noext', 'data' => 'x'],
			'manual-event.ics' => ['uid' => 'manual', 'data' => 'x'],
		];

	}//end seed()

	/**
	 * The register rows by schema.
	 *
	 * @return array<string, list<mixed>>
	 */
	private function rows(): array {
		$leave = static fn (string $id, string $status, string $start, string $end, string $employee = 'e-ann'): array => ['id' => $id, 'status' => $status, 'startDate' => $start, 'endDate' => $end, 'employeeId' => $employee, 'reason' => 'private', 'leaveType' => 'holiday'];
		return [
			'Employee' => [
				['id' => 'e-ann', 'firstName' => 'Ann', 'lastName' => 'de Vries, jr.'],
				['@self' => ['id' => 'e-bob'], 'firstName' => 'Bob'],
				['id' => 'e-name', 'name' => 'Cas; Ltd'],
				['firstName' => 'NoId'],
				'not-a-row',
			],
			'LeaveRequest' => [
				$leave('l-new', 'approved', '2026-02-02', '2026-02-06'),
				$leave('l-same', 'approved', '2026-03-02', '2026-03-06'),
				$leave('l-moved', 'approved', '2026-03-09', '2026-03-13', 'e-bob'),
				$leave('l-nosummary', 'approved', '2026-04-01', '2026-04-01', 'e-name'),
				$leave('l-rejected', 'rejected', '2026-01-01', '2026-01-01'),
				$leave('l-pending', 'pending', '2026-01-01', '2026-01-01'),
				$leave('l-nodates', 'approved', ' ', '2026-01-01'),
				$leave('l-baddate', 'approved', '2026-13-40', '2026-01-01'),
				$leave('l-unknown', 'approved', '2026-05-04', '2026-05-04', 'e-missing'),
				['@self' => ['id' => 'l-self'], 'status' => 'approved', 'startDate' => '2026-06-01', 'endDate' => '2026-06-02'],
				['status' => 'approved', 'startDate' => '2026-06-01', 'endDate' => '2026-06-02'],
				new class {
					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['id' => 'l-entity', 'status' => 'approved', 'startDate' => '2026-02-23', 'endDate' => '2026-02-27', 'employeeId' => 'e-bob'];
					}//end jsonSerialize()
				},
			],
			'SickLeaveCase' => [
				['id' => 's-open', 'status' => 'gemeld', 'firstSickDay' => '2026-02-10', 'employeeId' => 'e-ann'],
				['id' => 's-closed', 'status' => 'hersteld', 'firstSickDay' => '2026-02-10', 'recoveredDate' => '2026-02-15', 'employeeId' => 'e-bob'],
				['id' => 's-late', 'status' => 'hersteld', 'firstSickDay' => '2026-03-02', 'recoveredDate' => '2026-03-20', 'employeeId' => 'e-name'],
				['id' => 's-norecovery', 'status' => 'hersteld', 'firstSickDay' => '2026-02-10', 'employeeId' => 'e-ann'],
				['id' => 's-nofirst', 'status' => 'gemeld', 'firstSickDay' => '', 'employeeId' => 'e-ann'],
				['id' => 's-baddate', 'status' => 'hersteld', 'firstSickDay' => '2026-02-31', 'recoveredDate' => '2026-03-01', 'employeeId' => 'e-ann'],
				['id' => 's-badrecovery', 'status' => 'hersteld', 'firstSickDay' => '2026-02-01', 'recoveredDate' => 'soon', 'employeeId' => 'e-ann'],
				['@self' => ['id' => 's-self'], 'status' => 'gemeld', 'firstSickDay' => '2026-04-01'],
				['status' => 'gemeld', 'firstSickDay' => '2026-04-01'],
			],
		];

	}//end rows()

	/**
	 * Build the service over the case's configuration.
	 *
	 * @param array<string, mixed> $config Principal, URI, backend and register availability.
	 *
	 * @return LeaveCalendarService
	 */
	private function service(array $config): LeaveCalendarService {
		$rows = $this->rows();
		$objects = new class($rows) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<mixed>> $rows Rows by schema.
			 */
			public function __construct(private readonly array $rows) {
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
			 * @param array<string, mixed> $options The query options.
			 *
			 * @return list<mixed>
			 */
			public function findAll(array $options = []): array {
				return $this->rows[$this->schema];
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($objects, $config) {
				if ($class === 'OCA\DAV\CalDAV\CalDavBackend' && $config['backend'] !== null) {
					return $config['backend'];
				}

				if ($class === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}

				throw new \RuntimeException('no ' . $class);
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn($config['register']);
		$calendars = new CalendarSettings(AppConfigValues::answer($this->createMock(IAppConfig::class), ['leave_calendar_principal' => $config['principal'], 'leave_calendar_uri' => $config['uri']]));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = [$level => $message];
				}
			);
		}

		return new LeaveCalendarService($container, $settings, $calendars, $logger);

	}//end service()

}//end class
