<?php

/**
 * The CAO components one payslip pays.
 *
 * Resolves the components the contract names (EmploymentTermsResolver),
 * selects the approved time entries of the timesheets this run pays, and
 * computes the lines (CaoComponentCalculator). The run adds the total to the
 * gross before the calculation, so components are taxed and insured as wage.
 * A component that does not resolve is listed and not paid; an override the
 * resolver refuses leaves every named component unresolved, never a guessed
 * figure (payroll-cao-components D3, D4).
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Folds a contract's CAO components into one payslip.
 *
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */
class CaoComponentPayService {

	/**
	 * Constructor.
	 *
	 * @param EmploymentTermsResolver $terms      Resolves the components.
	 * @param CaoComponentCalculator  $calculator Computes the lines.
	 * @param LoggerInterface         $logger     The logger.
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
	 */
	public function __construct(
		private readonly EmploymentTermsResolver $terms,
		private readonly CaoComponentCalculator $calculator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The components of one payslip, or null when the contract names none.
	 *
	 * @param array<string, mixed>       $contract         The covering contract.
	 * @param int                        $regularWageCents The regular wage of the period.
	 * @param array<string, mixed>|null  $hoursPay        The hours fold (timesheetIds, hourlyRate), or null.
	 * @param list<array<string, mixed>> $entries          Every TimeEntry.
	 * @param list<string>|null          $nonWorkingDates  Public holidays, or null when unread.
	 * @param string                     $period           The run's period.
	 *
	 * @return array{totalCents: int, lines: list<array<string, mixed>>, unresolved: list<string>}|null
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
	 */
	public function foldFor(array $contract, int $regularWageCents, ?array $hoursPay, array $entries, ?array $nonWorkingDates, string $period): ?array {
		if (array_filter((array)($contract['caoComponents'] ?? [])) === []) {
			return null;
		}

		try {
			$resolved = $this->terms->resolveComponents(contract: $contract);
		} catch (InvalidArgumentException $e) {
			$this->logger->warning('humaniq: the CAO components of contract ' . (string)($contract['id'] ?? '') . ' are not paid: ' . $e->getMessage());
			return ['totalCents' => 0, 'lines' => [], 'unresolved' => array_values(array_map('strval', (array)$contract['caoComponents']))];
		}

		$paid = array_flip(array_map('strval', (array)($hoursPay['timesheetIds'] ?? [])));
		$worked = array_values(array_filter($entries, static fn (array $entry): bool => isset($paid[(string)($entry['timesheetId'] ?? '')])));
		$rate = ($hoursPay['hourlyRate'] ?? ($contract['hourlyWage'] ?? null));
		$result = $this->calculator->compute(
			components: $resolved['components'],
			regularWageCents: $regularWageCents,
			hourlyRate: (is_numeric($rate) === true ? (float)$rate : null),
			entries: $worked,
			nonWorkingDates: ($nonWorkingDates ?? []),
			monthFraction: self::monthFraction(contract: $contract, period: $period)
		);

		return ['totalCents' => $result['totalCents'], 'lines' => $result['lines'], 'unresolved' => $resolved['unresolved']];
	}//end foldFor()

	/**
	 * The payslip fields of a fold; none when the contract names no component.
	 *
	 * @param array<string, mixed>|null $fold The fold, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
	 */
	public function payslipFields(?array $fold): array {
		if ($fold === null) {
			return [];
		}

		$lines = array_map(static function (array $line): array {
			$line['amount'] = round($line['amountCents'] / 100, 2);
			unset($line['amountCents']);
			return $line;
		}, $fold['lines']);

		return [
			'caoComponentLines' => array_values($lines),
			'caoComponentsTotal' => round($fold['totalCents'] / 100, 2),
			'caoComponentsUnresolved' => array_values($fold['unresolved']),
		];
	}//end payslipFields()

	/**
	 * The share of the period's month the contract covers, 1.0 when it
	 * covers all of it or the period cannot be read.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param string               $period   The period, `YYYY-MM`.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
	 */
	public static function monthFraction(array $contract, string $period): float {
		if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
			return 1.0;
		}

		$first = $period . '-01';
		$last = date('Y-m-t', (int)strtotime($first));
		$from = max($first, substr((string)($contract['startDate'] ?? ''), 0, 10));
		$until = substr((string)($contract['endDate'] ?? ''), 0, 10);
		$until = ($until === '' ? $last : min($last, $until));
		if ($from === $first && $until === $last) {
			return 1.0;
		}

		$days = (int)date('t', (int)strtotime($first));
		$covered = max(0, (int)((strtotime($until) - strtotime($from)) / 86400) + 1);
		return ($covered / $days);
	}//end monthFraction()

}//end class
