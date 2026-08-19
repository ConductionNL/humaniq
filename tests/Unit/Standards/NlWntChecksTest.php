<?php

/**
 * Unit tests for the WNT norm-overschrijding check (NlWntChecks).
 *
 * Pins the `nl-wnt-norm-overschrijding` predicate (design.md D1/D4, spec.md
 * REQ-WNT-003): vacuous on a dangling employee reference, vacuous for a
 * non-topfunctionaris regardless of compensation, vacuous when a transitional
 * exemption (overgangsrecht / ontheffing-minister) is recorded, else violates
 * only when the hand-entered annual `totalCompensation` exceeds the REAL
 * `lib/Standards/tables/nl-2026.json` WNT-norm ANNUAL figure (EUR 262.000/jaar)
 * read via `TaxTables::dertigProcentRegeling()['aftoppingsgrensJaarCents']` --
 * never a hardcoded figure re-declared here. The suite drives the REAL
 * `RuleEngine::evaluate()` (catalogue + auto-discovered CheckProviders + the
 * nl-2026 table) proving the rule is genuinely reachable, and proves the
 * entire pre-existing seed population (all `wntTopfunctionaris: false`) stays
 * silent.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards
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
 * @spec openspec/changes/wnt-disclosure/specs/wnt-disclosure/spec.md#REQ-WNT-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards;

use OCA\Hrmq\Standards\Checks\NlWntChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlWntChecks (raw predicate + through the REAL RuleEngine).
 *
 * @spec openspec/changes/wnt-disclosure/specs/wnt-disclosure/spec.md#REQ-WNT-003
 */
class NlWntChecksTest extends TestCase {

	/**
	 * The WNT-norm's annual figure for 2026 (EUR 262.000), read here ONLY to
	 * express the boundary in the test assertions -- the predicate itself
	 * reads it from TaxTables, never from a literal (design.md D1).
	 *
	 * @var int
	 */
	private const WNT_NORM_JAAR_EURO = 262000;

	/**
	 * The registered predicates.
	 *
	 * @var array<string, array<string, callable>>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		$this->checks = NlWntChecks::checks();

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();

	}//end tearDown()

	/**
	 * A minimal WntDisclosure fixture, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function disclosure(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'emp-topf',
				'year' => '2026',
				'totalCompensation' => 300000,
				'status' => 'concept',
			],
			$overrides
		);

	}//end disclosure()

	/**
	 * A `context['related']['Employee']['byId']` fixture, keyed by each row's
	 * `id` (the RuleAuditService::buildRelatedContext() shape this change
	 * extends).
	 *
	 * @param array<int, array<string, mixed>> $employees The Employee rows.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $employees): array {
		$byId = [];
		foreach ($employees as $employee) {
			$byId[(string)($employee['id'] ?? '')] = $employee;
		}

		return [
			'jurisdiction' => 'NL',
			'related' => ['Employee' => ['byId' => $byId]],
		];

	}//end context()

	/**
	 * A topfunctionaris Employee row for the byId index.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function topfunctionaris(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-topf',
				'wntTopfunctionaris' => true,
				'wntUitzonderingReden' => null,
			],
			$overrides
		);

	}//end topfunctionaris()

	/**
	 * REQ-WNT-003 Scenario "Compensation above norm without exemption is
	 * flagged": a topfunctionaris with no exemption and totalCompensation just
	 * over the norm is flagged.
	 *
	 * @return void
	 */
	public function testOverNormTopfunctionarisWithoutExemptionIsFlagged(): void {
		$check = $this->checks['WntDisclosure']['nl-wnt-norm-overschrijding'];

		$disclosure = $this->disclosure(['totalCompensation' => (self::WNT_NORM_JAAR_EURO + 1)]);
		$context = $this->context([$this->topfunctionaris()]);

		self::assertFalse($check($disclosure, $context));

	}//end testOverNormTopfunctionarisWithoutExemptionIsFlagged()

	/**
	 * REQ-WNT-003 Scenario "A valid transitional exemption clears the flag":
	 * the SAME over-norm figure passes when the referenced Employee records an
	 * overgangsrecht exemption (presence-only gate, design.md D4).
	 *
	 * @return void
	 */
	public function testOverNormWithRecordedExemptionPasses(): void {
		$check = $this->checks['WntDisclosure']['nl-wnt-norm-overschrijding'];

		$disclosure = $this->disclosure(['totalCompensation' => (self::WNT_NORM_JAAR_EURO + 50000)]);

		self::assertTrue($check($disclosure, $this->context([$this->topfunctionaris(['wntUitzonderingReden' => 'overgangsrecht'])])));
		self::assertTrue($check($disclosure, $this->context([$this->topfunctionaris(['wntUitzonderingReden' => 'ontheffing-minister'])])));

	}//end testOverNormWithRecordedExemptionPasses()

	/**
	 * REQ-WNT-003 Scenario "Compensation at or below norm passes": exactly at
	 * the norm passes, just above violates -- proving the boundary is the REAL
	 * TaxTables annual leaf, not a fabricated figure.
	 *
	 * @return void
	 */
	public function testNormBoundaryIsTheRealTaxTablesAnnualLeaf(): void {
		$check = $this->checks['WntDisclosure']['nl-wnt-norm-overschrijding'];
		$context = $this->context([$this->topfunctionaris()]);

		self::assertTrue(
			$check($this->disclosure(['totalCompensation' => self::WNT_NORM_JAAR_EURO]), $context),
			'At exactly the WNT-norm the disclosure must pass.'
		);
		self::assertFalse(
			$check($this->disclosure(['totalCompensation' => (self::WNT_NORM_JAAR_EURO + 1)]), $context),
			'One euro over the WNT-norm the disclosure must violate -- proving the boundary is the real TaxTables jaar leaf.'
		);

	}//end testNormBoundaryIsTheRealTaxTablesAnnualLeaf()

	/**
	 * REQ-WNT-003 Scenario "A non-topfunctionaris employee's disclosure never
	 * violates": a referenced Employee with wntTopfunctionaris false is vacuous
	 * regardless of compensation.
	 *
	 * @return void
	 */
	public function testNonTopfunctionarisIsVacuousRegardlessOfCompensation(): void {
		$check = $this->checks['WntDisclosure']['nl-wnt-norm-overschrijding'];

		$disclosure = $this->disclosure(['totalCompensation' => 1000000]);

		self::assertTrue($check($disclosure, $this->context([$this->topfunctionaris(['wntTopfunctionaris' => false])])));
		self::assertTrue($check($disclosure, $this->context([$this->topfunctionaris(['wntTopfunctionaris' => null])])));

	}//end testNonTopfunctionarisIsVacuousRegardlessOfCompensation()

	/**
	 * A WntDisclosure whose referenced Employee cannot be resolved from the
	 * pre-pass (dangling reference) is vacuous -- a different data-integrity
	 * problem, never a false WNT violation.
	 *
	 * @return void
	 */
	public function testDanglingEmployeeReferenceIsVacuous(): void {
		$check = $this->checks['WntDisclosure']['nl-wnt-norm-overschrijding'];

		$disclosure = $this->disclosure(['employeeId' => 'does-not-exist', 'totalCompensation' => 1000000]);

		self::assertTrue($check($disclosure, $this->context([$this->topfunctionaris()])));

	}//end testDanglingEmployeeReferenceIsVacuous()

	/**
	 * REQ-WNT-003 — the violation branch driven through the REAL
	 * `RuleEngine::evaluate()` (catalogue + auto-discovered CheckProviders +
	 * the nl-2026 table), proving `nl-wnt-norm-overschrijding` is genuinely
	 * reachable via `occ hrmq:rules:audit` and not an orphaned capability.
	 *
	 * @return void
	 */
	public function testRealRuleEngineFiresTheViolationForAnOverNormTopfunctionaris(): void {
		$disclosure = $this->disclosure(['totalCompensation' => (self::WNT_NORM_JAAR_EURO + 18000)]);
		$violations = RuleEngine::evaluate('WntDisclosure', $disclosure, $this->context([$this->topfunctionaris()]));

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		self::assertContains('nl-wnt-norm-overschrijding', $ruleIds, 'The real RuleEngine must fire nl-wnt-norm-overschrijding for an over-norm topfunctionaris without exemption.');

		$violation = $violations[array_search('nl-wnt-norm-overschrijding', $ruleIds, true)];
		self::assertSame('mandatory', $violation->severity, 'nl-wnt-norm-overschrijding must be severity mandatory (WNT art. 2.3).');

	}//end testRealRuleEngineFiresTheViolationForAnOverNormTopfunctionaris()

	/**
	 * The mirror-image REAL RuleEngine checks: an exemption, an at-norm figure,
	 * and a non-topfunctionaris each produce NO nl-wnt-norm-overschrijding
	 * violation.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentOnTheCleanBranches(): void {
		$overNorm = $this->disclosure(['totalCompensation' => (self::WNT_NORM_JAAR_EURO + 50000)]);

		$exempt = RuleEngine::evaluate('WntDisclosure', $overNorm, $this->context([$this->topfunctionaris(['wntUitzonderingReden' => 'overgangsrecht'])]));
		self::assertNotContains('nl-wnt-norm-overschrijding', array_map(static fn ($v) => $v->ruleId, $exempt));

		$atNorm = RuleEngine::evaluate('WntDisclosure', $this->disclosure(['totalCompensation' => self::WNT_NORM_JAAR_EURO]), $this->context([$this->topfunctionaris()]));
		self::assertNotContains('nl-wnt-norm-overschrijding', array_map(static fn ($v) => $v->ruleId, $atNorm));

		$nonTopf = RuleEngine::evaluate('WntDisclosure', $overNorm, $this->context([$this->topfunctionaris(['wntTopfunctionaris' => false])]));
		self::assertNotContains('nl-wnt-norm-overschrijding', array_map(static fn ($v) => $v->ruleId, $nonTopf));

	}//end testRealRuleEngineIsSilentOnTheCleanBranches()

	/**
	 * REQ-WNT-005 Scenario "The pre-existing seed population stays silent":
	 * every pre-existing seeded Employee (all `wntTopfunctionaris: false`)
	 * produces NO nl-wnt-norm-overschrijding violation, even against an
	 * absurdly over-norm disclosure — the rule stays silent for the whole
	 * non-topfunctionaris population.
	 *
	 * @return void
	 */
	public function testPreExistingSeedPopulationStaysSilent(): void {
		$seedPath = __DIR__ . '/../../../lib/Settings/register.d/hr-seed.json';
		self::assertFileExists($seedPath, 'The hr-seed fragment must exist.');

		$seed = json_decode((string)file_get_contents($seedPath), true);
		$objects = ($seed['components']['objects'] ?? []);
		self::assertNotEmpty($objects, 'The seed must contain objects.');

		$nonTopfEmployees = [];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') !== 'Employee') {
				continue;
			}

			if (($object['wntTopfunctionaris'] ?? false) === true) {
				continue;
			}

			$nonTopfEmployees[] = (string)($object['@self']['slug'] ?? '');
		}

		self::assertNotEmpty($nonTopfEmployees, 'There must be pre-existing non-topfunctionaris employees in the seed.');

		foreach ($nonTopfEmployees as $slug) {
			$context = $this->context([['id' => $slug, 'wntTopfunctionaris' => false, 'wntUitzonderingReden' => null]]);
			$disclosure = $this->disclosure(['employeeId' => $slug, 'totalCompensation' => 5000000]);

			$violations = RuleEngine::evaluate('WntDisclosure', $disclosure, $context);
			self::assertNotContains(
				'nl-wnt-norm-overschrijding',
				array_map(static fn ($v) => $v->ruleId, $violations),
				'Pre-existing seeded employee "' . $slug . '" (non-topfunctionaris) must never fire the WNT norm check.'
			);
		}

	}//end testPreExistingSeedPopulationStaysSilent()

}//end class
