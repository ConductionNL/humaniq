<?php

/**
 * Attendance Clock Service
 *
 * Clock in and clock out from `My attendance` (REQ-TA-008). Clocking in
 * creates an open AttendanceRecord stamped on the server with the caller's
 * own employee and user id and the current time, so the employee never picks
 * themselves. Clocking out closes the caller's open record through the
 * schema's own `sluiten` transition, with the current time as `clockOut` and
 * `workedHours` computed here from the clock times and the break. An employee
 * has at most one open record at a time.
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
 * @spec openspec/specs/time-attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Humaniq\Kernel\Contracts\OpenRegisterAvailabilityInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The caller's own clock day: open it, close it.
 *
 * @spec openspec/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
 */
final class AttendanceClockService {

	/**
	 * The clock record's schema.
	 */
	public const SCHEMA = 'AttendanceRecord';

	/**
	 * OpenRegister's lifecycle engine, which runs the `sluiten` transition.
	 */
	private const TRANSITION_ENGINE = 'OCA\OpenRegister\Service\Lifecycle\TransitionEngine';

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface              $store        Reads employees and records, writes the new record.
	 * @param ContainerInterface                $container    Resolves OpenRegister's TransitionEngine.
	 * @param OpenRegisterAvailabilityInterface $openRegister OpenRegister availability.
	 * @param ITimeFactory                      $timeFactory  The clock.
	 * @param LoggerInterface                   $logger       Logger.
	 *
	 * @spec openspec/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
	 */
	public function __construct(
		private readonly RecordStoreInterface $store,
		private readonly ContainerInterface $container,
		private readonly OpenRegisterAvailabilityInterface $openRegister,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Open a clock day for the caller at the current time.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return array{status: int, body: array<string, mixed>} 201 with the record; 404 without a linked employee; 409 when a day is already open.
	 *
	 * @spec openspec/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
	 */
	public function clockIn(string $uid): array {
		$employee = $this->employeeOf(uid: $uid);
		if ($employee === null) {
			return self::answer(status: 404, body: ['error' => 'Your account is not linked to an employee, so you cannot clock in.']);
		}

		if ($this->openRecordOf(uid: $uid) !== null) {
			return self::answer(status: 409, body: ['error' => 'A day is already open. Clock out before you clock in again.']);
		}

		$now = $this->timeFactory->now();
		$record = [
			'employeeId' => (string)($employee['id'] ?? $employee['@self']['id'] ?? ''),
			'userId' => $uid,
			'date' => $now->format('Y-m-d'),
			'clockIn' => $now->format(DateTimeInterface::ATOM),
			'breakMinutes' => 0,
			'status' => 'open',
		];
		$administrationId = trim((string)($employee['administrationId'] ?? ''));
		if ($administrationId !== '') {
			$record['administrationId'] = $administrationId;
		}

		$saved = $this->store->save(payload: $record, schema: self::SCHEMA);

		return self::answer(status: 201, body: ['record' => array_merge($record, ['id' => self::uuidOf(entity: $saved)])]);
	}//end clockIn()

	/**
	 * Close the caller's open clock day at the current time.
	 *
	 * @param string   $uid          The signed-in user.
	 * @param int|null $breakMinutes The break taken, or null to keep the record's.
	 *
	 * @return array{status: int, body: array<string, mixed>} 200 with the closed record; 409 when no day is open; 400 for a negative break; 502 when the transition fails.
	 *
	 * @spec openspec/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
	 */
	public function clockOut(string $uid, ?int $breakMinutes = null): array {
		if ($breakMinutes !== null && $breakMinutes < 0) {
			return self::answer(status: 400, body: ['error' => 'The break cannot be negative.']);
		}

		$record = $this->openRecordOf(uid: $uid);
		if ($record === null) {
			return self::answer(status: 409, body: ['error' => 'You have no open day to clock out of.']);
		}

		$now = $this->timeFactory->now();
		$break = ($breakMinutes ?? (int)($record['breakMinutes'] ?? 0));
		$data = [
			'clockOut' => $now->format(DateTimeInterface::ATOM),
			'breakMinutes' => $break,
			'workedHours' => self::workedHours(clockIn: (string)($record['clockIn'] ?? ''), clockOut: $now, breakMinutes: $break),
		];

		$id = (string)($record['id'] ?? $record['@self']['id'] ?? '');
		try {
			$this->transitionEngine()->transition(objectId: $id, action: 'sluiten', data: $data);
		} catch (\Throwable $e) {
			$this->logger->warning('AttendanceClockService: uitklokken van ' . $id . ' mislukt: ' . $e->getMessage());
			return self::answer(status: 502, body: ['error' => 'Clocking out failed: ' . $e->getMessage()]);
		}

		return self::answer(status: 200, body: ['record' => array_merge($record, $data, ['id' => $id, 'status' => 'gesloten'])]);
	}//end clockOut()

	/**
	 * Hours worked between two clock times less the break, at least zero,
	 * to two decimals.
	 *
	 * @param string            $clockIn      The clock-in time (ISO 8601).
	 * @param DateTimeInterface $clockOut     The clock-out time.
	 * @param int               $breakMinutes The break.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
	 */
	public static function workedHours(string $clockIn, DateTimeInterface $clockOut, int $breakMinutes): float {
		try {
			$start = new DateTimeImmutable($clockIn);
		} catch (\Throwable) {
			return 0.0;
		}

		$minutes = (($clockOut->getTimestamp() - $start->getTimestamp()) / 60) - $breakMinutes;

		return round(max(0.0, $minutes / 60), 2);
	}//end workedHours()

	/**
	 * The caller's own Employee, linked by `nextcloudUserId`.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return array<string, mixed>|null
	 */
	private function employeeOf(string $uid): ?array {
		if (trim($uid) === '') {
			return null;
		}

		return ($this->store->findFiltered(schema: 'Employee', filters: ['nextcloudUserId' => $uid])[0] ?? null);
	}//end employeeOf()

	/**
	 * The caller's open clock record, or null.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return array<string, mixed>|null
	 */
	private function openRecordOf(string $uid): ?array {
		if (trim($uid) === '') {
			return null;
		}

		return ($this->store->findFiltered(schema: self::SCHEMA, filters: ['userId' => $uid, 'status' => 'open'])[0] ?? null);
	}//end openRecordOf()

	/**
	 * The uuid of a saved entity, or an empty string.
	 *
	 * @param object $entity The saved ObjectEntity.
	 *
	 * @return string
	 */
	private static function uuidOf(object $entity): string {
		if (method_exists($entity, 'getUuid') === true) {
			return (string)$entity->getUuid();
		}

		return '';
	}//end uuidOf()

	/**
	 * A status and body pair.
	 *
	 * @param int                  $status The HTTP status.
	 * @param array<string, mixed> $body   The body.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private static function answer(int $status, array $body): array {
		return ['status' => $status, 'body' => $body];
	}//end answer()

	/**
	 * OpenRegister's TransitionEngine.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function transitionEngine(): mixed {
		if ($this->openRegister->isAvailable() === false) {
			throw new RuntimeException('humaniq requires the OpenRegister app, which is not installed on this instance.');
		}

		return $this->container->get(self::TRANSITION_ENGINE);
	}//end transitionEngine()
}//end class
