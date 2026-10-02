<?php

/**
 * IncomeRelationshipLine
 *
 * One income relationship (inkomstenverhouding) of the wage tax return,
 * made from an employee, their contract, the run's payslip and the engine's
 * recalculation of that payslip. Every element follows the 2026
 * Gegevensspecificaties (GS) as listed in the filings-wage-tax-message
 * design, D5; what cannot be reported without a guess is a finding, never a
 * default.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;

use OCA\Humaniq\Payroll\CalculationResult;

/**
 * One income relationship: its elements, its amounts in cents, its findings.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 * @SuppressWarnings(PHPMD.StaticAccess) The parts are pure static builders over one LineFacts.
 */
final class IncomeRelationshipLine {

	/**
	 * The end reasons of GS p64.
	 *
	 * @var list<string>
	 */
	public const END_REASONS = ['01', '03', '04', '05', '06', '20', '21', '30', '32', '33', '34', '40', '41', '50', '51', '90', '91', '92', '99'];

	/**
	 * The amounts of Werknemersgegevens in the XSD's order.
	 *
	 * @var list<string>
	 */
	public const AMOUNTS = AmountsPart::AMOUNTS;

	/**
	 * Make the line.
	 *
	 * @param array<string, mixed>        $employee The Employee (with its id).
	 * @param array<string, mixed>        $contract The covering EmploymentContract, or empty.
	 * @param array<string, mixed>        $payslip  The run's Payslip.
	 * @param CalculationResult|null      $result   The engine's recalculation, or null when it failed.
	 * @param array{0: string, 1: string} $period   The declaration period's first and last day.
	 *
	 * @return array{employeeId: string, tree: array<string, mixed>, cents: array<string, int>, findings: list<array{kind: string, severity: string, employeeId: string, element: string, problem: string}>}
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function make(array $employee, array $contract, array $payslip, ?CalculationResult $result, array $period): array {
		$facts = new LineFacts($employee, $contract, $payslip, $result, $period);
		$code = PeriodPart::incomeCode($facts);
		$cents = AmountsPart::cents($facts, $code);
		$start = self::startDate($facts);
		$end = self::endDate($facts);

		$tree = [
			'NumIV' => (string)max(0, (int)($employee['incomeRelationshipNumber'] ?? 1)),
			'DatAanv' => $start,
			'DatEind' => $end,
			'CdRdnEindArbov' => self::endReason($facts, $code, $end),
			'PersNr' => $facts->filled('employeeNumber'),
			'NatuurlijkPersoon' => PersonPart::make($facts),
			'Inkomstenperiode' => [PeriodPart::make($facts, $code, $start)],
			'Werknemersgegevens' => AmountsPart::group($facts, $code, $cents),
		];

		return ['employeeId' => (string)($employee['id'] ?? ''), 'tree' => $tree, 'cents' => $cents, 'findings' => $facts->findings()];
	}//end make()

	/**
	 * Whether a nine-digit number passes the elfproef (GS p34, p66).
	 *
	 * @param string $number The number.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function elfproef(string $number): bool {
		return PersonPart::elfproef($number);
	}//end elfproef()

	/**
	 * The start of the income relationship (GS p60).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return string
	 */
	private static function startDate(LineFacts $facts): string {
		$start = $facts->filled('startDate');
		if ($start === null) {
			$facts->find('employee-without-start-date', 'DatAanv', $facts->name() . ' heeft geen datum in dienst.');
			return $facts->period[0];
		}

		return $start;
	}//end startDate()

	/**
	 * The end date, only once it falls on or before the period end (GS p62, 0040).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return string|null
	 */
	private static function endDate(LineFacts $facts): ?string {
		$end = $facts->filled('endDate');
		return ($end !== null && $end <= $facts->period[1]) ? $end : null;
	}//end endDate()

	/**
	 * The end reason, required for an ended 11/13/15 relationship (GS p65, 2501).
	 *
	 * @param LineFacts   $facts The line.
	 * @param string      $code  The income code.
	 * @param string|null $end   The reported end date.
	 *
	 * @return string|null
	 */
	private static function endReason(LineFacts $facts, string $code, ?string $end): ?string {
		if ($end === null || in_array($code, PeriodPart::EMPLOYMENT_CODES, true) === false) {
			return null;
		}

		$reason = (string)($facts->employee['endReason'] ?? '');
		if (in_array($reason, self::END_REASONS, true) === false) {
			$facts->find('employment-end-without-reason', 'CdRdnEindArbov', 'Het dienstverband van ' . $facts->name() . ' eindigt op ' . $end . ' zonder reden van einde arbeidsverhouding.');
			return null;
		}

		return $reason;
	}//end endReason()

}//end class
