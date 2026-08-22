<?php

/**
 * Unit tests for the NL loonaangifte filing-lifecycle checks.
 *
 * Pins the three new executable checks the loonaangifte-filing-lifecycle
 * change adds to NlWageTaxFilingChecks: tijdvakcode consistency (read from the
 * corpus rule's `parameters` tables, never hard-coded), deadline derivation
 * (period end + 1 calendar month, no weekend/holiday extension), and deadline
 * alerting (14-day window / overdue) for filings not yet sent. Also pins that
 * the pre-existing seedObjects() sample still satisfies every registered check
 * (old and new) after the schema/corpus additions.
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
 * @spec openspec/changes/loonaangifte-filing-lifecycle/specs/loonaangifte-filing-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlWageTaxFilingChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlWageTaxFilingChecks.
 *
 * @spec openspec/changes/loonaangifte-filing-lifecycle/specs/loonaangifte-filing-lifecycle/spec.md
 */
class NlWageTaxFilingChecksTest extends TestCase {

	/**
	 * The registered LoonaangifteFiling predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlWageTaxFilingChecks::checks()['LoonaangifteFiling'];

	}//end setUp()

	/**
	 * A minimal NL loonaangifte base fixture; each test overrides the fields
	 * it exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function filing(array $overrides = []): array {
		return array_merge(
			[
				'filingType' => 'loonaangifte',
				'jurisdiction' => 'NL',
				'tijdvak' => 'maand',
				'period' => '2026-01',
				'tijdvakcode' => '6010',
				'deadline' => '2026-02-28',
				'status' => 'klaargezet',
			],
			$overrides
		);

	}//end filing()

	/**
	 * @return void
	 */
	public function testTijdvakcodeConsistentForCorrectMaandCode(): void {
		$filing = $this->filing(['period' => '2026-04', 'tijdvakcode' => '6040']);
		$this->assertTrue(($this->checks['nl-loonaangifte-tijdvakcode'])($filing));

	}//end testTijdvakcodeConsistentForCorrectMaandCode()

	/**
	 * @return void
	 */
	public function testTijdvakcodeInconsistentForWrongMaandCode(): void {
		// Seeded scenario from REQ-LFL-004: period 2026-04 (maand) must carry
		// 6040, not 6050.
		$filing = $this->filing(['period' => '2026-04', 'tijdvakcode' => '6050']);
		$this->assertFalse(($this->checks['nl-loonaangifte-tijdvakcode'])($filing));

	}//end testTijdvakcodeInconsistentForWrongMaandCode()

	/**
	 * @return void
	 */
	public function testTijdvakcodeConsistentForJaarCode(): void {
		$filing = $this->filing(['tijdvak' => 'jaar', 'period' => '2026', 'tijdvakcode' => '6400']);
		$this->assertTrue(($this->checks['nl-loonaangifte-tijdvakcode'])($filing));

		$wrong = $this->filing(['tijdvak' => 'jaar', 'period' => '2026', 'tijdvakcode' => '6010']);
		$this->assertFalse(($this->checks['nl-loonaangifte-tijdvakcode'])($wrong));

	}//end testTijdvakcodeConsistentForJaarCode()

	/**
	 * @return void
	 */
	public function testMissingTijdvakcodeIsAllowedOnConceptFiling(): void {
		$filing = $this->filing(['tijdvakcode' => '', 'status' => 'concept']);
		$this->assertTrue(($this->checks['nl-loonaangifte-tijdvakcode'])($filing));

	}//end testMissingTijdvakcodeIsAllowedOnConceptFiling()

	/**
	 * @return void
	 */
	public function testMissingTijdvakcodeIsAViolationOnceReviewReady(): void {
		$filing = $this->filing(['tijdvakcode' => '', 'status' => 'klaargezet']);
		$this->assertFalse(($this->checks['nl-loonaangifte-tijdvakcode'])($filing));

	}//end testMissingTijdvakcodeIsAViolationOnceReviewReady()

	/**
	 * @return void
	 */
	public function testDeadlineDerivationCorrectForOrdinaryMonth(): void {
		$filing = $this->filing(['period' => '2026-05', 'deadline' => '2026-06-30']);
		$this->assertTrue(($this->checks['nl-loonaangifte-deadline-derivation'])($filing));

	}//end testDeadlineDerivationCorrectForOrdinaryMonth()

	/**
	 * @return void
	 */
	public function testDeadlineDerivationRejectsWeekendExtension(): void {
		// 2026-02-28 is a Saturday; AWR/LH 210 grant no extension to the
		// following Monday (2026-03-02).
		$filing = $this->filing(['period' => '2026-01', 'deadline' => '2026-03-02']);
		$this->assertFalse(($this->checks['nl-loonaangifte-deadline-derivation'])($filing));

	}//end testDeadlineDerivationRejectsWeekendExtension()

	/**
	 * @return void
	 */
	public function testDeadlineDerivationHandlesDecemberYearRollover(): void {
		$filing = $this->filing(['period' => '2025-12', 'deadline' => '2026-01-31']);
		$this->assertTrue(($this->checks['nl-loonaangifte-deadline-derivation'])($filing));

	}//end testDeadlineDerivationHandlesDecemberYearRollover()

	/**
	 * @return void
	 */
	public function testDeadlineAlertOverdueUnfiledIsAViolation(): void {
		$filing = $this->filing(['status' => 'concept', 'deadline' => '2026-04-30']);
		$this->assertFalse(($this->checks['nl-loonaangifte-deadline-alert'])($filing));

	}//end testDeadlineAlertOverdueUnfiledIsAViolation()

	/**
	 * @return void
	 */
	public function testDeadlineAlertApproachingWithin14DaysIsAViolation(): void {
		$filing = $this->filing(['status' => 'bevestigd', 'deadline' => date('Y-m-d', strtotime('+7 days'))]);
		$this->assertFalse(($this->checks['nl-loonaangifte-deadline-alert'])($filing));

	}//end testDeadlineAlertApproachingWithin14DaysIsAViolation()

	/**
	 * @return void
	 */
	public function testDeadlineAlertFarFutureDeadlineIsNotAViolation(): void {
		$filing = $this->filing(['status' => 'concept', 'deadline' => date('Y-m-d', strtotime('+30 days'))]);
		$this->assertTrue(($this->checks['nl-loonaangifte-deadline-alert'])($filing));

	}//end testDeadlineAlertFarFutureDeadlineIsNotAViolation()

	/**
	 * @return void
	 */
	public function testDeadlineAlertNeverFiresOnceSent(): void {
		$filing = $this->filing(['status' => 'verzonden', 'deadline' => '2020-01-01']);
		$this->assertTrue(($this->checks['nl-loonaangifte-deadline-alert'])($filing));

	}//end testDeadlineAlertNeverFiresOnceSent()

	/**
	 * @return void
	 */
	public function testNonNlOrNonLoonaangifteFilingsAreUnaffectedByAllThreeChecks(): void {
		$de = $this->filing(['jurisdiction' => 'DE', 'filingType' => 'lohnsteuer-anmeldung', 'tijdvakcode' => 'ABC1', 'deadline' => '1900-01-01', 'status' => 'concept']);
		$this->assertTrue(($this->checks['nl-loonaangifte-tijdvakcode'])($de));
		$this->assertTrue(($this->checks['nl-loonaangifte-deadline-derivation'])($de));
		$this->assertTrue(($this->checks['nl-loonaangifte-deadline-alert'])($de));

	}//end testNonNlOrNonLoonaangifteFilingsAreUnaffectedByAllThreeChecks()

	/**
	 * @return void
	 */
	public function testSeedObjectSatisfiesEveryRegisteredCheck(): void {
		$seed = NlWageTaxFilingChecks::seedObjects()['LoonaangifteFiling'][0];
		foreach ($this->checks as $ruleId => $predicate) {
			$this->assertTrue((bool)$predicate($seed), sprintf('seed sample violates %s', $ruleId));
		}

	}//end testSeedObjectSatisfiesEveryRegisteredCheck()

	/**
	 * @return void
	 */
	public function testAllThreeNewRuleIdsAreRegistered(): void {
		$this->assertArrayHasKey('nl-loonaangifte-tijdvakcode', $this->checks);
		$this->assertArrayHasKey('nl-loonaangifte-deadline-derivation', $this->checks);
		$this->assertArrayHasKey('nl-loonaangifte-deadline-alert', $this->checks);

	}//end testAllThreeNewRuleIdsAreRegistered()

}//end class
