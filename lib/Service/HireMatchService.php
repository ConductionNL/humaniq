<?php

/**
 * Finds the existing employees a new hire may already be.
 *
 * Three keys, in this order: the same BSN; the same last name and date of
 * birth; the same private e-mail. Name alone is never a key, because two
 * different people often share one. Each person is listed once, under the
 * strongest key that matched, with whether they have left
 * (hiring-hire-to-employee D3).
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
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The ordered duplicate check.
 */
class HireMatchService {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads the employees.
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
	) {
	}//end __construct()

	/**
	 * The employees, active or former, this person may already be.
	 *
	 * @param array<string, mixed> $person The hire: bsn, lastName, dateOfBirth, privateEmail (each optional).
	 *
	 * @return list<array{employeeId: string, name: string, matchedOn: string, hasLeft: bool, endDate: ?string, startDate: ?string}>
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
	 */
	public function matches(array $person): array {
		$wanted = [
			'bsn' => self::bsn($person['bsn'] ?? null),
			'name-and-birth-date' => self::nameAndBirthDate($person['lastName'] ?? null, $person['dateOfBirth'] ?? null),
			'private-email' => self::email($person['privateEmail'] ?? null),
		];
		if (array_filter($wanted) === []) {
			return [];
		}

		$employees = $this->gateway->loadAll('Employee');
		$found = [];
		foreach ($wanted as $key => $value) {
			if ($value === '') {
				continue;
			}

			foreach ($employees as $employee) {
				$employeeId = (string)($employee['id'] ?? ($employee['@self']['id'] ?? ''));
				if ($employeeId === '' || isset($found[$employeeId]) === true || self::keyOf(key: $key, employee: $employee) !== $value) {
					continue;
				}

				$found[$employeeId] = self::row(employeeId: $employeeId, employee: $employee, matchedOn: $key);
			}
		}

		return array_values($found);
	}//end matches()

	/**
	 * One key's value for an employee.
	 *
	 * @param string               $key      The key.
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return string
	 */
	private static function keyOf(string $key, array $employee): string {
		return match ($key) {
			'bsn' => self::bsn($employee['bsn'] ?? null),
			'name-and-birth-date' => self::nameAndBirthDate($employee['lastName'] ?? null, $employee['dateOfBirth'] ?? null),
			default => self::email($employee['privateEmail'] ?? null),
		};
	}//end keyOf()

	/**
	 * One match row.
	 *
	 * @param string               $employeeId The employee id.
	 * @param array<string, mixed> $employee   The employee.
	 * @param string               $matchedOn  The key that matched.
	 *
	 * @return array{employeeId: string, name: string, matchedOn: string, hasLeft: bool, endDate: ?string, startDate: ?string}
	 */
	private static function row(string $employeeId, array $employee, string $matchedOn): array {
		$endDate = trim((string)($employee['endDate'] ?? ''));
		$startDate = trim((string)($employee['startDate'] ?? ''));
		$name = trim(trim((string)($employee['firstName'] ?? '')) . ' ' . trim((string)($employee['lastName'] ?? '')));

		return [
			'employeeId' => $employeeId,
			'name' => $name,
			'matchedOn' => $matchedOn,
			'hasLeft' => $endDate !== '',
			'endDate' => ($endDate !== '' ? $endDate : null),
			'startDate' => ($startDate !== '' ? $startDate : null),
		];
	}//end row()

	/**
	 * A BSN as digits only.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 */
	private static function bsn(mixed $value): string {
		return (string)preg_replace('/\D/', '', (string)($value ?? ''));
	}//end bsn()

	/**
	 * Last name and date of birth as one key, empty unless both are known.
	 *
	 * @param mixed $lastName    The last name.
	 * @param mixed $dateOfBirth The date of birth.
	 *
	 * @return string
	 */
	private static function nameAndBirthDate(mixed $lastName, mixed $dateOfBirth): string {
		$name = mb_strtolower((string)preg_replace('/\s+/', ' ', trim((string)($lastName ?? ''))));
		$born = trim((string)($dateOfBirth ?? ''));
		if ($name === '' || $born === '') {
			return '';
		}

		return $name . '|' . $born;
	}//end nameAndBirthDate()

	/**
	 * An e-mail address, trimmed and lower-cased.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 */
	private static function email(mixed $value): string {
		return mb_strtolower(trim((string)($value ?? '')));
	}//end email()

}//end class
