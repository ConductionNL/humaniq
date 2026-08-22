<?php

/**
 * Unit tests for the HR21 normfunctie-schaal consistency check (NlHr21Checks).
 *
 * Drives the `nl-hr21-schaal-consistentie` predicate through the REAL
 * RuleEngine + RuleCatalogue corpus (not the raw closure) so the test also
 * proves the corpus rule exists, is machine-checkable, and is reachable —
 * i.e. NOT an orphaned capability (REQ-HR21-003). Also covers the
 * `SeedsObjects` illustrative Normfunctie seed (REQ-HR21-001/REQ-HR21-005):
 * every seed row is well-formed and idempotent re-seeding never duplicates.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
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
 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-001
 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlHr21Checks;
use OCA\Humaniq\Standards\RuleCatalogue;
use OCA\Humaniq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlHr21Checks, driven through the real RuleEngine.
 *
 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
 */
class NlHr21ChecksTest extends TestCase {

	/**
	 * Reset every statically-memoised layer so each test loads the real
	 * catalogue/corpus fresh.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		RuleCatalogue::reset();

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();
		RuleCatalogue::reset();

	}//end tearDown()

	/**
	 * Whether the evaluated violations contain a given rule id.
	 *
	 * @param array<int, \OCA\Humaniq\Standards\Violation> $violations The violations.
	 * @param string $ruleId The rule id to look for.
	 *
	 * @return bool
	 */
	private function hasViolation(array $violations, string $ruleId): bool {
		foreach ($violations as $violation) {
			if ($violation->ruleId === $ruleId) {
				return true;
			}
		}

		return false;
	}//end hasViolation()

	/**
	 * An hr21 context with one Normfunctie's `{caoSchaal, caoSchaalVerified}`.
	 *
	 * @param string $normfunctieId The Normfunctie id.
	 * @param string $caoSchaal Its mapped schaal.
	 * @param bool $caoSchaalVerified Whether the mapping is verified.
	 *
	 * @return array<string, mixed>
	 */
	private function context(string $normfunctieId, string $caoSchaal, bool $caoSchaalVerified): array {
		return [
			'hr21' => [
				'normfunctiesById' => [
					$normfunctieId => ['caoSchaal' => $caoSchaal, 'caoSchaalVerified' => $caoSchaalVerified],
				],
			],
		];

	}//end context()

	/**
	 * The rule is registered against EmploymentContract AND wired to the
	 * corpus — i.e. reachable, not an orphaned predicate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public function testSchaalConsistentieCheckIsReachableFromTheEngine(): void {
		$this->assertArrayHasKey('nl-hr21-schaal-consistentie', (NlHr21Checks::checks()['EmploymentContract'] ?? []));
		$this->assertContains('nl-hr21-schaal-consistentie', RuleEngine::checkedRuleIds());

	}//end testSchaalConsistentieCheckIsReachableFromTheEngine()

	/**
	 * A contract whose own caoSchaal disagrees with its verified normfunctie's
	 * mapped schaal is a mandatory violation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public function testMismatchedSchaalRaisesMandatoryViolation(): void {
		$contract = ['normfunctieId' => 'nf-1', 'caoSchaal' => '6'];
		$violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context('nf-1', '8', true));

		$this->assertTrue($this->hasViolation($violations, 'nl-hr21-schaal-consistentie'));

		$rule = null;
		foreach ($violations as $violation) {
			if ($violation->ruleId === 'nl-hr21-schaal-consistentie') {
				$rule = $violation;
			}
		}

		$this->assertNotNull($rule);
		$this->assertSame('mandatory', $rule->severity);

	}//end testMismatchedSchaalRaisesMandatoryViolation()

	/**
	 * A contract whose own caoSchaal matches its verified normfunctie's mapped
	 * schaal passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public function testMatchingSchaalPasses(): void {
		$contract = ['normfunctieId' => 'nf-1', 'caoSchaal' => '8'];
		$violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context('nf-1', '8', true));

		$this->assertFalse($this->hasViolation($violations, 'nl-hr21-schaal-consistentie'));

	}//end testMatchingSchaalPasses()

	/**
	 * A contract with no normfunctieId is out of scope — vacuous, regardless
	 * of its own caoSchaal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public function testNullNormfunctieIdIsVacuous(): void {
		$contract = ['normfunctieId' => null, 'caoSchaal' => '6'];
		$violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context('nf-1', '8', true));

		$this->assertFalse($this->hasViolation($violations, 'nl-hr21-schaal-consistentie'));

	}//end testNullNormfunctieIdIsVacuous()

	/**
	 * An unresolvable normfunctieId (not present in the audit context) is
	 * vacuous — nothing decidable from this object alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public function testUnresolvableNormfunctieIsVacuous(): void {
		$contract = ['normfunctieId' => 'no-such-normfunctie', 'caoSchaal' => '6'];
		$violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context('nf-1', '8', true));

		$this->assertFalse($this->hasViolation($violations, 'nl-hr21-schaal-consistentie'));

	}//end testUnresolvableNormfunctieIsVacuous()

	/**
	 * A placeholder (unverified) mapping is advisory, never a false mandatory
	 * violation, even when the contract's caoSchaal disagrees with the
	 * unverified mapped schaal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public function testUnverifiedMappingIsVacuousEvenOnMismatch(): void {
		$contract = ['normfunctieId' => 'nf-1', 'caoSchaal' => '6'];
		$violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context('nf-1', '8', false));

		$this->assertFalse($this->hasViolation($violations, 'nl-hr21-schaal-consistentie'));

	}//end testUnverifiedMappingIsVacuousEvenOnMismatch()

	/**
	 * The illustrative Normfunctie seed: every row is well-formed (natural
	 * key, no full ~150-catalog claim implied by row count), and exactly one
	 * row is `caoSchaalVerified: true` (the deliberate, documented proof-case
	 * exception — tasks.md #11), every other row `caoSchaalVerified: false`
	 * (the honesty bar: an unconfirmed mapping never silently reads as
	 * confirmed).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-001
	 */
	public function testSeedIsIllustrativeSubsetWithOneDocumentedVerifiedException(): void {
		$rows = (NlHr21Checks::seedObjects()['Normfunctie'] ?? []);
		$this->assertNotEmpty($rows);
		$this->assertLessThanOrEqual(6, count($rows));
		$this->assertGreaterThanOrEqual(4, count($rows));

		$verifiedCount = 0;
		$codes = [];
		foreach ($rows as $row) {
			$this->assertNotEmpty((string)($row['functiecode'] ?? ''));
			$this->assertNotEmpty((string)($row['naam'] ?? ''));
			$this->assertNotEmpty((string)($row['functiegroep'] ?? ''));
			$this->assertNotEmpty((string)($row['caoSchaal'] ?? ''));
			$this->assertNotEmpty((string)($row['caoSchaalSource'] ?? ''));
			$codes[] = $row['functiecode'];

			if (($row['caoSchaalVerified'] ?? false) === true) {
				$verifiedCount++;
				// The single verified row must document that it's a proof
				// case, not an actual VNG/HR21 confirmation.
				$this->assertStringContainsString('proof-case', (string)$row['caoSchaalSource']);
			}
		}

		$this->assertSame(1, $verifiedCount, 'exactly one seeded row is the documented verified proof-case exception');
		$this->assertSame(count($codes), count(array_unique($codes)), 'no duplicate functiecode in the seed');

	}//end testSeedIsIllustrativeSubsetWithOneDocumentedVerifiedException()

	/**
	 * Spans at least 2 hoofdprocessen (design.md Seed Data: "2-3
	 * hoofdprocessen").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-005
	 */
	public function testSeedSpansMultipleHoofdprocessen(): void {
		$rows = (NlHr21Checks::seedObjects()['Normfunctie'] ?? []);
		$functiegroepen = array_unique(array_map(static fn (array $r): string => (string)$r['functiegroep'], $rows));

		$this->assertGreaterThanOrEqual(2, count($functiegroepen));

	}//end testSeedSpansMultipleHoofdprocessen()

	/**
	 * `seedObjects()` is a pure, side-effect-free data provider: calling it
	 * twice yields byte-identical rows (no randomness, no accumulating
	 * state), so the "create only when the type is empty" seeder discipline
	 * (RuleTestDataSeeder::createMissingSamples) never sees drift across
	 * repeated calls and a re-seed never produces a duplicate row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-001
	 */
	public function testSeedObjectsIsIdempotentAcrossCalls(): void {
		$first = NlHr21Checks::seedObjects();
		$second = NlHr21Checks::seedObjects();

		$this->assertSame($first, $second);

		$codes = array_map(static fn (array $r): string => (string)$r['functiecode'], ($first['Normfunctie'] ?? []));
		$this->assertSame(count($codes), count(array_unique($codes)), 'no duplicate functiecode across repeated calls');

	}//end testSeedObjectsIsIdempotentAcrossCalls()

}//end class
