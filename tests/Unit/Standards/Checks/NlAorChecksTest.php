<?php

/**
 * Unit tests for the ambtenarenrecht (AOR) presence checks (NlAorChecks).
 *
 * Pins the two `ambtenarenwet-2017` predicates (spec.md REQ-AOR-002 /
 * REQ-AOR-003), both anchored on `Employee` and both presence-only (the
 * `nl-gebruikelijkloon-norm` MVP precedent):
 *
 * - `nl-ambtenaar-eed-vereist`: vacuous when `publicSectorRegime` is null,
 *   else violates when `ambtseedAfgelegdOp` is empty.
 * - `nl-ambtenaar-nevenwerkzaamheden-melding`: vacuous when
 *   `publicSectorRegime` is null, else violates when `nevenwerkzaamhedenGemeld`
 *   is not `true`.
 *
 * The suite drives the raw predicates, then the REAL `RuleEngine::evaluate()`
 * (catalogue + auto-discovered CheckProviders) proving both rules are genuinely
 * reachable and carry severity `mandatory`, and finally loads the actual
 * `lib/Settings/register.d/hr-seed.json` and asserts neither rule fires for any
 * pre-existing `publicSectorRegime: null` Employee (REQ-AOR-004): the two rules
 * are provably vacuous for the entire pre-existing seed population.
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
 * @spec openspec/changes/aor-ambtenarenrecht/specs/aor-ambtenarenrecht/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlAorChecks;
use OCA\Humaniq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlAorChecks (raw predicates + through the REAL RuleEngine).
 *
 * @spec openspec/changes/aor-ambtenarenrecht/specs/aor-ambtenarenrecht/spec.md
 */
class NlAorChecksTest extends TestCase {

	/**
	 * The registered Employee predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		$this->checks = NlAorChecks::checks()['Employee'];

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();

	}//end tearDown()

	/**
	 * A minimal Employee fixture, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-aor-1',
				'lastName' => 'Test',
				'startDate' => '2024-01-01',
				'publicSectorRegime' => null,
				'ambtseedAfgelegdOp' => null,
				'nevenwerkzaamhedenGemeld' => false,
			],
			$overrides
		);

	}//end employee()

	/**
	 * REQ-AOR-002 / REQ-AOR-003 scenario: an ordinary private-sector employee
	 * (publicSectorRegime null) never violates either rule, even with both
	 * ambtenaar fields empty — the two obligations do not exist for them.
	 *
	 * @return void
	 */
	public function testPrivateSectorEmployeeIsVacuousForBothRules(): void {
		$eed = $this->checks['nl-ambtenaar-eed-vereist'];
		$neven = $this->checks['nl-ambtenaar-nevenwerkzaamheden-melding'];

		$employee = $this->employee(
			[
				'publicSectorRegime' => null,
				'ambtseedAfgelegdOp' => null,
				'nevenwerkzaamhedenGemeld' => false,
			]
		);

		$this->assertTrue($eed($employee), 'A private-sector employee must never fire the eed rule.');
		$this->assertTrue($neven($employee), 'A private-sector employee must never fire the nevenwerkzaamheden rule.');

	}//end testPrivateSectorEmployeeIsVacuousForBothRules()

	/**
	 * An absent `publicSectorRegime` key (not even present, the pre-existing
	 * seed shape) is also vacuous for both rules.
	 *
	 * @return void
	 */
	public function testAbsentRegimeKeyIsVacuousForBothRules(): void {
		$eed = $this->checks['nl-ambtenaar-eed-vereist'];
		$neven = $this->checks['nl-ambtenaar-nevenwerkzaamheden-melding'];

		$employee = ['id' => 'emp-legacy', 'lastName' => 'Legacy', 'startDate' => '2020-01-01'];

		$this->assertTrue($eed($employee));
		$this->assertTrue($neven($employee));

	}//end testAbsentRegimeKeyIsVacuousForBothRules()

	/**
	 * REQ-AOR-002 scenario: a genormaliseerd ambtenaar with the ambtseed date
	 * on file passes the eed rule; missing that date violates it.
	 *
	 * @return void
	 */
	public function testEedRulePassAndViolationForGenormaliseerd(): void {
		$eed = $this->checks['nl-ambtenaar-eed-vereist'];

		$passing = $this->employee(['publicSectorRegime' => 'genormaliseerd', 'ambtseedAfgelegdOp' => '2021-09-01']);
		$missing = $this->employee(['publicSectorRegime' => 'genormaliseerd', 'ambtseedAfgelegdOp' => null]);

		$this->assertTrue($eed($passing), 'A recorded ambtseed date passes.');
		$this->assertFalse($eed($missing), 'A null ambtseed date on an ambtenaar violates.');

	}//end testEedRulePassAndViolationForGenormaliseerd()

	/**
	 * REQ-AOR-002 scenario: the special-status (`ambtenarenwet`) regime also
	 * violates the eed rule when the date is missing and passes when present —
	 * the presence check does not depend on which live regime it is.
	 *
	 * @return void
	 */
	public function testEedRulePassAndViolationForAmbtenarenwet(): void {
		$eed = $this->checks['nl-ambtenaar-eed-vereist'];

		$passing = $this->employee(['publicSectorRegime' => 'ambtenarenwet', 'ambtseedAfgelegdOp' => '2019-04-01']);
		$missing = $this->employee(['publicSectorRegime' => 'ambtenarenwet', 'ambtseedAfgelegdOp' => null]);

		$this->assertTrue($eed($passing));
		$this->assertFalse($eed($missing));

	}//end testEedRulePassAndViolationForAmbtenarenwet()

	/**
	 * A whitespace-only ambtseed date does NOT count as present.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyEedDateDoesNotSatisfy(): void {
		$eed = $this->checks['nl-ambtenaar-eed-vereist'];

		$employee = $this->employee(['publicSectorRegime' => 'ambtenarenwet', 'ambtseedAfgelegdOp' => '   ']);

		$this->assertFalse($eed($employee));

	}//end testWhitespaceOnlyEedDateDoesNotSatisfy()

	/**
	 * REQ-AOR-003 scenario: an ambtenaar with the nevenwerkzaamheden
	 * attestation on file passes; a missing/false attestation violates.
	 *
	 * @return void
	 */
	public function testNevenwerkzaamhedenRulePassAndViolation(): void {
		$neven = $this->checks['nl-ambtenaar-nevenwerkzaamheden-melding'];

		$onFile = $this->employee(['publicSectorRegime' => 'ambtenarenwet', 'nevenwerkzaamhedenGemeld' => true]);
		$missing = $this->employee(['publicSectorRegime' => 'genormaliseerd', 'nevenwerkzaamhedenGemeld' => false]);

		$this->assertTrue($neven($onFile), 'An on-file disclosure attestation passes.');
		$this->assertFalse($neven($missing), 'A false attestation on an ambtenaar violates.');

	}//end testNevenwerkzaamhedenRulePassAndViolation()

	/**
	 * Only an exact boolean `true` satisfies the nevenwerkzaamheden rule — a
	 * truthy string or the integer 1 does NOT (presence-of-attestation, not a
	 * loose cast).
	 *
	 * @return void
	 */
	public function testNevenwerkzaamhedenRequiresExactTrue(): void {
		$neven = $this->checks['nl-ambtenaar-nevenwerkzaamheden-melding'];

		$this->assertFalse($neven($this->employee(['publicSectorRegime' => 'ambtenarenwet', 'nevenwerkzaamhedenGemeld' => 1])));
		$this->assertFalse($neven($this->employee(['publicSectorRegime' => 'ambtenarenwet', 'nevenwerkzaamhedenGemeld' => 'true'])));
		$this->assertTrue($neven($this->employee(['publicSectorRegime' => 'ambtenarenwet', 'nevenwerkzaamhedenGemeld' => true])));

	}//end testNevenwerkzaamhedenRequiresExactTrue()

	/**
	 * REQ-AOR-002 — the missing-eed scenario driven through the REAL
	 * `RuleEngine::evaluate()` (catalogue + auto-discovered CheckProviders),
	 * proving `nl-ambtenaar-eed-vereist` is genuinely reachable via
	 * `occ humaniq:rules:audit` and carries severity `mandatory`.
	 *
	 * @return void
	 */
	public function testRealRuleEngineFiresMandatoryEedViolation(): void {
		$employee = $this->employee(
			[
				'publicSectorRegime' => 'ambtenarenwet',
				'ambtseedAfgelegdOp' => null,
				'nevenwerkzaamhedenGemeld' => true,
			]
		);

		$violations = RuleEngine::evaluate('Employee', $employee);
		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);

		$this->assertContains('nl-ambtenaar-eed-vereist', $ruleIds, 'The real RuleEngine must fire nl-ambtenaar-eed-vereist for an ambtenaar with no ambtseed.');
		$this->assertNotContains('nl-ambtenaar-nevenwerkzaamheden-melding', $ruleIds, 'The disclosure rule must stay silent when the attestation is on file.');

		$violation = $violations[array_search('nl-ambtenaar-eed-vereist', $ruleIds, true)];
		$this->assertSame('mandatory', $violation->severity, 'nl-ambtenaar-eed-vereist must be severity mandatory.');

	}//end testRealRuleEngineFiresMandatoryEedViolation()

	/**
	 * REQ-AOR-003 — the missing-disclosure scenario through the REAL
	 * RuleEngine, proving `nl-ambtenaar-nevenwerkzaamheden-melding` is reachable
	 * and mandatory.
	 *
	 * @return void
	 */
	public function testRealRuleEngineFiresMandatoryNevenwerkzaamhedenViolation(): void {
		$employee = $this->employee(
			[
				'publicSectorRegime' => 'genormaliseerd',
				'ambtseedAfgelegdOp' => '2021-09-01',
				'nevenwerkzaamhedenGemeld' => false,
			]
		);

		$violations = RuleEngine::evaluate('Employee', $employee);
		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);

		$this->assertContains('nl-ambtenaar-nevenwerkzaamheden-melding', $ruleIds);
		$this->assertNotContains('nl-ambtenaar-eed-vereist', $ruleIds, 'The eed rule must stay silent when the date is on file.');

		$violation = $violations[array_search('nl-ambtenaar-nevenwerkzaamheden-melding', $ruleIds, true)];
		$this->assertSame('mandatory', $violation->severity);

	}//end testRealRuleEngineFiresMandatoryNevenwerkzaamhedenViolation()

	/**
	 * REQ-AOR-002 / REQ-AOR-003 — a fully-compliant ambtenaar produces neither
	 * violation through the REAL RuleEngine.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentForACompliantAmbtenaar(): void {
		$employee = $this->employee(
			[
				'publicSectorRegime' => 'ambtenarenwet',
				'ambtseedAfgelegdOp' => '2019-04-01',
				'nevenwerkzaamhedenGemeld' => true,
			]
		);

		$violations = RuleEngine::evaluate('Employee', $employee);
		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);

		$this->assertNotContains('nl-ambtenaar-eed-vereist', $ruleIds);
		$this->assertNotContains('nl-ambtenaar-nevenwerkzaamheden-melding', $ruleIds);

	}//end testRealRuleEngineIsSilentForACompliantAmbtenaar()

	/**
	 * REQ-AOR-004 — the entire PRE-EXISTING seeded Employee population (every
	 * Employee in the real hr-seed.json that carries no publicSectorRegime, i.e.
	 * every seed that predates this change) fires neither new rule through the
	 * REAL RuleEngine. This is the vacuous-for-the-population guarantee the
	 * spec's "pre-existing seed population stays silent" scenario requires.
	 *
	 * @return void
	 */
	public function testEveryPrivateSectorSeedEmployeeStaysSilent(): void {
		$seedPath = __DIR__ . '/../../../../lib/Settings/register.d/hr-seed.json';
		$decoded = json_decode((string)file_get_contents($seedPath), true);
		$objects = ($decoded['components']['objects'] ?? []);

		$checkedAtLeastOne = false;
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? null) !== 'Employee') {
				continue;
			}

			// Only the pre-existing population — those with no ambtenaar regime.
			if (($object['publicSectorRegime'] ?? null) !== null) {
				continue;
			}

			$checkedAtLeastOne = true;
			$violations = RuleEngine::evaluate('Employee', $object);
			$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);

			$slug = (string)($object['@self']['slug'] ?? '(unknown)');
			$this->assertNotContains('nl-ambtenaar-eed-vereist', $ruleIds, "Pre-existing seed {$slug} must not fire nl-ambtenaar-eed-vereist.");
			$this->assertNotContains('nl-ambtenaar-nevenwerkzaamheden-melding', $ruleIds, "Pre-existing seed {$slug} must not fire nl-ambtenaar-nevenwerkzaamheden-melding.");
		}

		$this->assertTrue($checkedAtLeastOne, 'Expected at least one pre-existing private-sector Employee seed to exercise.');

	}//end testEveryPrivateSectorSeedEmployeeStaysSilent()

	/**
	 * REQ-AOR-004 — the three NEW seeded ambtenaar Employees reproduce exactly
	 * one eed violation (the eed-ontbreekt seed) and zero nevenwerkzaamheden
	 * violations across the three, driven through the REAL RuleEngine.
	 *
	 * @return void
	 */
	public function testNewAmbtenaarSeedsReproduceExactlyOneEedViolation(): void {
		$seedPath = __DIR__ . '/../../../../lib/Settings/register.d/hr-seed.json';
		$decoded = json_decode((string)file_get_contents($seedPath), true);
		$objects = ($decoded['components']['objects'] ?? []);

		$newSlugs = [
			'employee-ambtenaar-genormaliseerd',
			'employee-ambtenaar-politie',
			'employee-ambtenaar-eed-ontbreekt',
		];

		$eedViolations = 0;
		$nevenViolations = 0;
		$seen = [];
		foreach ($objects as $object) {
			$slug = (string)($object['@self']['slug'] ?? '');
			if (in_array($slug, $newSlugs, true) === false) {
				continue;
			}

			$seen[] = $slug;
			$ruleIds = array_map(static fn ($v) => $v->ruleId, RuleEngine::evaluate('Employee', $object));

			$eedViolations += (int)in_array('nl-ambtenaar-eed-vereist', $ruleIds, true);
			$nevenViolations += (int)in_array('nl-ambtenaar-nevenwerkzaamheden-melding', $ruleIds, true);
		}

		sort($seen);
		$expected = $newSlugs;
		sort($expected);
		$this->assertSame($expected, $seen, 'All three new ambtenaar seeds must be present exactly once.');
		$this->assertSame(1, $eedViolations, 'Exactly one eed violation (the eed-ontbreekt seed) is expected across the three new seeds.');
		$this->assertSame(0, $nevenViolations, 'Zero nevenwerkzaamheden violations are expected across the three new seeds.');

	}//end testNewAmbtenaarSeedsReproduceExactlyOneEedViolation()

}//end class
