<?php

/**
 * Deviations of one payslip from the employee's own paid history.
 *
 * For each component (gross, net, wage tax, employer cost, hours paid) the
 * baseline is the median of the employee's six most recent earlier paid
 * payslips. A deviation is flagged when it exceeds the larger of the
 * component's relative threshold times the baseline and its absolute floor.
 * A cause the payslip carries and the history lacks (sick pay, a retro
 * correction, leave bought or sold, a garnishment, a car benefit, overtime,
 * reimbursements, or an applied raise) is named and drops the finding to
 * info (payroll-run-checks D3, D4).
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * A median and a threshold anyone can recompute; no I/O.
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-003
 */
class PayAnomalyDetector {

	/**
	 * Default thresholds per component: relative share of the baseline and an
	 * absolute floor (euros, or hours for hoursPaid).
	 *
	 * @var array<string, array{relative: float, absolute: float}>
	 */
	public const DEFAULT_THRESHOLDS = [
		'grossPay' => ['relative' => 0.15, 'absolute' => 50.0],
		'nettoPay' => ['relative' => 0.15, 'absolute' => 50.0],
		'loonheffing' => ['relative' => 0.15, 'absolute' => 50.0],
		'employerCost' => ['relative' => 0.15, 'absolute' => 50.0],
		'hoursPaid' => ['relative' => 0.20, 'absolute' => 8.0],
	];

	/**
	 * The causes a payslip can carry, with the words that name them.
	 *
	 * @var array<string, string>
	 */
	private const CAUSES = [
		'sickLeaveCaseId' => 'loondoorbetaling bij ziekte',
		'retroAdjustment' => 'een correctie met terugwerkende kracht',
		'leaveBuySell' => 'gekocht of verkocht verlof',
		'loonbeslag' => 'loonbeslag',
		'bijtelling' => 'een bijtelling',
		'overtimePay' => 'overuren',
		'reimbursements' => 'declaraties',
	];

	/**
	 * The fewest earlier payslips a baseline needs.
	 */
	private const MIN_HISTORY = 3;

	/**
	 * The most earlier payslips a baseline uses.
	 */
	private const MAX_HISTORY = 6;

	/**
	 * Constructor.
	 *
	 * @param Percentile $percentile The median.
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-003
	 */
	public function __construct(
		private readonly Percentile $percentile = new Percentile(),
	) {
	}//end __construct()

	/**
	 * The deviation findings of one payslip.
	 *
	 * @param array<string, mixed>                                    $payslip      The payslip.
	 * @param list<array<string, mixed>>                              $history      The employee's paid payslips (any order; later ones are ignored).
	 * @param array<string, array{relative: float, absolute: float}> $thresholds   Overrides per component.
	 * @param bool                                                    $raiseApplied Whether a raise reached the salary in this period.
	 *
	 * @return list<array{kind: string, severity: string, component: string|null, currentValue: float|null, baselineValue: float|null, explanation: string|null, message: string}>
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-003
	 */
	public function detect(array $payslip, array $history, array $thresholds = [], bool $raiseApplied = false): array {
		$earlier = $this->earlier(payslip: $payslip, history: $history);
		if (count($earlier) < self::MIN_HISTORY) {
			return [[
				'kind' => 'deviation',
				'severity' => 'info',
				'component' => null,
				'currentValue' => null,
				'baselineValue' => null,
				'explanation' => null,
				'message' => 'Te weinig eerdere loonstroken (' . count($earlier) . ') om met de eigen geschiedenis te vergelijken.',
			]];
		}

		$explanation = $this->explanation(payslip: $payslip, earlier: $earlier, raiseApplied: $raiseApplied);
		$findings = [];
		foreach (self::DEFAULT_THRESHOLDS as $component => $default) {
			$finding = $this->componentFinding(component: $component, payslip: $payslip, earlier: $earlier, threshold: ($thresholds[$component] ?? $default), explanation: $explanation);
			if ($finding !== null) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}//end detect()

	/**
	 * One component's finding, or null when it is within its threshold or
	 * cannot be read.
	 *
	 * @param string                               $component   The component.
	 * @param array<string, mixed>                 $payslip     The payslip.
	 * @param list<array<string, mixed>>           $earlier     The baseline payslips.
	 * @param array{relative: float, absolute: float} $threshold The threshold.
	 * @param string|null                          $explanation The named cause, or null.
	 *
	 * @return array{kind: string, severity: string, component: string|null, currentValue: float|null, baselineValue: float|null, explanation: string|null, message: string}|null
	 */
	private function componentFinding(string $component, array $payslip, array $earlier, array $threshold, ?string $explanation): ?array {
		$current = self::valueOf(payslip: $payslip, component: $component);
		$values = [];
		foreach ($earlier as $previous) {
			$value = self::valueOf(payslip: $previous, component: $component);
			if ($value !== null) {
				$values[] = $value;
			}
		}

		if ($current === null || count($values) < self::MIN_HISTORY) {
			return null;
		}

		sort($values);
		$baseline = (float)$this->percentile->value($values, 50.0);
		if (abs($current - $baseline) <= max(((float)$threshold['relative']) * abs($baseline), (float)$threshold['absolute'])) {
			return null;
		}

		return [
			'kind' => 'deviation',
			'severity' => ($explanation === null ? 'warning' : 'info'),
			'component' => $component,
			'currentValue' => round($current, 2),
			'baselineValue' => round($baseline, 2),
			'explanation' => $explanation,
			'message' => sprintf('%s is %.2f, de mediaan van de eerdere loonstroken is %.2f.', $component, $current, $baseline),
		];
	}//end componentFinding()

	/**
	 * The six most recent payslips of periods before this one.
	 *
	 * @param array<string, mixed>       $payslip The payslip.
	 * @param list<array<string, mixed>> $history The history.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function earlier(array $payslip, array $history): array {
		$period = (string)($payslip['period'] ?? '');
		$earlier = array_values(array_filter($history, static fn (array $previous): bool => (string)($previous['period'] ?? '') !== '' && (string)$previous['period'] < $period));
		usort($earlier, static fn (array $a, array $b): int => strcmp((string)$b['period'], (string)$a['period']));

		return array_slice($earlier, 0, self::MAX_HISTORY);
	}//end earlier()

	/**
	 * The causes this payslip carries and none of the baseline did.
	 *
	 * @param array<string, mixed>       $payslip      The payslip.
	 * @param list<array<string, mixed>> $earlier      The baseline payslips.
	 * @param bool                       $raiseApplied Whether a raise was applied this period.
	 *
	 * @return string|null
	 */
	private function explanation(array $payslip, array $earlier, bool $raiseApplied): ?string {
		$causes = [];
		foreach (self::CAUSES as $field => $words) {
			if (self::carries(payslip: $payslip, field: $field) === true && array_filter($earlier, static fn (array $previous): bool => self::carries(payslip: $previous, field: $field)) === []) {
				$causes[] = $words;
			}
		}

		if ($raiseApplied === true) {
			$causes[] = 'een doorgevoerde loonsverhoging';
		}

		return ($causes === [] ? null : 'Verklaard door ' . implode(', ', $causes) . '.');
	}//end explanation()

	/**
	 * Whether a payslip carries a cause.
	 *
	 * @param array<string, mixed> $payslip The payslip.
	 * @param string               $field   The field.
	 *
	 * @return bool
	 */
	private static function carries(array $payslip, string $field): bool {
		$value = ($payslip[$field] ?? null);
		return $value !== null && $value !== '' && $value !== 0 && $value !== 0.0;
	}//end carries()

	/**
	 * A component's value on a payslip, or null.
	 *
	 * @param array<string, mixed> $payslip   The payslip.
	 * @param string               $component The component.
	 *
	 * @return float|null
	 */
	private static function valueOf(array $payslip, string $component): ?float {
		if ($component === 'employerCost') {
			$parts = [($payslip['werknemersverzekeringen'] ?? null), ($payslip['zvw'] ?? null)];
			$numeric = array_filter($parts, 'is_numeric');
			return ($numeric === [] ? null : (float)array_sum($numeric));
		}

		$value = ($payslip[$component] ?? ($component === 'hoursPaid' ? ($payslip['hoursWorked'] ?? null) : null));

		return (is_numeric($value) === true ? (float)$value : null);
	}//end valueOf()

}//end class
