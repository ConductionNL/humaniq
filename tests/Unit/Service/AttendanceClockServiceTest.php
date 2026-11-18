<?php

/**
 * Unit tests for AttendanceClockService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drives the service through a mocked record store and a fake
 * TransitionEngine, the two OpenRegister boundaries it uses.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Kernel\Contracts\OpenRegisterAvailabilityInterface;
use OCA\Humaniq\Service\AttendanceClockService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Clock in, clock out, and the one-open-day rule (REQ-TA-008).
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
 */
#[CoversClass(AttendanceClockService::class)]
final class AttendanceClockServiceTest extends TestCase {

	/**
	 * The record store.
	 *
	 * @var RecordStoreInterface&MockObject
	 */
	private RecordStoreInterface&MockObject $store;

	/**
	 * The fake TransitionEngine; it records its calls.
	 *
	 * @var object
	 */
	private object $engine;

	/**
	 * A service whose clock reads the given time.
	 *
	 * @param string                                    $now     The current time.
	 * @param array<string, list<array<string, mixed>>> $rows    Rows the store answers per schema.
	 * @param bool                                      $failing Whether the transition throws.
	 *
	 * @return AttendanceClockService
	 */
	private function service(string $now, array $rows = [], bool $failing = false): AttendanceClockService {
		$this->store = $this->createMock(RecordStoreInterface::class);
		$this->store->method('findFiltered')->willReturnCallback(
			static function (string $schema, array $filters) use ($rows): array {
				return array_values(
					array_filter(
						($rows[$schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		$this->engine = new class($failing) {
			/**
			 * @var list<array{objectId: string, action: string, data: array<string, mixed>}>
			 */
			public array $calls = [];

			/**
			 * @param bool $failing Whether a transition throws.
			 */
			public function __construct(private bool $failing) {
			}//end __construct()

			/**
			 * @param string               $objectId The object.
			 * @param string               $action   The transition.
			 * @param array<string, mixed> $data     The carrying write.
			 *
			 * @return object
			 */
			public function transition(string $objectId, string $action, array $data = []): object {
				if ($this->failing === true) {
					throw new RuntimeException('guard refused');
				}

				$this->calls[] = ['objectId' => $objectId, 'action' => $action, 'data' => $data];
				return new \stdClass();
			}//end transition()
		};

		$engine    = $this->engine;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\Lifecycle\TransitionEngine')->willReturn($engine);

		$openRegister = $this->createMock(OpenRegisterAvailabilityInterface::class);
		$openRegister->method('isAvailable')->willReturn(true);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable($now));

		return new AttendanceClockService($this->store, $container, $openRegister, $time, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The linked employee.
	 *
	 * @return array<string, mixed>
	 */
	private static function employee(): array {
		return ['id' => 'emp-1', 'nextcloudUserId' => 'sanne', 'administrationId' => 'adm-1'];
	}//end employee()

	/**
	 * Scenario: an employee clocks in at the start of the day.
	 *
	 * @return void
	 */
	public function testClockInOpensARecordStampedWithTheCallersOwnEmployee(): void {
		$service = $this->service('2026-03-02T08:30:00+01:00', ['Employee' => [self::employee(), ['id' => 'emp-2', 'nextcloudUserId' => 'piet']]]);

		$saved = new class {
			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'att-1';
			}//end getUuid()
		};
		$this->store->expects(self::once())->method('save')->with(
			[
				'employeeId' => 'emp-1',
				'userId' => 'sanne',
				'date' => '2026-03-02',
				'clockIn' => '2026-03-02T08:30:00+01:00',
				'breakMinutes' => 0,
				'status' => 'open',
				'administrationId' => 'adm-1',
			],
			'AttendanceRecord'
		)->willReturn($saved);

		$answer = $service->clockIn('sanne');

		self::assertSame(201, $answer['status']);
		self::assertSame('att-1', $answer['body']['record']['id']);
		self::assertSame('emp-1', $answer['body']['record']['employeeId']);
	}//end testClockInOpensARecordStampedWithTheCallersOwnEmployee()

	/**
	 * Scenario: a second clock in on an open day is refused.
	 *
	 * @return void
	 */
	public function testASecondClockInOnAnOpenDayIsRefused(): void {
		$service = $this->service(
			'2026-03-02T09:00:00+01:00',
			[
				'Employee' => [self::employee()],
				'AttendanceRecord' => [['id' => 'att-1', 'userId' => 'sanne', 'status' => 'open', 'clockIn' => '2026-03-02T08:30:00+01:00']],
			]
		);
		$this->store->expects(self::never())->method('save');

		$answer = $service->clockIn('sanne');

		self::assertSame(409, $answer['status']);
		self::assertStringContainsString('already open', $answer['body']['error']);
	}//end testASecondClockInOnAnOpenDayIsRefused()

	/**
	 * An account without a linked employee cannot clock in.
	 *
	 * @return void
	 */
	public function testAnUnlinkedAccountCannotClockIn(): void {
		$service = $this->service('2026-03-02T08:30:00+01:00', ['Employee' => [self::employee()]]);
		$this->store->expects(self::never())->method('save');

		self::assertSame(404, $service->clockIn('stranger')['status']);
		self::assertSame(404, $service->clockIn('')['status']);
	}//end testAnUnlinkedAccountCannotClockIn()

	/**
	 * Scenario: an employee clocks out at the end of the day, 8.0 hours after a 30 minute break.
	 *
	 * @return void
	 */
	public function testClockOutClosesTheDayThroughSluitenWithTheWorkedHours(): void {
		$service = $this->service(
			'2026-03-02T17:00:00+01:00',
			[
				'AttendanceRecord' => [
					['id' => 'att-9', 'userId' => 'piet', 'status' => 'open', 'clockIn' => '2026-03-02T07:00:00+01:00'],
					['id' => 'att-1', 'userId' => 'sanne', 'status' => 'open', 'clockIn' => '2026-03-02T08:30:00+01:00', 'breakMinutes' => 30],
				],
			]
		);

		$answer = $service->clockOut('sanne');

		self::assertSame(200, $answer['status']);
		self::assertSame(
			[['objectId' => 'att-1', 'action' => 'sluiten', 'data' => ['clockOut' => '2026-03-02T17:00:00+01:00', 'breakMinutes' => 30, 'workedHours' => 8.0]]],
			$this->engine->calls
		);
		self::assertSame('gesloten', $answer['body']['record']['status']);
	}//end testClockOutClosesTheDayThroughSluitenWithTheWorkedHours()

	/**
	 * A break given at clock out replaces the record's.
	 *
	 * @return void
	 */
	public function testABreakGivenAtClockOutIsUsed(): void {
		$service = $this->service('2026-03-02T17:00:00+01:00', ['AttendanceRecord' => [['id' => 'att-1', 'userId' => 'sanne', 'status' => 'open', 'clockIn' => '2026-03-02T08:30:00+01:00']]]);

		$service->clockOut('sanne', 45);

		self::assertSame(7.75, $this->engine->calls[0]['data']['workedHours']);
		self::assertSame(45, $this->engine->calls[0]['data']['breakMinutes']);
		self::assertSame(400, $service->clockOut('sanne', -5)['status']);
	}//end testABreakGivenAtClockOutIsUsed()

	/**
	 * Without an open day there is nothing to clock out of; a refused transition is reported.
	 *
	 * @return void
	 */
	public function testClockOutWithoutAnOpenDayOrAFailingTransition(): void {
		self::assertSame(409, $this->service('2026-03-02T17:00:00+01:00')->clockOut('sanne')['status']);
		self::assertSame([], $this->engine->calls);

		$failing = $this->service('2026-03-02T17:00:00+01:00', ['AttendanceRecord' => [['id' => 'att-1', 'userId' => 'sanne', 'status' => 'open', 'clockIn' => '2026-03-02T08:30:00+01:00']]], true);
		self::assertSame(502, $failing->clockOut('sanne')['status']);
	}//end testClockOutWithoutAnOpenDayOrAFailingTransition()

	/**
	 * Worked hours never go below zero and an unreadable clock-in reads as zero.
	 *
	 * @return void
	 */
	public function testWorkedHoursFloorsAtZero(): void {
		$out = new DateTimeImmutable('2026-03-02T09:00:00+01:00');

		self::assertSame(0.0, AttendanceClockService::workedHours('2026-03-02T08:50:00+01:00', $out, 30));
		self::assertSame(0.0, AttendanceClockService::workedHours('not a time', $out, 0));
		self::assertSame(0.5, AttendanceClockService::workedHours('2026-03-02T08:30:00+01:00', $out, 0));
	}//end testWorkedHoursFloorsAtZero()
}//end class
