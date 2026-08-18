<?php

/**
 * Unit tests for the NL offboarding checks.
 *
 * Pins the four offboarding-wizard-mvp predicates: transitievergoeding
 * presence for dismissal-initiated reasons at/past eindafrekening_gereed
 * (nl-offboarding-transitievergoeding, single-object), verlofsaldo payout at
 * afgerond (nl-offboarding-verlofsaldo-uitbetaling, cross-object via the
 * context's LeaveBalance index, incl. the vacuous no-balance-rows skip),
 * getuigschrift provision (nl-offboarding-getuigschrift, advisory), and
 * einddatum consistency (nl-offboarding-einddatum-consistentie, cross-object
 * via the context's Employee index, incl. fail-closed on a dangling
 * employeeId).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/offboarding-wizard-mvp/specs/offboarding-wizard/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlOffboardingChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlOffboardingChecks.
 *
 * @spec openspec/changes/offboarding-wizard-mvp/specs/offboarding-wizard/spec.md
 */
class NlOffboardingChecksTest extends TestCase {

	/**
	 * The registered Offboarding predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlOffboardingChecks::checks()['Offboarding'];

	}//end setUp()

	/**
	 * A minimal Offboarding fixture; each test overrides the fields it exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function offboarding(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'employee-jansen',
				'lastWorkingDay' => '2026-08-31',
				'reason' => 'opzegging-werkgever',
				'status' => 'aangekondigd',
				'exitGesprekDone' => null,
				'assetsIngeleverd' => false,
				'toegangIngetrokken' => false,
				'verlofsaldoUitbetaald' => false,
				'vakantiegeldAfgerekend' => false,
				'transitievergoedingBedrag' => null,
				'getuigschriftVerstrekt' => false,
				'notes' => null,
			],
			$overrides
		);

	}//end offboarding()

	/**
	 * A minimal `context['related']` fixture matching RuleAuditService's
	 * pre-pass shape.
	 *
	 * @param array<string, array<string, mixed>> $employeesById Employee index by id.
	 * @param array<string, array<int, array<string, mixed>>> $leaveBalancesByEmployeeId LeaveBalance index by employeeId.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $employeesById = [], array $leaveBalancesByEmployeeId = []): array {
		return [
			'related' => [
				'Employee' => ['byId' => $employeesById],
				'LeaveBalance' => ['byEmployeeId' => $leaveBalancesByEmployeeId],
			],
		];

	}//end context()

	// -- nl-offboarding-transitievergoeding -----------------------------------

	/**
	 * @return void
	 */
	public function testTransitievergoedingSatisfiedBeforeEindafrekeningGereed(): void {
		$case = $this->offboarding(['status' => 'afronding_gepland', 'transitievergoedingBedrag' => null]);

		$this->assertTrue(($this->checks['nl-offboarding-transitievergoeding'])($case));

	}//end testTransitievergoedingSatisfiedBeforeEindafrekeningGereed()

	/**
	 * @return void
	 */
	public function testTransitievergoedingViolatedWhenMissingAtEindafrekeningGereed(): void {
		$case = $this->offboarding(['status' => 'eindafrekening_gereed', 'reason' => 'opzegging-werkgever', 'transitievergoedingBedrag' => null]);

		$this->assertFalse(($this->checks['nl-offboarding-transitievergoeding'])($case));

	}//end testTransitievergoedingViolatedWhenMissingAtEindafrekeningGereed()

	/**
	 * @return void
	 */
	public function testTransitievergoedingViolatedWhenMissingAtAfgerondForEindeContract(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'reason' => 'einde-contract', 'transitievergoedingBedrag' => null]);

		$this->assertFalse(($this->checks['nl-offboarding-transitievergoeding'])($case));

	}//end testTransitievergoedingViolatedWhenMissingAtAfgerondForEindeContract()

	/**
	 * @return void
	 */
	public function testTransitievergoedingSatisfiedWhenRecordedAsZeroOrMore(): void {
		$case = $this->offboarding(['status' => 'eindafrekening_gereed', 'reason' => 'opzegging-werkgever', 'transitievergoedingBedrag' => 4200.50]);

		$this->assertTrue(($this->checks['nl-offboarding-transitievergoeding'])($case));

	}//end testTransitievergoedingSatisfiedWhenRecordedAsZeroOrMore()

	/**
	 * @return void
	 */
	public function testTransitievergoedingViolatedWhenNegative(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'reason' => 'opzegging-werkgever', 'transitievergoedingBedrag' => -1.0]);

		$this->assertFalse(($this->checks['nl-offboarding-transitievergoeding'])($case));

	}//end testTransitievergoedingViolatedWhenNegative()

	/**
	 * @return void
	 */
	public function testResignationNeverRequiresATransitievergoeding(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'reason' => 'opzegging-werknemer', 'transitievergoedingBedrag' => null]);

		$this->assertTrue(($this->checks['nl-offboarding-transitievergoeding'])($case));

	}//end testResignationNeverRequiresATransitievergoeding()

	/**
	 * @return void
	 */
	public function testPensionAndDeathNeverRequireATransitievergoeding(): void {
		$pension = $this->offboarding(['status' => 'afgerond', 'reason' => 'pensioen', 'transitievergoedingBedrag' => null]);
		$overleden = $this->offboarding(['status' => 'afgerond', 'reason' => 'overlijden', 'transitievergoedingBedrag' => null]);

		$this->assertTrue(($this->checks['nl-offboarding-transitievergoeding'])($pension));
		$this->assertTrue(($this->checks['nl-offboarding-transitievergoeding'])($overleden));

	}//end testPensionAndDeathNeverRequireATransitievergoeding()

	/**
	 * @return void
	 */
	public function testVsoNeverRequiresAStatutoryTransitievergoeding(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'reason' => 'vso', 'transitievergoedingBedrag' => null]);

		$this->assertTrue(($this->checks['nl-offboarding-transitievergoeding'])($case));

	}//end testVsoNeverRequiresAStatutoryTransitievergoeding()

	// -- nl-offboarding-verlofsaldo-uitbetaling -------------------------------

	/**
	 * @return void
	 */
	public function testVerlofsaldoSatisfiedBeforeAfgerond(): void {
		$case = $this->offboarding(['status' => 'eindafrekening_gereed', 'verlofsaldoUitbetaald' => false]);

		$this->assertTrue(($this->checks['nl-offboarding-verlofsaldo-uitbetaling'])($case, $this->context()));

	}//end testVerlofsaldoSatisfiedBeforeAfgerond()

	/**
	 * @return void
	 */
	public function testVerlofsaldoViolatedWhenOpenBalanceAndNotPaid(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'verlofsaldoUitbetaald' => false]);
		$context = $this->context(
			[],
			['employee-jansen' => [['leaveType' => 'holiday', 'year' => 2026, 'entitledHours' => 160, 'bovenwettelijkHours' => 40, 'usedHours' => 56]]]
		);

		$this->assertFalse(($this->checks['nl-offboarding-verlofsaldo-uitbetaling'])($case, $context));

	}//end testVerlofsaldoViolatedWhenOpenBalanceAndNotPaid()

	/**
	 * @return void
	 */
	public function testVerlofsaldoSatisfiedWhenOpenBalanceButPaid(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'verlofsaldoUitbetaald' => true]);
		$context = $this->context(
			[],
			['employee-jansen' => [['leaveType' => 'holiday', 'year' => 2026, 'entitledHours' => 160, 'bovenwettelijkHours' => 40, 'usedHours' => 56]]]
		);

		$this->assertTrue(($this->checks['nl-offboarding-verlofsaldo-uitbetaling'])($case, $context));

	}//end testVerlofsaldoSatisfiedWhenOpenBalanceButPaid()

	/**
	 * @return void
	 */
	public function testVerlofsaldoSkipsWhenNoBalanceRowsResolve(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'verlofsaldoUitbetaald' => false]);

		$this->assertTrue(($this->checks['nl-offboarding-verlofsaldo-uitbetaling'])($case, $this->context()));

	}//end testVerlofsaldoSkipsWhenNoBalanceRowsResolve()

	/**
	 * @return void
	 */
	public function testVerlofsaldoSatisfiedWhenBalanceIsFullyDepleted(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'verlofsaldoUitbetaald' => false]);
		$context = $this->context(
			[],
			['employee-jansen' => [['leaveType' => 'holiday', 'year' => 2026, 'entitledHours' => 160, 'bovenwettelijkHours' => 0, 'usedHours' => 160]]]
		);

		$this->assertTrue(($this->checks['nl-offboarding-verlofsaldo-uitbetaling'])($case, $context));

	}//end testVerlofsaldoSatisfiedWhenBalanceIsFullyDepleted()

	/**
	 * @return void
	 */
	public function testVerlofsaldoSumsMultipleRowsAcrossLeaveTypes(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'verlofsaldoUitbetaald' => false]);
		$context = $this->context(
			[],
			[
				'employee-jansen' => [
					['leaveType' => 'holiday', 'year' => 2026, 'entitledHours' => 160, 'bovenwettelijkHours' => 0, 'usedHours' => 160],
					['leaveType' => 'special', 'year' => 2026, 'entitledHours' => 8, 'bovenwettelijkHours' => 0, 'usedHours' => 0],
				],
			]
		);

		$this->assertFalse(($this->checks['nl-offboarding-verlofsaldo-uitbetaling'])($case, $context));

	}//end testVerlofsaldoSumsMultipleRowsAcrossLeaveTypes()

	// -- nl-offboarding-getuigschrift ------------------------------------------

	/**
	 * @return void
	 */
	public function testGetuigschriftSatisfiedBeforeAfgerond(): void {
		$case = $this->offboarding(['status' => 'eindafrekening_gereed', 'getuigschriftVerstrekt' => false]);

		$this->assertTrue(($this->checks['nl-offboarding-getuigschrift'])($case));

	}//end testGetuigschriftSatisfiedBeforeAfgerond()

	/**
	 * @return void
	 */
	public function testGetuigschriftViolatedWhenAfgerondWithoutOne(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'getuigschriftVerstrekt' => false]);

		$this->assertFalse(($this->checks['nl-offboarding-getuigschrift'])($case));

	}//end testGetuigschriftViolatedWhenAfgerondWithoutOne()

	/**
	 * @return void
	 */
	public function testGetuigschriftSatisfiedWhenProvided(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'getuigschriftVerstrekt' => true]);

		$this->assertTrue(($this->checks['nl-offboarding-getuigschrift'])($case));

	}//end testGetuigschriftSatisfiedWhenProvided()

	// -- nl-offboarding-einddatum-consistentie ---------------------------------

	/**
	 * @return void
	 */
	public function testEinddatumSatisfiedBeforeAfgerond(): void {
		$case = $this->offboarding(['status' => 'eindafrekening_gereed', 'lastWorkingDay' => '2026-08-31']);

		$this->assertTrue(($this->checks['nl-offboarding-einddatum-consistentie'])($case, $this->context()));

	}//end testEinddatumSatisfiedBeforeAfgerond()

	/**
	 * @return void
	 */
	public function testEinddatumViolatedWhenEmployeeEndDateMismatches(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'lastWorkingDay' => '2026-05-31']);
		$context = $this->context(['employee-jansen' => ['endDate' => '2026-06-30']]);

		$this->assertFalse(($this->checks['nl-offboarding-einddatum-consistentie'])($case, $context));

	}//end testEinddatumViolatedWhenEmployeeEndDateMismatches()

	/**
	 * @return void
	 */
	public function testEinddatumViolatedWhenEmployeeEndDateIsMissing(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'lastWorkingDay' => '2026-05-31']);
		$context = $this->context(['employee-jansen' => ['endDate' => '']]);

		$this->assertFalse(($this->checks['nl-offboarding-einddatum-consistentie'])($case, $context));

	}//end testEinddatumViolatedWhenEmployeeEndDateIsMissing()

	/**
	 * @return void
	 */
	public function testEinddatumSatisfiedWhenEmployeeEndDateMatches(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'lastWorkingDay' => '2026-05-31']);
		$context = $this->context(['employee-jansen' => ['endDate' => '2026-05-31']]);

		$this->assertTrue(($this->checks['nl-offboarding-einddatum-consistentie'])($case, $context));

	}//end testEinddatumSatisfiedWhenEmployeeEndDateMatches()

	/**
	 * @return void
	 */
	public function testEinddatumFailsClosedWhenEmployeeIdDangling(): void {
		$case = $this->offboarding(['status' => 'afgerond', 'employeeId' => 'no-such-employee', 'lastWorkingDay' => '2026-05-31']);

		$this->assertFalse(($this->checks['nl-offboarding-einddatum-consistentie'])($case, $this->context()));

	}//end testEinddatumFailsClosedWhenEmployeeIdDangling()

}//end class
