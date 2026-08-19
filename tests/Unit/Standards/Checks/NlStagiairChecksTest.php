<?php

/**
 * Unit tests for the stagiair/BBL BPV-overeenkomst check (NlStagiairChecks).
 *
 * Pins the single `hr-stagiair` predicate `nl-bpv-overeenkomst-vereist`
 * (spec.md REQ-STAG-005), registered under BOTH object types:
 *
 * - `Stagiair`: violates when startDate has passed and
 *   bpvOvereenkomstOndertekend is not true; vacuous for a future/absent
 *   startDate; passes when signed.
 * - `EmploymentContract`: the SAME predicate GUARDED to `type === 'bbl'` --
 *   it NEVER fires for a permanent/temporary/agency/minijob contract
 *   (the guard is asserted explicitly here, the acceptance criterion).
 *
 * The suite drives the raw predicates, then the REAL `RuleEngine::evaluate()`
 * (catalogue + auto-discovered CheckProviders) proving the rule is genuinely
 * reachable under both object types and carries severity `mandatory`, and
 * finally loads the actual `lib/Settings/register.d/hr-seed.json` and asserts
 * exactly the intended seed shapes (stagiair-bakker violates; stagiair-devries
 * and contract-visser-bbl pass; no non-bbl contract is ever flagged).
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
 * @spec openspec/changes/stagiair-bbl-admin/specs/stagiair-bbl-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlStagiairChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlStagiairChecks (raw predicates + through the REAL RuleEngine).
 *
 * @spec openspec/changes/stagiair-bbl-admin/specs/stagiair-bbl-admin/spec.md
 */
class NlStagiairChecksTest extends TestCase {

	/**
	 * The registered Stagiair predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $stagiairChecks;

	/**
	 * The registered EmploymentContract predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $contractChecks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		$this->stagiairChecks = NlStagiairChecks::checks()['Stagiair'];
		$this->contractChecks = NlStagiairChecks::checks()['EmploymentContract'];

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();

	}//end tearDown()

	/**
	 * A date N days from today, ISO 8601.
	 *
	 * @param int $days Offset in days (negative = past).
	 *
	 * @return string
	 */
	private function dateOffset(int $days): string {
		return (new \DateTimeImmutable('today'))->modify($days . ' days')->format('Y-m-d');
	}//end dateOffset()

	/**
	 * REQ-STAG-005 scenario: a Stagiair whose startDate has passed with a
	 * signed BPV passes; the same placement unsigned violates.
	 *
	 * @return void
	 */
	public function testStagiairPassAndViolationOnStartedPlacement(): void {
		$check = $this->stagiairChecks['nl-bpv-overeenkomst-vereist'];

		$signed = ['startDate' => $this->dateOffset(-30), 'bpvOvereenkomstOndertekend' => true];
		$unsigned = ['startDate' => $this->dateOffset(-30), 'bpvOvereenkomstOndertekend' => false];

		$this->assertTrue($check($signed), 'A signed BPV on a started placement passes.');
		$this->assertFalse($check($unsigned), 'An unsigned BPV on a started placement violates.');

	}//end testStagiairPassAndViolationOnStartedPlacement()

	/**
	 * REQ-STAG-005 scenario: a future-dated placement is not yet checked, even
	 * with an unsigned BPV (the placement has not started).
	 *
	 * @return void
	 */
	public function testStagiairFutureStartDateIsVacuous(): void {
		$check = $this->stagiairChecks['nl-bpv-overeenkomst-vereist'];

		$future = ['startDate' => $this->dateOffset(30), 'bpvOvereenkomstOndertekend' => false];

		$this->assertTrue($check($future), 'A future placement with an unsigned BPV is not yet flagged.');

	}//end testStagiairFutureStartDateIsVacuous()

	/**
	 * An absent/unparseable startDate is vacuously satisfied, and only an
	 * exact boolean true counts as signed (a truthy string/1 does NOT).
	 *
	 * @return void
	 */
	public function testStagiairEdgeCases(): void {
		$check = $this->stagiairChecks['nl-bpv-overeenkomst-vereist'];

		$this->assertTrue($check(['bpvOvereenkomstOndertekend' => false]), 'No startDate is vacuous.');
		$this->assertTrue($check(['startDate' => 'not-a-date', 'bpvOvereenkomstOndertekend' => false]), 'Unparseable startDate is vacuous.');
		$this->assertFalse($check(['startDate' => $this->dateOffset(-1), 'bpvOvereenkomstOndertekend' => 1]), 'Integer 1 is not a signed BPV.');
		$this->assertFalse($check(['startDate' => $this->dateOffset(-1), 'bpvOvereenkomstOndertekend' => 'true']), 'A truthy string is not a signed BPV.');

	}//end testStagiairEdgeCases()

	/**
	 * REQ-STAG-005 scenario: a bbl EmploymentContract with a passed startDate
	 * and an unsigned BPV violates; the same contract signed passes.
	 *
	 * @return void
	 */
	public function testBblContractPassAndViolation(): void {
		$check = $this->contractChecks['nl-bpv-overeenkomst-vereist'];

		$unsigned = ['type' => 'bbl', 'startDate' => $this->dateOffset(-30), 'bpvOvereenkomstOndertekend' => false];
		$signed = ['type' => 'bbl', 'startDate' => $this->dateOffset(-30), 'bpvOvereenkomstOndertekend' => true];

		$this->assertFalse($check($unsigned), 'An unsigned bbl leerarbeidsovereenkomst past its start date violates.');
		$this->assertTrue($check($signed), 'A signed bbl contract passes.');

	}//end testBblContractPassAndViolation()

	/**
	 * The type === 'bbl' guard (acceptance criterion): the rule NEVER fires for
	 * a permanent/temporary/agency/minijob contract, even with a passed
	 * startDate and an explicitly unsigned/absent BPV field. This is the
	 * guard the spec requires be tested explicitly.
	 *
	 * @return void
	 */
	public function testNonBblContractIsNeverChecked(): void {
		$check = $this->contractChecks['nl-bpv-overeenkomst-vereist'];

		foreach (['permanent', 'temporary', 'agency', 'minijob'] as $type) {
			$unsigned = ['type' => $type, 'startDate' => $this->dateOffset(-30), 'bpvOvereenkomstOndertekend' => false];
			$absent = ['type' => $type, 'startDate' => $this->dateOffset(-30)];

			$this->assertTrue($check($unsigned), "nl-bpv-overeenkomst-vereist must never fire for a {$type} contract (unsigned).");
			$this->assertTrue($check($absent), "nl-bpv-overeenkomst-vereist must never fire for a {$type} contract (no BPV field).");
		}

	}//end testNonBblContractIsNeverChecked()

	/**
	 * REQ-STAG-005 — the Stagiair violation driven through the REAL
	 * `RuleEngine::evaluate('Stagiair', ...)`, proving the rule is reachable
	 * via `occ hrmq:rules:audit` and carries severity `mandatory`.
	 *
	 * @return void
	 */
	public function testRealRuleEngineFiresMandatoryStagiairViolation(): void {
		$stagiair = ['startDate' => $this->dateOffset(-14), 'bpvOvereenkomstOndertekend' => false];

		$violations = RuleEngine::evaluate('Stagiair', $stagiair);
		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);

		$this->assertContains('nl-bpv-overeenkomst-vereist', $ruleIds);

		$violation = $violations[array_search('nl-bpv-overeenkomst-vereist', $ruleIds, true)];
		$this->assertSame('mandatory', $violation->severity, 'nl-bpv-overeenkomst-vereist must be severity mandatory.');

	}//end testRealRuleEngineFiresMandatoryStagiairViolation()

	/**
	 * REQ-STAG-005 — the bbl-contract violation and the non-bbl silence, both
	 * through the REAL `RuleEngine::evaluate('EmploymentContract', ...)`.
	 *
	 * @return void
	 */
	public function testRealRuleEngineBblContractBranch(): void {
		$bbl = ['type' => 'bbl', 'startDate' => $this->dateOffset(-14), 'bpvOvereenkomstOndertekend' => false];
		$permanent = ['type' => 'permanent', 'startDate' => $this->dateOffset(-14)];

		$bblRuleIds = array_map(static fn ($v) => $v->ruleId, RuleEngine::evaluate('EmploymentContract', $bbl));
		$permRuleIds = array_map(static fn ($v) => $v->ruleId, RuleEngine::evaluate('EmploymentContract', $permanent));

		$this->assertContains('nl-bpv-overeenkomst-vereist', $bblRuleIds, 'An unsigned started bbl contract must fire the rule through the real engine.');
		$this->assertNotContains('nl-bpv-overeenkomst-vereist', $permRuleIds, 'A permanent contract must never fire nl-bpv-overeenkomst-vereist.');

	}//end testRealRuleEngineBblContractBranch()

	/**
	 * REQ-STAG-005 — the real hr-seed.json population: exactly one Stagiair
	 * (stagiair-bakker) fires the rule; stagiair-devries and every seeded
	 * EmploymentContract (including the new type: bbl contract-visser-bbl and
	 * every pre-existing non-bbl contract) stay silent.
	 *
	 * @return void
	 */
	public function testRealSeedPopulationFlagsOnlyBakker(): void {
		$seedPath = __DIR__ . '/../../../../lib/Settings/register.d/hr-seed.json';
		$decoded = json_decode((string)file_get_contents($seedPath), true);
		$objects = ($decoded['components']['objects'] ?? []);

		$stagiairViolators = [];
		$contractViolators = [];
		$sawBbl = false;
		foreach ($objects as $object) {
			$schema = (string)($object['@self']['schema'] ?? '');
			$slug = (string)($object['@self']['slug'] ?? '(unknown)');

			if ($schema === 'Stagiair') {
				$ruleIds = array_map(static fn ($v) => $v->ruleId, RuleEngine::evaluate('Stagiair', $object));
				if (in_array('nl-bpv-overeenkomst-vereist', $ruleIds, true) === true) {
					$stagiairViolators[] = $slug;
				}

				continue;
			}

			if ($schema === 'EmploymentContract') {
				if ((string)($object['type'] ?? '') === 'bbl') {
					$sawBbl = true;
				}

				$ruleIds = array_map(static fn ($v) => $v->ruleId, RuleEngine::evaluate('EmploymentContract', $object));
				if (in_array('nl-bpv-overeenkomst-vereist', $ruleIds, true) === true) {
					$contractViolators[] = $slug;
				}
			}
		}//end foreach

		$this->assertSame(['stagiair-bakker'], $stagiairViolators, 'Exactly stagiair-bakker must fire the BPV rule.');
		$this->assertSame([], $contractViolators, 'No seeded EmploymentContract (bbl or otherwise) may fire the BPV rule — the seeded bbl contract is signed.');
		$this->assertTrue($sawBbl, 'Expected the seeded type: bbl EmploymentContract to be present.');

	}//end testRealSeedPopulationFlagsOnlyBakker()

	/**
	 * The catalogue exposes the rule as machine-checkable and the engine as
	 * enforceable, at the bumped catalogue version.
	 *
	 * @return void
	 */
	public function testRuleIsMachineCheckableAndEnforceable(): void {
		$machineCheckable = array_column(\OCA\Hrmq\Standards\RuleCatalogue::machineCheckable(), 'id');
		$this->assertContains('nl-bpv-overeenkomst-vereist', $machineCheckable);

		$enforceable = RuleEngine::checkedRuleIds();
		$this->assertContains('nl-bpv-overeenkomst-vereist', $enforceable);

	}//end testRuleIsMachineCheckableAndEnforceable()

}//end class
