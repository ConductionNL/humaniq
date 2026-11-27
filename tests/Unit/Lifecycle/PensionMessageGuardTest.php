<?php

/**
 * PensionMessageGuardTest
 *
 * Checking a pension filing: refused while the run is not approved, refused
 * naming an employee in the scheme who cannot be reported, allowed when every
 * employee in the scheme can be, and allowed for a fund without a scheme.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\PensionMessageGuard;
use OCA\Humaniq\Service\PensionMessageService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use PHPUnit\Framework\TestCase;

/**
 * PensionMessageGuard over a stubbed run rule and message service.
 *
 * @covers \OCA\Humaniq\Lifecycle\PensionMessageGuard
 *
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */
class PensionMessageGuardTest extends TestCase {

	/**
	 * The June pfab filing.
	 *
	 * @var array<string, mixed>
	 */
	private const FILING = ['id' => 'f-1', 'period' => '2026-06', 'fund' => 'pfab', 'payrollRunId' => 'run-06', 'administrationId' => 'ADM-001'];

	/**
	 * The guard, with the run rule answering $run and the service answering $outcome.
	 *
	 * @param GuardResult                $run     What the approved-run rule answers.
	 * @param array<string, mixed>|null  $outcome What lines() answers, or null when it must not be asked.
	 * @param \Throwable|null            $throws  What lines() throws instead.
	 *
	 * @return PensionMessageGuard
	 */
	private function guard(GuardResult $run, ?array $outcome, ?\Throwable $throws=null): PensionMessageGuard {
		$runApproved = $this->createMock(LifecycleGuardInterface::class);
		$runApproved->method('check')->willReturn($run);

		$messages = $this->createMock(PensionMessageService::class);
		if ($throws !== null) {
			$messages->expects($this->once())->method('lines')->willThrowException($throws);
		} else if ($outcome === null) {
			$messages->expects($this->never())->method('lines');
		} else {
			$messages->expects($this->once())->method('lines')->with(self::FILING)->willReturn($outcome);
		}

		return new PensionMessageGuard(runApproved: $runApproved, messages: $messages);
	}

	/**
	 * A scheme of fund pfab, as lines() returns it.
	 *
	 * @var array<string, mixed>
	 */
	private const SCHEME = ['fund' => 'pfab', 'messageFormat' => 'upa'];

	/**
	 * A run that is not approved refuses with the run rule's own message,
	 * before any figure is read.
	 *
	 * @return void
	 */
	public function testARunThatIsNotApprovedRefusesWithTheRunRulesMessage(): void {
		$result = $this->guard(GuardResult::deny('De loonrun heeft status "calculated".'), null)->check(self::FILING, 'controleren', 'hr');

		$this->assertFalse($result->isAllowed());
		$this->assertSame('De loonrun heeft status "calculated".', $result->getMessage());
	}

	/**
	 * An employee in the scheme whose contract has no hours per week refuses
	 * the check and is named with the missing part-time factor.
	 *
	 * @return void
	 */
	public function testAnEmployeeWithoutHoursPerWeekRefusesTheCheckAndIsNamed(): void {
		$outcome = [
			'scheme' => self::SCHEME,
			'messageFormat' => 'upa',
			'lines' => [['employeeId' => 'e-1']],
			'findings' => [
				['kind' => 'no-part-time-factor', 'severity' => 'blocking', 'employeeId' => 'e-2', 'problem' => 'Bram Smit heeft een arbeidsovereenkomst zonder uren per week, dus de deeltijdfactor ontbreekt.'],
				['kind' => 'no-contract', 'severity' => 'blocking', 'employeeId' => 'e-3', 'problem' => 'Cas Bos heeft geen arbeidsovereenkomst in deze periode, dus de deeltijdfactor ontbreekt.'],
			],
		];

		$result = $this->guard(GuardResult::allow(), $outcome)->check(self::FILING, 'controleren', 'hr');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('Bram Smit heeft een arbeidsovereenkomst zonder uren per week', (string)$result->getMessage());
		$this->assertStringContainsString('Cas Bos heeft geen arbeidsovereenkomst', (string)$result->getMessage());
	}

	/**
	 * Every employee in the scheme reportable: the check is allowed.
	 *
	 * @return void
	 */
	public function testEveryEmployeeReportableAllowsTheCheck(): void {
		$outcome = ['scheme' => self::SCHEME, 'messageFormat' => 'upa', 'lines' => [['employeeId' => 'e-1']], 'findings' => []];

		$this->assertTrue($this->guard(GuardResult::allow(), $outcome)->check(self::FILING, 'controleren', 'hr')->isAllowed());
	}

	/**
	 * A fund without a scheme in the period is still delivered outside
	 * humaniq, so its check passes as before.
	 *
	 * @return void
	 */
	public function testAFundWithoutASchemePassesAsBefore(): void {
		$outcome = [
			'scheme' => null,
			'messageFormat' => '',
			'lines' => [],
			'findings' => [['kind' => 'no-scheme', 'severity' => 'blocking', 'employeeId' => '', 'problem' => 'Er is geen pensioenregeling voor fonds pfab die geldt in 2026-06.']],
		];

		$this->assertTrue($this->guard(GuardResult::allow(), $outcome)->check(self::FILING, 'controleren', 'hr')->isAllowed());
	}

	/**
	 * A read that fails refuses rather than allowing on a guess.
	 *
	 * @return void
	 */
	public function testAFailingReadRefuses(): void {
		$result = $this->guard(GuardResult::allow(), null, new \RuntimeException('register down'))->check(self::FILING, 'controleren', 'hr');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('geweigerd', (string)$result->getMessage());
	}
}//end class
