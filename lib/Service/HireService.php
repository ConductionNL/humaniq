<?php

/**
 * Turns a hired application into an employee and an onboarding case.
 *
 * HR presses Create employee on an application at aangenomen and confirms the
 * start date and the name. This service creates the Employee from the
 * application's data, or attaches the hire to an existing record HR chose,
 * starts an Onboarding case at aangenomen, and links the application to the
 * employee. The application's status is carried unchanged, so no transition
 * fires. A linked application answers its employee and writes nothing, so a
 * double click never makes two records (hiring-hire-to-employee D1-D3).
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
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Create employee, attach to an existing one, or report the matches. *
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */
class HireService {

	private const APPLICATION_SCHEMA = 'job-application';
	private const EMPLOYEE_SCHEMA = 'Employee';
	private const ONBOARDING_SCHEMA = 'Onboarding';
	private const HIRED = 'aangenomen';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads and writes the register.
	 * @param HireMatchService     $matcher The duplicate check.
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly HireMatchService $matcher,
	) {
	}//end __construct()

	/**
	 * The proposed first and last name, split on the first space so a Dutch
	 * prefix stays with the last name. HR corrects it in the dialog.
	 *
	 * @param string $candidateName The name on the application.
	 *
	 * @return array{firstName: string, lastName: string}
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
	 */
	public function proposedName(string $candidateName): array {
		$parts = explode(' ', (string)preg_replace('/\s+/', ' ', trim($candidateName)), 2);
		if (count($parts) === 1) {
			return ['firstName' => '', 'lastName' => $parts[0]];
		}

		return ['firstName' => $parts[0], 'lastName' => $parts[1]];
	}//end proposedName()

	/**
	 * Hire: create or attach, start the case, link the application.
	 *
	 * @param string               $applicationId The application.
	 * @param array<string, mixed> $input         startDate, firstName, lastName, bsn, dateOfBirth, attachToEmployeeId, createNew.
	 *
	 * @return array<string, mixed> outcome created, attached, already-linked, matches, not-found, not-hired or invalid.
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
	 */
	public function hire(string $applicationId, array $input): array {
		$application = $this->gateway->findObjectData($applicationId, self::APPLICATION_SCHEMA);
		if ($application === null) {
			return ['outcome' => 'not-found', 'message' => 'Application not found.'];
		}

		$linked = self::text($application['employeeId'] ?? null);
		if ($linked !== '') {
			return ['outcome' => 'already-linked', 'employeeId' => $linked];
		}

		if (($application['status'] ?? null) !== self::HIRED) {
			return ['outcome' => 'not-hired', 'message' => 'Only a hired application can become an employee.'];
		}

		$person = self::personFrom(application: $application, input: $input);
		$problem = self::problemWith(person: $person);
		if ($problem !== null) {
			return ['outcome' => 'invalid', 'message' => $problem];
		}

		$attachTo = self::text($input['attachToEmployeeId'] ?? null);
		if ($attachTo !== '') {
			return $this->attach(applicationId: $applicationId, application: $application, employeeId: $attachTo, person: $person);
		}

		if (($input['createNew'] ?? false) !== true) {
			$matches = $this->matcher->matches($person);
			if ($matches !== []) {
				return ['outcome' => 'matches', 'matches' => $matches];
			}
		}

		$employeeId = (string)$this->gateway->save(self::withoutEmpty($person), self::EMPLOYEE_SCHEMA)->getUuid();

		return $this->startCase(applicationId: $applicationId, application: $application, employeeId: $employeeId, startDate: $person['startDate'], outcome: 'created');
	}//end hire()

	/**
	 * Attach the hire to an existing employee. A former employee gets the new
	 * start date and no end date; an active one changes no dates.
	 *
	 * @param string               $applicationId The application.
	 * @param array<string, mixed> $application   The stored application.
	 * @param string               $employeeId    The chosen employee.
	 * @param array<string, mixed> $person        The confirmed hire.
	 *
	 * @return array<string, mixed>
	 */
	private function attach(string $applicationId, array $application, string $employeeId, array $person): array {
		$employee = $this->gateway->findObjectData($employeeId, self::EMPLOYEE_SCHEMA);
		if ($employee === null) {
			return ['outcome' => 'invalid', 'message' => 'The chosen employee does not exist.'];
		}

		if (self::text($employee['endDate'] ?? null) !== '') {
			unset($employee['id'], $employee['@self']);
			$employee['endDate'] = null;
			$employee['startDate'] = $person['startDate'];
			$this->gateway->save($employee, self::EMPLOYEE_SCHEMA, $employeeId);
		}

		return $this->startCase(applicationId: $applicationId, application: $application, employeeId: $employeeId, startDate: $person['startDate'], outcome: 'attached');
	}//end attach()

	/**
	 * Start the onboarding case and link the application, status unchanged.
	 *
	 * @param string               $applicationId The application.
	 * @param array<string, mixed> $application   The stored application.
	 * @param string               $employeeId    The employee.
	 * @param string               $startDate     The first working day.
	 * @param string               $outcome       created or attached.
	 *
	 * @return array<string, mixed>
	 */
	private function startCase(string $applicationId, array $application, string $employeeId, string $startDate, string $outcome): array {
		$case = self::withoutEmpty(
			[
				'employeeId' => $employeeId,
				'startDate' => $startDate,
				'status' => self::HIRED,
				'administrationId' => self::text($application['administrationId'] ?? null),
			]
		);
		$onboardingId = (string)$this->gateway->save($case, self::ONBOARDING_SCHEMA)->getUuid();

		unset($application['id'], $application['@self']);
		$application['employeeId'] = $employeeId;
		$this->gateway->save($application, self::APPLICATION_SCHEMA, $applicationId);

		return ['outcome' => $outcome, 'employeeId' => $employeeId, 'onboardingId' => $onboardingId];
	}//end startCase()

	/**
	 * The employee fields from the application and HR's confirmation.
	 *
	 * @param array<string, mixed> $application The application.
	 * @param array<string, mixed> $input       HR's confirmation.
	 *
	 * @return array<string, mixed>
	 */
	private static function personFrom(array $application, array $input): array {
		return [
			'firstName' => self::text($input['firstName'] ?? null),
			'lastName' => self::text($input['lastName'] ?? null),
			'privateEmail' => self::text($application['email'] ?? null),
			'phone' => self::text($application['phone'] ?? null),
			'bsn' => self::text($input['bsn'] ?? null),
			'dateOfBirth' => self::text($input['dateOfBirth'] ?? null),
			'startDate' => self::text($input['startDate'] ?? null),
			'administrationId' => self::text($application['administrationId'] ?? null),
		];
	}//end personFrom()

	/**
	 * What is wrong with the confirmed hire, or null.
	 *
	 * @param array<string, mixed> $person The hire.
	 *
	 * @return string|null
	 */
	private static function problemWith(array $person): ?string {
		if (self::isDate($person['startDate']) === false) {
			return 'A start date is needed, as YYYY-MM-DD.';
		}

		if ($person['lastName'] === '') {
			return 'A last name is needed.';
		}

		if ($person['dateOfBirth'] !== '' && self::isDate($person['dateOfBirth']) === false) {
			return 'The date of birth must be a date, as YYYY-MM-DD.';
		}

		return null;
	}//end problemWith()

	/**
	 * Whether a value is a real Y-m-d date.
	 *
	 * @param string $value The value.
	 *
	 * @return boolean
	 */
	private static function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
	}//end isDate()

	/**
	 * A payload without its empty fields.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutEmpty(array $payload): array {
		return array_filter($payload, static fn (mixed $value): bool => $value !== '' && $value !== null);
	}//end withoutEmpty()

	/**
	 * A trimmed string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

}//end class
