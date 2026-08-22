<?php

/**
 * Unit tests for the NL HR-signals checks.
 *
 * Pins the three hr-signals predicates: the contract-expiry successor signal
 * (nl-signaal-contract-verloopt, cross-object via the context's
 * signals.contractsByEmployeeId full-list index), the statutory
 * aanzegtermijn (nl-aanzegtermijn-bewaking, object-local), and the
 * BHV-certificate expiry signal (nl-bhv-certificaat-verloopt, object-local,
 * bhv-organisatie). All three read `new \DateTimeImmutable('today')`, so
 * every time-sensitive fixture here uses relative offsets
 * (`date('Y-m-d', strtotime(...))`) rather than hardcoded calendar dates --
 * the same convention NlOnboardingChecksTest uses for its overdue-proeftijd
 * cases.
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
 * @spec openspec/changes/hr-signals/specs/hr-signals/spec.md
 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlSignalChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlSignalChecks.
 *
 * @spec openspec/changes/hr-signals/specs/hr-signals/spec.md
 */
class NlSignalChecksTest extends TestCase {

	/**
	 * The registered EmploymentContract predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * The registered BhvCertificering predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $bhvChecks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$all = NlSignalChecks::checks();
		$this->checks = $all['EmploymentContract'];
		$this->bhvChecks = $all['BhvCertificering'];

	}//end setUp()

	/**
	 * A minimal EmploymentContract fixture; each test overrides the fields it
	 * exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'contract-x',
				'employeeId' => 'employee-x',
				'type' => 'temporary',
				'startDate' => '2025-01-01',
				'endDate' => date('Y-m-d', strtotime('+19 days')),
				'aanzegdOn' => null,
			],
			$overrides
		);

	}//end contract()

	/**
	 * A minimal `context['signals']` fixture matching
	 * RuleAuditService::buildSignalsContext()'s shape.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $contractsByEmployeeId Full-list contract index by employeeId.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $contractsByEmployeeId = []): array {
		return ['signals' => ['contractsByEmployeeId' => $contractsByEmployeeId]];
	}//end context()

	// -- nl-signaal-contract-verloopt: window edges --------------------------

	/**
	 * @return void
	 */
	public function testContractVerloeptViolatedAtDayZeroOfWindow(): void {
		$contract = $this->contract(['endDate' => date('Y-m-d')]);

		$this->assertFalse(($this->checks['nl-signaal-contract-verloopt'])($contract, $this->context()));

	}//end testContractVerloeptViolatedAtDayZeroOfWindow()

	/**
	 * @return void
	 */
	public function testContractVerloeptViolatedAtDaySixtyOfWindow(): void {
		$contract = $this->contract(['endDate' => date('Y-m-d', strtotime('+60 days'))]);

		$this->assertFalse(($this->checks['nl-signaal-contract-verloopt'])($contract, $this->context()));

	}//end testContractVerloeptViolatedAtDaySixtyOfWindow()

	/**
	 * @return void
	 */
	public function testContractVerloeptSatisfiedAtDaySixtyOneOutsideWindow(): void {
		$contract = $this->contract(['endDate' => date('Y-m-d', strtotime('+61 days'))]);

		$this->assertTrue(($this->checks['nl-signaal-contract-verloopt'])($contract, $this->context()));

	}//end testContractVerloeptSatisfiedAtDaySixtyOneOutsideWindow()

	/**
	 * @return void
	 */
	public function testContractVerloeptSatisfiedWhenAlreadyExpired(): void {
		$contract = $this->contract(['endDate' => date('Y-m-d', strtotime('-1 day'))]);

		$this->assertTrue(($this->checks['nl-signaal-contract-verloopt'])($contract, $this->context()));

	}//end testContractVerloeptSatisfiedWhenAlreadyExpired()

	/**
	 * @return void
	 */
	public function testContractVerloeptSatisfiedForPermanentContract(): void {
		$contract = $this->contract(['type' => 'permanent', 'endDate' => date('Y-m-d', strtotime('+10 days'))]);

		$this->assertTrue(($this->checks['nl-signaal-contract-verloopt'])($contract, $this->context()));

	}//end testContractVerloeptSatisfiedForPermanentContract()

	/**
	 * @return void
	 */
	public function testContractVerloeptSatisfiedForOpenEndedContract(): void {
		$contract = $this->contract(['endDate' => null]);

		$this->assertTrue(($this->checks['nl-signaal-contract-verloopt'])($contract, $this->context()));

	}//end testContractVerloeptSatisfiedForOpenEndedContract()

	// -- nl-signaal-contract-verloopt: successor shapes -----------------------

	/**
	 * @return void
	 */
	public function testContractVerloeptViolatedWhenNoSuccessorExists(): void {
		$contract = $this->contract();

		$this->assertFalse(($this->checks['nl-signaal-contract-verloopt'])($contract, $this->context()));

	}//end testContractVerloeptViolatedWhenNoSuccessorExists()

	/**
	 * @return void
	 */
	public function testContractVerloeptSatisfiedWhenOpenEndedSuccessorExists(): void {
		$contract = $this->contract();
		$context = $this->context(
			[
				'employee-x' => [
					['id' => 'contract-x', 'startDate' => '2025-01-01', 'endDate' => $contract['endDate']],
					['id' => 'contract-y', 'startDate' => date('Y-m-d', strtotime('+30 days')), 'endDate' => ''],
				],
			]
		);

		$this->assertTrue(($this->checks['nl-signaal-contract-verloopt'])($contract, $context));

	}//end testContractVerloeptSatisfiedWhenOpenEndedSuccessorExists()

	/**
	 * @return void
	 */
	public function testContractVerloeptSatisfiedWhenLaterFixedTermSuccessorExists(): void {
		$contract = $this->contract();
		$context = $this->context(
			[
				'employee-x' => [
					['id' => 'contract-x', 'startDate' => '2025-01-01', 'endDate' => $contract['endDate']],
					[
						'id' => 'contract-y',
						'startDate' => date('Y-m-d', strtotime('+30 days')),
						'endDate' => date('Y-m-d', strtotime('+400 days')),
					],
				],
			]
		);

		$this->assertTrue(($this->checks['nl-signaal-contract-verloopt'])($contract, $context));

	}//end testContractVerloeptSatisfiedWhenLaterFixedTermSuccessorExists()

	/**
	 * @return void
	 */
	public function testContractVerloeptViolatedWhenOnlyAnEarlierSiblingExists(): void {
		$contract = $this->contract();
		$context = $this->context(
			[
				'employee-x' => [
					['id' => 'contract-x', 'startDate' => '2025-01-01', 'endDate' => $contract['endDate']],
					['id' => 'contract-earlier', 'startDate' => '2020-01-01', 'endDate' => '2024-12-31'],
				],
			]
		);

		$this->assertFalse(($this->checks['nl-signaal-contract-verloopt'])($contract, $context));

	}//end testContractVerloeptViolatedWhenOnlyAnEarlierSiblingExists()

	/**
	 * @return void
	 */
	public function testContractVerloeptViolatedWhenSiblingStartsLaterButEndsNoLaterThanOwn(): void {
		// An "overlapping" sibling that starts after this contract but whose
		// own end does not extend past it is not a successor under the
		// shape-based heuristic (design.md D2/Risks).
		$contract = $this->contract(['startDate' => '2025-01-01', 'endDate' => date('Y-m-d', strtotime('+30 days'))]);
		$context = $this->context(
			[
				'employee-x' => [
					['id' => 'contract-x', 'startDate' => '2025-01-01', 'endDate' => $contract['endDate']],
					['id' => 'contract-overlap', 'startDate' => '2025-06-01', 'endDate' => date('Y-m-d', strtotime('+10 days'))],
				],
			]
		);

		$this->assertFalse(($this->checks['nl-signaal-contract-verloopt'])($contract, $context));

	}//end testContractVerloeptViolatedWhenSiblingStartsLaterButEndsNoLaterThanOwn()

	/**
	 * @return void
	 */
	public function testContractVerloeptExcludesItselfFromTheSuccessorScan(): void {
		// The sibling list includes the contract's own row (as the real
		// register load would) -- it must never count as its own successor.
		$contract = $this->contract();
		$context = $this->context(
			['employee-x' => [['id' => 'contract-x', 'startDate' => $contract['startDate'], 'endDate' => $contract['endDate']]]]
		);

		$this->assertFalse(($this->checks['nl-signaal-contract-verloopt'])($contract, $context));

	}//end testContractVerloeptExcludesItselfFromTheSuccessorScan()

	/**
	 * @return void
	 */
	public function testContractVerloeptDegradesToViolationWhenSignalsContextIsEmpty(): void {
		// An empty/missing context.signals index (the pre-pass never ran)
		// degrades to "no successor resolves" -- never a false pass.
		$contract = $this->contract();

		$this->assertFalse(($this->checks['nl-signaal-contract-verloopt'])($contract, []));

	}//end testContractVerloeptDegradesToViolationWhenSignalsContextIsEmpty()

	// -- nl-aanzegtermijn-bewaking: deadline arithmetic -----------------------

	/**
	 * @return void
	 */
	public function testAanzegtermijnViolatedWhenDeadlineMissedAndNotRecorded(): void {
		// 11-month fixed term whose one-month-out deadline is 43 days ago.
		$contract = $this->contract(
			[
				'startDate' => date('Y-m-d', strtotime('-11 months -13 days')),
				'endDate' => date('Y-m-d', strtotime('+17 days')),
				'aanzegdOn' => null,
			]
		);

		$this->assertFalse(($this->checks['nl-aanzegtermijn-bewaking'])($contract));

	}//end testAanzegtermijnViolatedWhenDeadlineMissedAndNotRecorded()

	/**
	 * @return void
	 */
	public function testAanzegtermijnSatisfiedWhenAanzegdOnIsTimely(): void {
		$contract = $this->contract(
			[
				'startDate' => date('Y-m-d', strtotime('-11 months -13 days')),
				'endDate' => date('Y-m-d', strtotime('+17 days')),
				'aanzegdOn' => date('Y-m-d', strtotime('-40 days')),
			]
		);

		$this->assertTrue(($this->checks['nl-aanzegtermijn-bewaking'])($contract));

	}//end testAanzegtermijnSatisfiedWhenAanzegdOnIsTimely()

	/**
	 * @return void
	 */
	public function testAanzegtermijnViolatedWhenAanzegdOnIsRecordedButLate(): void {
		$contract = $this->contract(
			[
				'startDate' => date('Y-m-d', strtotime('-11 months -13 days')),
				'endDate' => date('Y-m-d', strtotime('+17 days')),
				// Recorded, but after the one-month-out deadline.
				'aanzegdOn' => date('Y-m-d', strtotime('-10 days')),
			]
		);

		$this->assertFalse(($this->checks['nl-aanzegtermijn-bewaking'])($contract));

	}//end testAanzegtermijnViolatedWhenAanzegdOnIsRecordedButLate()

	/**
	 * @return void
	 */
	public function testAanzegtermijnSatisfiedWhenDeadlineNotYetPassed(): void {
		$contract = $this->contract(
			[
				'startDate' => date('Y-m-d', strtotime('-3 months')),
				'endDate' => date('Y-m-d', strtotime('+8 months')),
				'aanzegdOn' => null,
			]
		);

		$this->assertTrue(($this->checks['nl-aanzegtermijn-bewaking'])($contract));

	}//end testAanzegtermijnSatisfiedWhenDeadlineNotYetPassed()

	/**
	 * @return void
	 */
	public function testAanzegtermijnSatisfiedOnTheBoundaryMonth(): void {
		// aanzegdOn recorded exactly on the deadline (endDate minus one
		// month) satisfies the "on or before" wording.
		$endDate = date('Y-m-d', strtotime('+17 days'));
		$deadline = date('Y-m-d', strtotime($endDate . ' -1 month'));
		$contract = $this->contract(
			[
				'startDate' => date('Y-m-d', strtotime($endDate . ' -11 months')),
				'endDate' => $endDate,
				'aanzegdOn' => $deadline,
			]
		);

		$this->assertTrue(($this->checks['nl-aanzegtermijn-bewaking'])($contract));

	}//end testAanzegtermijnSatisfiedOnTheBoundaryMonth()

	/**
	 * @return void
	 */
	public function testAanzegtermijnSatisfiedForShortFixedTermUnderSixMonths(): void {
		// A 5-month fixed term whose deadline would already have passed --
		// out of scope per BW 7:668 lid 1 (applies from six months).
		$contract = $this->contract(
			[
				'startDate' => date('Y-m-d', strtotime('-5 months -3 days')),
				'endDate' => date('Y-m-d', strtotime('-3 days')),
				'aanzegdOn' => null,
			]
		);

		$this->assertTrue(($this->checks['nl-aanzegtermijn-bewaking'])($contract));

	}//end testAanzegtermijnSatisfiedForShortFixedTermUnderSixMonths()

	/**
	 * @return void
	 */
	public function testAanzegtermijnSatisfiedForPermanentContract(): void {
		$contract = $this->contract(
			[
				'type' => 'permanent',
				'startDate' => date('Y-m-d', strtotime('-2 years')),
				'endDate' => date('Y-m-d', strtotime('-1 day')),
				'aanzegdOn' => null,
			]
		);

		$this->assertTrue(($this->checks['nl-aanzegtermijn-bewaking'])($contract));

	}//end testAanzegtermijnSatisfiedForPermanentContract()

	/**
	 * @return void
	 */
	public function testAanzegtermijnSatisfiedWhenAlreadyExpired(): void {
		// Deadline passed AND the contract itself has since expired --
		// historical breaches after expiry are not re-flagged (monitoring
		// window, design.md D2).
		$contract = $this->contract(
			[
				'startDate' => date('Y-m-d', strtotime('-14 months')),
				'endDate' => date('Y-m-d', strtotime('-2 days')),
				'aanzegdOn' => null,
			]
		);

		$this->assertTrue(($this->checks['nl-aanzegtermijn-bewaking'])($contract));

	}//end testAanzegtermijnSatisfiedWhenAlreadyExpired()

	// -- nl-bhv-certificaat-verloopt: bhv-organisatie -------------------------

	/**
	 * A minimal BhvCertificering fixture; each test overrides the fields it
	 * exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function bhvCertificering(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'bhv-x',
				'employeeId' => 'employee-x',
				'rol' => 'bhv_basis',
				'certificaatBehaaldOp' => '2024-01-01',
				'certificaatGeldigTot' => date('Y-m-d', strtotime('+45 days')),
			],
			$overrides
		);

	}//end bhvCertificering()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
	 */
	public function testBhvCertificaatViolatedWhenExpiringInsideTheNinetyDayWindow(): void {
		$cert = $this->bhvCertificering(['certificaatGeldigTot' => date('Y-m-d', strtotime('+45 days'))]);

		$this->assertFalse(($this->bhvChecks['nl-bhv-certificaat-verloopt'])($cert));

	}//end testBhvCertificaatViolatedWhenExpiringInsideTheNinetyDayWindow()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
	 */
	public function testBhvCertificaatViolatedAtDayZeroOfWindow(): void {
		$cert = $this->bhvCertificering(['certificaatGeldigTot' => date('Y-m-d')]);

		$this->assertFalse(($this->bhvChecks['nl-bhv-certificaat-verloopt'])($cert));

	}//end testBhvCertificaatViolatedAtDayZeroOfWindow()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
	 */
	public function testBhvCertificaatViolatedAtDayNinetyOfWindow(): void {
		$cert = $this->bhvCertificering(['certificaatGeldigTot' => date('Y-m-d', strtotime('+90 days'))]);

		$this->assertFalse(($this->bhvChecks['nl-bhv-certificaat-verloopt'])($cert));

	}//end testBhvCertificaatViolatedAtDayNinetyOfWindow()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
	 */
	public function testBhvCertificaatSatisfiedAtDayNinetyOneOutsideWindow(): void {
		$cert = $this->bhvCertificering(['certificaatGeldigTot' => date('Y-m-d', strtotime('+91 days'))]);

		$this->assertTrue(($this->bhvChecks['nl-bhv-certificaat-verloopt'])($cert));

	}//end testBhvCertificaatSatisfiedAtDayNinetyOneOutsideWindow()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
	 */
	public function testBhvCertificaatSatisfiedWhenValidOneYearOut(): void {
		$cert = $this->bhvCertificering(['certificaatGeldigTot' => date('Y-m-d', strtotime('+1 year'))]);

		$this->assertTrue(($this->bhvChecks['nl-bhv-certificaat-verloopt'])($cert));

	}//end testBhvCertificaatSatisfiedWhenValidOneYearOut()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
	 */
	public function testBhvCertificaatSatisfiedWhenAlreadyExpired(): void {
		// An already-expired certificate is a distinct, more urgent state
		// this MVP does not separately classify (REQ-BHV-002) -- it passes
		// this predicate vacuously, the same monitoring-window posture the
		// two EmploymentContract predicates take.
		$cert = $this->bhvCertificering(['certificaatGeldigTot' => date('Y-m-d', strtotime('-1 day'))]);

		$this->assertTrue(($this->bhvChecks['nl-bhv-certificaat-verloopt'])($cert));

	}//end testBhvCertificaatSatisfiedWhenAlreadyExpired()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
	 */
	public function testBhvCertificaatSatisfiedWhenGeldigTotUnparseable(): void {
		$cert = $this->bhvCertificering(['certificaatGeldigTot' => '']);

		$this->assertTrue(($this->bhvChecks['nl-bhv-certificaat-verloopt'])($cert));

	}//end testBhvCertificaatSatisfiedWhenGeldigTotUnparseable()

	/**
	 * The two existing EmploymentContract predicates are unaffected by the
	 * BhvCertificering addition (REQ-BHV-002 "the existing hr-signals
	 * predicates are unaffected") -- both remain registered exclusively
	 * under the EmploymentContract key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
	 */
	public function testExistingContractPredicatesRemainUnderEmploymentContractKeyOnly(): void {
		$all = NlSignalChecks::checks();

		$this->assertArrayHasKey('nl-signaal-contract-verloopt', $all['EmploymentContract']);
		$this->assertArrayHasKey('nl-aanzegtermijn-bewaking', $all['EmploymentContract']);
		$this->assertArrayHasKey('nl-bhv-certificaat-verloopt', $all['BhvCertificering']);
		$this->assertArrayNotHasKey('nl-bhv-certificaat-verloopt', $all['EmploymentContract']);

	}//end testExistingContractPredicatesRemainUnderEmploymentContractKeyOnly()

}//end class
