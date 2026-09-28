<?php

/**
 * Frequent Absence Service
 *
 * Counts an employee's sickness cases against their administration's
 * threshold (absence-deadlines-and-signals design.md D4): the cases whose
 * first sick day falls in the window of `frequentAbsenceWindowMonths` (12 when
 * unset) ending on the counted case's first sick day. A relapse reopens the
 * same case, so it counts once. The count and the signal (count at or above
 * `frequentAbsenceThreshold`, 3 when unset) are stamped on the counted case
 * under InternalWriteMarker, so the post-save listener does not count its own
 * write. No medical data is read or written.
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The frequent-absence count and signal for one sickness case.
 *
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
 */
class FrequentAbsenceService {

	/**
	 * Threshold when the administration sets none.
	 *
	 * @var int
	 */
	public const DEFAULT_THRESHOLD = 3;

	/**
	 * Window in months when the administration sets none.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_MONTHS = 12;

	/**
	 * @param HoursRegisterGateway $gateway Reads cases and administrations, writes the stamp.
	 * @param InternalWriteMarker $marker Marks the stamp as humaniq's own write.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
	) {

	}//end __construct()

	/**
	 * Count the employee's cases in the window ending on this case's first
	 * sick day, and whether that reaches the threshold.
	 *
	 * @param array<string, mixed> $case The SickLeaveCase.
	 *
	 * @return array{episodesInWindow: int, frequentAbsence: bool}|null Null when the case has no employee or first sick day.
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
	 */
	public function evaluate(array $case): ?array {
		$employeeId = trim((string)($case['employeeId'] ?? ''));
		$day = trim((string)($case['firstSickDay'] ?? ''));
		if ($employeeId === '' || $day === '') {
			return null;
		}

		[$threshold, $months] = $this->policyFor(trim((string)($case['administrationId'] ?? '')));
		$from = gmdate('Y-m-d', (int)strtotime($day . ' -' . $months . ' months'));

		$count = 0;
		foreach ($this->gateway->findFiltered('SickLeaveCase', ['employeeId' => $employeeId]) as $other) {
			$otherDay = trim((string)($other['firstSickDay'] ?? ''));
			if ($otherDay > $from && $otherDay <= $day) {
				$count++;
			}
		}

		return ['episodesInWindow' => $count, 'frequentAbsence' => $count >= $threshold];
	}//end evaluate()

	/**
	 * Evaluate a case and stamp the count and signal on it.
	 *
	 * @param string $caseId The SickLeaveCase id.
	 * @param array<string, mixed> $case The SickLeaveCase as saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
	 */
	public function stamp(string $caseId, array $case): void {
		$result = $this->evaluate($case);
		if ($result === null) {
			return;
		}

		$update = array_merge($case, $result);
		unset($update['@self'], $update['id']);
		$this->marker->runInternal(fn () => $this->gateway->save($update, 'SickLeaveCase', $caseId));
	}//end stamp()

	/**
	 * The administration's threshold and window, or the defaults.
	 *
	 * @param string $administrationId The administration key.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function policyFor(string $administrationId): array {
		$administration = [];
		if ($administrationId !== '') {
			$administration = ($this->gateway->findFiltered('hrAdministration', ['administrationId' => $administrationId])[0] ?? []);
		}

		$threshold = ($administration['frequentAbsenceThreshold'] ?? null);
		$months = ($administration['frequentAbsenceWindowMonths'] ?? null);

		return [
			(is_numeric($threshold) === true && (int)$threshold > 0) ? (int)$threshold : self::DEFAULT_THRESHOLD,
			(is_numeric($months) === true && (int)$months > 0) ? (int)$months : self::DEFAULT_WINDOW_MONTHS,
		];
	}//end policyFor()

}//end class
