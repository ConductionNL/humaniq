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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * A median and a threshold anyone can recompute; no I/O.
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-003
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
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-003
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
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-003
	 */
	public function detect(array $payslip, array $history, array $thresholds = [], bool $raiseApplied = false): array {
		$earlier = $this->earlier(payslip: $payslip, history: $history);
		if (count($earlier) < self::MIN_HISTORY) {
			return [$this->tooLittleHistory(count: count($earlier))];
		}

		$check = new PayAnomalyCheck();
		$check->payslip = $payslip;
		$check->earlier = $earlier;
		$check->thresholds = $thresholds;
		$check->explanation = $this->explanation(check: $check, raiseApplied: $raiseApplied);

		return $this->findings(check: $check);
	}//end detect()

	/**
	 * The finding that there is too little history to compare with.
	 *
	 * @param int $count The number of earlier payslips.
	 *
	 * @return array{kind: string, severity: string, component: string|null, currentValue: float|null, baselineValue: float|null, explanation: string|null, message: string}
	 */
	private function tooLittleHistory(int $count): array {
		return [
			'kind' => 'deviation',
			'severity' => 'info',
			'component' => null,
			'currentValue' => null,
			'baselineValue' => null,
			'explanation' => null,
			'message' => 'Te weinig eerdere loonstroken (' . $count . ') om met de eigen geschiedenis te vergelijken.',
		];
	}//end tooLittleHistory()

	/**
	 * The finding of every component outside its threshold, in component order.
	 *
	 * @param PayAnomalyCheck $check The payslip being checked.
	 *
	 * @return list<array{kind: string, severity: string, component: string|null, currentValue: float|null, baselineValue: float|null, explanation: string|null, message: string}>
	 */
	private function findings(PayAnomalyCheck $check): array {
		$findings = [];
		foreach (array_keys(self::DEFAULT_THRESHOLDS) as $component) {
			$finding = $this->componentFinding(check: $check, component: $component);
			if ($finding !== null) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}//end findings()

	/**
	 * Threshold overrides from the `payroll_check_thresholds` setting: JSON
	 * keyed by component, each with a numeric `relative` share and
	 * `absolute` floor. An unknown component, a non-numeric value or a
	 * setting that is not JSON is ignored, so the defaults apply.
	 *
	 * @param string $setting The setting's value.
	 *
	 * @return array<string, array{relative: float, absolute: float}>
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-003
	 */
	public function thresholdsFrom(string $setting): array {
		$decoded = json_decode($setting, true);
		$thresholds = [];
		foreach (array_keys(self::DEFAULT_THRESHOLDS) as $component) {
			$entry = (is_array($decoded) === true ? ($decoded[$component] ?? null) : null);
			if (is_array($entry) === true && is_numeric($entry['relative'] ?? null) === true && is_numeric($entry['absolute'] ?? null) === true) {
				$thresholds[$component] = ['relative' => (float)$entry['relative'], 'absolute' => (float)$entry['absolute']];
			}
		}

		return $thresholds;
	}//end thresholdsFrom()

	/**
	 * One component's finding, or null when it is within its threshold or
	 * cannot be read.
	 *
	 * @param PayAnomalyCheck $check     The payslip, its baseline, the thresholds and the named cause (or null).
	 * @param string          $component The component.
	 *
	 * @return array{kind: string, severity: string, component: string|null, currentValue: float|null, baselineValue: float|null, explanation: string|null, message: string}|null
	 */
	private function componentFinding(PayAnomalyCheck $check, string $component): ?array {
		$current = self::valueOf(payslip: $check->payslip, component: $component);
		$values = self::readable(component: $component, payslips: $check->earlier);
		if ($current === null || count($values) < self::MIN_HISTORY) {
			return null;
		}

		sort($values);
		$reading = ['component' => $component, 'current' => $current, 'baseline' => (float)$this->percentile->value($values, 50.0)];
		if ($this->withinThreshold(check: $check, reading: $reading) === true) {
			return null;
		}

		return $this->deviation(check: $check, reading: $reading);
	}//end componentFinding()

	/**
	 * Whether a reading is within its component's threshold: the relative
	 * share of the baseline or the absolute floor, whichever is larger.
	 *
	 * @param PayAnomalyCheck                                            $check   The threshold overrides.
	 * @param array{component: string, current: float, baseline: float} $reading The component, its current value and its baseline.
	 *
	 * @return bool
	 */
	private function withinThreshold(PayAnomalyCheck $check, array $reading): bool {
		$threshold = ($check->thresholds[$reading['component']] ?? self::DEFAULT_THRESHOLDS[$reading['component']]);

		return abs($reading['current'] - $reading['baseline']) <= max(((float)$threshold['relative']) * abs($reading['baseline']), (float)$threshold['absolute']);
	}//end withinThreshold()

	/**
	 * The deviation finding of a reading outside its threshold; a warning
	 * unless a cause explains it.
	 *
	 * @param PayAnomalyCheck                                            $check   The named cause (or null).
	 * @param array{component: string, current: float, baseline: float} $reading The component, its current value and its baseline.
	 *
	 * @return array{kind: string, severity: string, component: string|null, currentValue: float|null, baselineValue: float|null, explanation: string|null, message: string}
	 */
	private function deviation(PayAnomalyCheck $check, array $reading): array {
		return [
			'kind' => 'deviation',
			'severity' => ($check->explanation === null ? 'warning' : 'info'),
			'component' => $reading['component'],
			'currentValue' => round($reading['current'], 2),
			'baselineValue' => round($reading['baseline'], 2),
			'explanation' => $check->explanation,
			'message' => sprintf('%s is %.2f, de mediaan van de eerdere loonstroken is %.2f.', $reading['component'], $reading['current'], $reading['baseline']),
		];
	}//end deviation()

	/**
	 * The component's readable values on the given payslips, in their order.
	 *
	 * @param string                     $component The component.
	 * @param list<array<string, mixed>> $payslips  The payslips.
	 *
	 * @return list<float>
	 */
	private static function readable(string $component, array $payslips): array {
		$values = [];
		foreach ($payslips as $payslip) {
			$value = self::valueOf(payslip: $payslip, component: $component);
			if ($value !== null) {
				$values[] = $value;
			}
		}

		return $values;
	}//end readable()

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
	 * @param PayAnomalyCheck $check        The payslip and its baseline.
	 * @param bool            $raiseApplied Whether a raise was applied this period.
	 *
	 * @return string|null
	 */
	private function explanation(PayAnomalyCheck $check, bool $raiseApplied): ?string {
		$causes = [];
		foreach (self::CAUSES as $field => $words) {
			if ($this->isNewCause(check: $check, field: $field) === true) {
				$causes[] = $words;
			}
		}

		if ($raiseApplied === true) {
			$causes[] = 'een doorgevoerde loonsverhoging';
		}

		return self::explained(causes: $causes);
	}//end explanation()

	/**
	 * The sentence that names the causes, or null when there are none.
	 *
	 * @param list<string> $causes The causes, in words.
	 *
	 * @return string|null
	 */
	private static function explained(array $causes): ?string {
		return ($causes === [] ? null : 'Verklaard door ' . implode(', ', $causes) . '.');
	}//end explained()

	/**
	 * Whether the payslip carries a cause none of the baseline payslips did.
	 *
	 * @param PayAnomalyCheck $check The payslip and its baseline.
	 * @param string          $field The cause's field.
	 *
	 * @return bool
	 */
	private function isNewCause(PayAnomalyCheck $check, string $field): bool {
		return self::carries(payslip: $check->payslip, field: $field) === true
			&& array_filter($check->earlier, static fn (array $previous): bool => self::carries(payslip: $previous, field: $field)) === [];
	}//end isNewCause()

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
