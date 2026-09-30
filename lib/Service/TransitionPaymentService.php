<?php

/**
 * Humaniq TransitionPaymentService
 *
 * Reads what the transition payment needs for one offboarding case (the
 * employee, their contracts and payslips, and the cap in the shipped
 * `nl-offboarding-transitievergoeding` rule), runs the calculator and writes
 * the amount and its breakdown on the case (hiring-offboarding-completion
 * D3, D4). A reason outside the dismissal list answers zero and writes
 * nothing. HR can overwrite the amount afterwards; the breakdown keeps what
 * the calculation said.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Standards\RuleCatalogue;

/**
 * Calculates and stores the payment on a case.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */
class TransitionPaymentService {

	private const SCHEMA = 'Offboarding';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway        $gateway    The register read and write.
	 * @param TransitionPaymentCalculator $calculator The statutory arithmetic.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly TransitionPaymentCalculator $calculator,
	) {

	}//end __construct()

	/**
	 * Calculate the payment of a case and, when one is due, store it.
	 *
	 * @param string $offboardingId The case, already resolved under the caller's rights.
	 *
	 * @return array<string, mixed>|null The result, or null when the case does not exist.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
	 */
	public function calculateFor(string $offboardingId): ?array {
		$case = $this->gateway->findObjectData($offboardingId, self::SCHEMA);
		if ($case === null) {
			return null;
		}

		$employeeId = (string)($case['employeeId'] ?? '');
		$result = $this->calculator->calculate(
			employee: ($this->gateway->findObjectData($employeeId, 'Employee') ?? []),
			contracts: $this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]),
			payslips: $this->gateway->findFiltered('Payslip', ['employeeId' => $employeeId]),
			parameters: $this->parameters(),
			endDate: (string)($case['lastWorkingDay'] ?? ''),
			reason: (string)($case['reason'] ?? '')
		);

		if (isset($result['serviceYears']) === false) {
			return $result;
		}

		unset($case['id'], $case['@self']);
		$case['transitievergoedingBedrag'] = $result['amountEur'];
		$case['transitievergoedingBerekening'] = $result;
		$this->gateway->save($case, self::SCHEMA, $offboardingId);

		return $result;
	}//end calculateFor()

	/**
	 * The parameters of the shipped transition payment rule. RuleCatalogue is
	 * the static rule data every check reads the same way.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function parameters(): array {
		foreach (RuleCatalogue::all() as $rule) {
			if (($rule['id'] ?? '') === TransitionPaymentCalculator::RULE_ID) {
				return (array)($rule['parameters'] ?? []);
			}
		}

		return [];
	}//end parameters()
}//end class
