<?php

/**
 * ReadsEmployeeKeys.
 *
 * The keys an employee is known by on other records, for a class that offers idOf().
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude helper shared by services and checks, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

/**
 * The keys an employee is known by.
 */
trait ReadsEmployeeKeys {
	/**
	 * The employee's id, slug and number, leaving out the empty ones.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return array<int, string>
	 */
	private function employeeKeys(array $employee): array {
		return array_filter(
			[
				$this->idOf($employee),
				(string)($employee['@self']['slug'] ?? ''),
				trim((string)($employee['employeeNumber'] ?? '')),
			],
			static fn (string $key): bool => $key !== ''
		);
	}//end employeeKeys()
}//end class
