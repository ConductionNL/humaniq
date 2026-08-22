<?php

/**
 * Unit tests for the NL onboarding checks.
 *
 * Pins the three onboarding-wizard-mvp predicates: WID identity-check timing
 * (nl-onboarding-wid-check, single-object), proeftijd BW 7:652 contract-type
 * cap plus overdue-unclosed (nl-onboarding-proeftijd-bewaking, cross-object via
 * the context's EmploymentContract index), and loonheffingenverklaring
 * presence (nl-onboarding-loonheffingenverklaring, cross-object via the
 * context's Employee index).
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/onboarding-wizard-mvp/specs/onboarding-wizard/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlOnboardingChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlOnboardingChecks.
 *
 * @spec openspec/changes/onboarding-wizard-mvp/specs/onboarding-wizard/spec.md
 */
class NlOnboardingChecksTest extends TestCase {

	/**
	 * The registered Onboarding predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlOnboardingChecks::checks()['Onboarding'];

	}//end setUp()

	/**
	 * A minimal Onboarding fixture; each test overrides the fields it exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function onboarding(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'employee-visser',
				'startDate' => '2026-07-01',
				'proeftijdEndDate' => null,
				'status' => 'aangenomen',
				'contractSigned' => false,
				'widCheckDone' => false,
				'widCheckDate' => null,
				'bsnValidated' => false,
				'ibanVerified' => false,
				'itProvisioned' => false,
				'pensioenAangemeld' => false,
				'notes' => null,
			],
			$overrides
		);

	}//end onboarding()

	/**
	 * A minimal `context['related']` fixture matching RuleAuditService's
	 * pre-pass shape.
	 *
	 * @param array<string, array<string, mixed>> $employeesById Employee index by id.
	 * @param array<string, array<string, mixed>> $contractsByEmployeeId EmploymentContract index by employeeId.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $employeesById = [], array $contractsByEmployeeId = []): array {
		return [
			'related' => [
				'Employee' => ['byId' => $employeesById],
				'EmploymentContract' => ['byEmployeeId' => $contractsByEmployeeId],
			],
		];

	}//end context()

	// -- nl-onboarding-wid-check ---------------------------------------------

	/**
	 * @return void
	 */
	public function testWidCheckSatisfiedWhenAlreadyDone(): void {
		$case = $this->onboarding(['widCheckDone' => true, 'startDate' => '2020-01-01', 'status' => 'proeftijd_lopend']);

		$this->assertTrue(($this->checks['nl-onboarding-wid-check'])($case));

	}//end testWidCheckSatisfiedWhenAlreadyDone()

	/**
	 * @return void
	 */
	public function testWidCheckNeverFlagsACancelledCase(): void {
		$case = $this->onboarding(['status' => 'geannuleerd', 'widCheckDone' => false, 'startDate' => '2020-01-01']);

		$this->assertTrue(($this->checks['nl-onboarding-wid-check'])($case));

	}//end testWidCheckNeverFlagsACancelledCase()

	/**
	 * @return void
	 */
	public function testWidCheckViolatedWhenStartDateHasPassedAndNotDone(): void {
		$case = $this->onboarding(['status' => 'gegevens_gevalideerd', 'widCheckDone' => false, 'startDate' => '2020-01-01']);

		$this->assertFalse(($this->checks['nl-onboarding-wid-check'])($case));

	}//end testWidCheckViolatedWhenStartDateHasPassedAndNotDone()

	/**
	 * @return void
	 */
	public function testWidCheckViolatedWhenReadyForFirstWorkdayAndNotDone(): void {
		$case = $this->onboarding(
			[
				'status' => 'gereed_eerste_werkdag',
				'widCheckDone' => false,
				'startDate' => date('Y-m-d', strtotime('+30 days')),
			]
		);

		$this->assertFalse(($this->checks['nl-onboarding-wid-check'])($case));

	}//end testWidCheckViolatedWhenReadyForFirstWorkdayAndNotDone()

	/**
	 * @return void
	 */
	public function testWidCheckSatisfiedWhenStartDateFutureAndStatusEarly(): void {
		$case = $this->onboarding(
			[
				'status' => 'contract_getekend',
				'widCheckDone' => false,
				'startDate' => date('Y-m-d', strtotime('+30 days')),
			]
		);

		$this->assertTrue(($this->checks['nl-onboarding-wid-check'])($case));

	}//end testWidCheckSatisfiedWhenStartDateFutureAndStatusEarly()

	// -- nl-onboarding-proeftijd-bewaking -------------------------------------

	/**
	 * @return void
	 */
	public function testProeftijdSatisfiedWhenNoContractResolves(): void {
		$case = $this->onboarding(['proeftijdEndDate' => '2026-12-01', 'status' => 'gegevens_gevalideerd']);

		$this->assertTrue(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $this->context()));

	}//end testProeftijdSatisfiedWhenNoContractResolves()

	/**
	 * @return void
	 */
	public function testProeftijdSatisfiedWhenFixedTermWithinOneMonthCap(): void {
		$case = $this->onboarding(['proeftijdEndDate' => '2026-08-01', 'status' => 'contract_getekend']);
		$context = $this->context(
			[],
			['employee-visser' => ['type' => 'temporary', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31']]
		);

		$this->assertTrue(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $context));

	}//end testProeftijdSatisfiedWhenFixedTermWithinOneMonthCap()

	/**
	 * @return void
	 */
	public function testProeftijdViolatedWhenFixedTermExceedsOneMonthCap(): void {
		$case = $this->onboarding(['proeftijdEndDate' => '2026-09-01', 'status' => 'contract_getekend']);
		$context = $this->context(
			[],
			['employee-visser' => ['type' => 'temporary', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31']]
		);

		$this->assertFalse(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $context));

	}//end testProeftijdViolatedWhenFixedTermExceedsOneMonthCap()

	/**
	 * @return void
	 */
	public function testProeftijdSatisfiedWhenPermanentWithinTwoMonthCap(): void {
		$case = $this->onboarding(['proeftijdEndDate' => '2026-09-01', 'status' => 'contract_getekend']);
		$context = $this->context([], ['employee-visser' => ['type' => 'permanent', 'startDate' => '2026-07-01', 'endDate' => '']]);

		$this->assertTrue(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $context));

	}//end testProeftijdSatisfiedWhenPermanentWithinTwoMonthCap()

	/**
	 * @return void
	 */
	public function testProeftijdViolatedWhenPermanentExceedsTwoMonthCap(): void {
		$case = $this->onboarding(['proeftijdEndDate' => '2026-09-15', 'status' => 'contract_getekend']);
		$context = $this->context([], ['employee-visser' => ['type' => 'permanent', 'startDate' => '2026-07-01', 'endDate' => '']]);

		$this->assertFalse(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $context));

	}//end testProeftijdViolatedWhenPermanentExceedsTwoMonthCap()

	/**
	 * @return void
	 */
	public function testProeftijdViolatedWhenLongFixedTermExceedsTwoMonthCap(): void {
		// A 3-year fixed-term contract (>= 2 years) takes the 2-month cap, same
		// as permanent.
		$case = $this->onboarding(['proeftijdEndDate' => '2026-09-15', 'status' => 'contract_getekend']);
		$context = $this->context(
			[],
			['employee-visser' => ['type' => 'temporary', 'startDate' => '2026-07-01', 'endDate' => '2029-07-01']]
		);

		$this->assertFalse(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $context));

	}//end testProeftijdViolatedWhenLongFixedTermExceedsTwoMonthCap()

	/**
	 * @return void
	 */
	public function testProeftijdViolatedWhenRunningAndPastEndDateUnclosed(): void {
		$case = $this->onboarding(['status' => 'proeftijd_lopend', 'proeftijdEndDate' => '2020-01-01']);

		$this->assertFalse(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $this->context()));

	}//end testProeftijdViolatedWhenRunningAndPastEndDateUnclosed()

	/**
	 * @return void
	 */
	public function testProeftijdSatisfiedWhenRunningAndEndDateInFuture(): void {
		$case = $this->onboarding(['status' => 'proeftijd_lopend', 'proeftijdEndDate' => date('Y-m-d', strtotime('+30 days'))]);

		$this->assertTrue(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $this->context()));

	}//end testProeftijdSatisfiedWhenRunningAndEndDateInFuture()

	/**
	 * @return void
	 */
	public function testProeftijdSatisfiedWhenAfgerondEvenIfEndDateIsPast(): void {
		$case = $this->onboarding(['status' => 'afgerond', 'proeftijdEndDate' => '2020-01-01']);

		$this->assertTrue(($this->checks['nl-onboarding-proeftijd-bewaking'])($case, $this->context()));

	}//end testProeftijdSatisfiedWhenAfgerondEvenIfEndDateIsPast()

	// -- nl-onboarding-loonheffingenverklaring --------------------------------

	/**
	 * @return void
	 */
	public function testLoonheffingenverklaringSatisfiedBeforeReadyStatus(): void {
		$case = $this->onboarding(['status' => 'aangenomen']);

		$this->assertTrue(($this->checks['nl-onboarding-loonheffingenverklaring'])($case, $this->context()));

	}//end testLoonheffingenverklaringSatisfiedBeforeReadyStatus()

	/**
	 * @return void
	 */
	public function testLoonheffingenverklaringViolatedWhenReadyAndEmployeeLacksVerklaring(): void {
		$case = $this->onboarding(['status' => 'gereed_eerste_werkdag']);
		$context = $this->context(['employee-visser' => ['loonheffingenVerklaringOnFile' => false, 'startDate' => '2026-07-01']]);

		$this->assertFalse(($this->checks['nl-onboarding-loonheffingenverklaring'])($case, $context));

	}//end testLoonheffingenverklaringViolatedWhenReadyAndEmployeeLacksVerklaring()

	/**
	 * @return void
	 */
	public function testLoonheffingenverklaringSatisfiedWhenReadyAndEmployeeHasVerklaring(): void {
		$case = $this->onboarding(['status' => 'proeftijd_lopend']);
		$context = $this->context(['employee-visser' => ['loonheffingenVerklaringOnFile' => true, 'startDate' => '2026-07-01']]);

		$this->assertTrue(($this->checks['nl-onboarding-loonheffingenverklaring'])($case, $context));

	}//end testLoonheffingenverklaringSatisfiedWhenReadyAndEmployeeHasVerklaring()

	/**
	 * @return void
	 */
	public function testLoonheffingenverklaringFailsClosedWhenEmployeeIdDangling(): void {
		$case = $this->onboarding(['status' => 'afgerond', 'employeeId' => 'no-such-employee']);

		$this->assertFalse(($this->checks['nl-onboarding-loonheffingenverklaring'])($case, $this->context()));

	}//end testLoonheffingenverklaringFailsClosedWhenEmployeeIdDangling()

}//end class
