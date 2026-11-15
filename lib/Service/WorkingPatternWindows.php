<?php

/**
 * Working-pattern windows
 *
 * Compares one employee's working-pattern windows (`from` date, `until`
 * date or 9999-12-31) and refuses the first pair that covers the same day,
 * for {@see WorkingHoursService::assertPatternsDoNotOverlap()}.
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
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Overlap check over one employee's working-pattern windows.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
 */
final class WorkingPatternWindows {

	/**
	 * Refuse the first pair of one employee's windows that share a day.
	 *
	 * @param int|string $employeeId The employee (an int when the id is numeric, as an array key).
	 * @param array<int, array{from: string, until: string}> $windows The employee's windows.
	 *
	 * @return void
	 *
	 * @throws OverlappingWorkingPatternException When two windows overlap.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	public function assertNoOverlap(int|string $employeeId, array $windows): void {
		$count = count($windows);
		for ($i = 0; $i < $count; $i++) {
			$b = $this->firstOverlapWith(windows: $windows, first: $i);
			if ($b !== null) {
				throw new OverlappingWorkingPatternException(
					'Two working patterns for employee ' . $employeeId
					. ' cover the same days (' . $windows[$i]['from'] . ' and ' . $b['from']
					. '). End the running pattern before writing a new one.'
				);
			}
		}
	}//end assertNoOverlap()

	/**
	 * The first window after window `$first` that shares a day with it, or null.
	 *
	 * @param array<int, array{from: string, until: string}> $windows One employee's windows.
	 * @param int $first The window to compare the later ones with.
	 *
	 * @return array{from: string, until: string}|null
	 */
	private function firstOverlapWith(array $windows, int $first): ?array {
		$a = $windows[$first];
		$count = count($windows);
		for ($j = ($first + 1); $j < $count; $j++) {
			$b = $windows[$j];
			if ($a['from'] <= substr($b['until'], 0, 10) && $b['from'] <= substr($a['until'], 0, 10)) {
				return $b;
			}
		}

		return null;
	}//end firstOverlapWith()
}//end class
