<?php

/**
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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
 * Unit tests for PayAnomalyDetector: the median of the employee's last six
 * paid payslips, a threshold per component, and a named cause.
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayAnomalyDetector;
use PHPUnit\Framework\TestCase;

/**
 * Deviations from the employee's own history.
 */
class PayAnomalyDetectorTest extends TestCase {

	/**
	 * A payslip of the given period and net.
	 *
	 * @param string               $period    The period.
	 * @param float                $net       The net pay.
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function payslip(string $period, float $net, array $overrides = []): array {
		return array_merge(['employeeId' => 'emp-1', 'period' => $period, 'grossPay' => 3800.0, 'nettoPay' => $net, 'loonheffing' => 700.0, 'werknemersverzekeringen' => 100.0, 'zvw' => 250.0], $overrides);
	}//end payslip()

	/**
	 * Four months of a steady 2800 net.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function history(): array {
		return [$this->payslip('2026-01', 2800.0), $this->payslip('2026-02', 2810.0), $this->payslip('2026-03', 2790.0), $this->payslip('2026-04', 2800.0)];
	}//end history()

	/**
	 * A net pay twice the usual is a warning that names the component, the
	 * current value and the baseline; steady components raise nothing.
	 *
	 * @return void
	 */
	public function testATwiceTheUsualNetIsAWarning(): void {
		$findings = (new PayAnomalyDetector())->detect(payslip: $this->payslip('2026-05', 5600.0), history: $this->history());

		$this->assertCount(1, $findings);
		$this->assertSame(['deviation', 'warning', 'nettoPay', 5600.0, 2800.0, null], [$findings[0]['kind'], $findings[0]['severity'], $findings[0]['component'], $findings[0]['currentValue'], $findings[0]['baselineValue'], $findings[0]['explanation']]);
		$this->assertNotSame('', $findings[0]['message']);
	}//end testATwiceTheUsualNetIsAWarning()

	/**
	 * The same deviation with a retro correction the history lacks is
	 * explained and drops to info; so is an applied raise.
	 *
	 * @return void
	 */
	public function testAKnownCauseExplainsTheDeviation(): void {
		$detector = new PayAnomalyDetector();

		$retro = $detector->detect(payslip: $this->payslip('2026-05', 5600.0, ['retroAdjustment' => 2800.0]), history: $this->history());
		$this->assertSame('info', $retro[0]['severity']);
		$this->assertNotNull($retro[0]['explanation']);

		$raise = $detector->detect(payslip: $this->payslip('2026-05', 5600.0), history: $this->history(), raiseApplied: true);
		$this->assertSame('info', $raise[0]['severity']);
	}//end testAKnownCauseExplainsTheDeviation()

	/**
	 * Two prior payslips are not enough history: one info finding.
	 *
	 * @return void
	 */
	public function testTwoPriorPayslipsAreNotEnoughHistory(): void {
		$findings = (new PayAnomalyDetector())->detect(payslip: $this->payslip('2026-05', 5600.0), history: array_slice($this->history(), 0, 2));

		$this->assertCount(1, $findings);
		$this->assertSame(['deviation', 'info', null], [$findings[0]['kind'], $findings[0]['severity'], $findings[0]['component']]);
	}//end testTwoPriorPayslipsAreNotEnoughHistory()

	/**
	 * Only the six most recent earlier payslips count, a later one never
	 * does, and a small change under the absolute floor is not flagged.
	 *
	 * @return void
	 */
	public function testTheBaselineIsTheLastSixEarlierPayslips(): void {
		$history = [$this->payslip('2025-01', 9000.0), $this->payslip('2025-02', 9000.0), $this->payslip('2025-03', 9000.0)];
		foreach (['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'] as $period) {
			$history[] = $this->payslip($period, 2800.0);
		}

		$history[] = $this->payslip('2026-09', 9000.0);

		$this->assertSame([], (new PayAnomalyDetector())->detect(payslip: $this->payslip('2026-05', 2840.0), history: $history));
	}//end testTheBaselineIsTheLastSixEarlierPayslips()

	/**
	 * A threshold per component is a setting: a tighter net threshold flags
	 * what the default lets pass.
	 *
	 * @return void
	 */
	public function testThresholdsAreSettings(): void {
		$findings = (new PayAnomalyDetector())->detect(payslip: $this->payslip('2026-05', 2900.0), history: $this->history(), thresholds: ['nettoPay' => ['relative' => 0.01, 'absolute' => 10.0]]);

		$this->assertSame(['nettoPay'], array_column($findings, 'component'));
	}//end testThresholdsAreSettings()

}//end class
