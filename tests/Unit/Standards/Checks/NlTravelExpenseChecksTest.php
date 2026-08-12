<?php

/**
 * Unit tests for the onbelaste-kilometervergoeding check (NlTravelExpenseChecks).
 *
 * Drives the nl-reiskosten-onbelast-tarief predicate through the REAL
 * RuleEngine + RuleCatalogue corpus (not the raw closure) so the test also
 * proves the corpus rule exists, is machine-checkable, and is reachable --
 * i.e. NOT an orphaned capability (mileage-rules REQ-MILE-002 / REQ-MILE-003):
 * an over-rate mileage Expense raises a mandatory violation, an at-or-under
 * rate claim passes, and every out-of-scope shape (non-travel category,
 * missing/invalid travelType, absent or non-positive distanceKm, non-numeric
 * amount) is vacuously satisfied.
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
 * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-002
 * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlTravelExpenseChecks;
use OCA\Hrmq\Standards\RuleCatalogue;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlTravelExpenseChecks, driven through the real RuleEngine.
 *
 * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
 */
class NlTravelExpenseChecksTest extends TestCase
{

    private const RULE_ID = 'nl-reiskosten-onbelast-tarief';


    /**
     * Reset every statically-memoised layer so each test loads the real
     * catalogue/corpus fresh.
     *
     * @return void
     */
    protected function setUp(): void
    {
        RuleEngine::reset();
        RuleCatalogue::reset();

    }//end setUp()


    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RuleEngine::reset();
        RuleCatalogue::reset();

    }//end tearDown()


    /**
     * Whether the evaluated violations contain a given rule id.
     *
     * @param array<int, \OCA\Hrmq\Standards\Violation> $violations The violations.
     * @param string                                    $ruleId     The rule id to look for.
     *
     * @return bool
     */
    private function hasViolation(array $violations, string $ruleId): bool
    {
        foreach ($violations as $violation) {
            if ($violation->ruleId === $ruleId) {
                return true;
            }
        }

        return false;

    }//end hasViolation()


    /**
     * The single violation for a given rule id, or null.
     *
     * @param array<int, \OCA\Hrmq\Standards\Violation> $violations The violations.
     * @param string                                    $ruleId     The rule id.
     *
     * @return \OCA\Hrmq\Standards\Violation|null
     */
    private function violationFor(array $violations, string $ruleId): mixed
    {
        foreach ($violations as $violation) {
            if ($violation->ruleId === $ruleId) {
                return $violation;
            }
        }

        return null;

    }//end violationFor()


    /**
     * The rule is registered against Expense AND wired to the corpus -- i.e.
     * reachable through RuleEngine::providers()'s auto-discovery glob, not an
     * orphaned predicate (REQ-MILE-003 "New provider needs no RuleEngine
     * registration").
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testRuleIsReachableFromTheEngine(): void
    {
        $this->assertArrayHasKey(self::RULE_ID, (NlTravelExpenseChecks::checks()['Expense'] ?? []));
        $this->assertContains(self::RULE_ID, RuleEngine::checkedRuleIds());
        $this->assertContains('Expense', RuleEngine::supportedTypes());

    }//end testRuleIsReachableFromTheEngine()


    /**
     * The rate is real, machine-checkable catalogue data, not a hardcoded
     * literal in the predicate (REQ-MILE-002 "The rate is read from the
     * catalogue, not hardcoded").
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-002
     */
    public function testRateIsVersionedCatalogueData(): void
    {
        $rule = null;
        foreach (RuleCatalogue::all() as $candidate) {
            if (($candidate['id'] ?? '') === self::RULE_ID) {
                $rule = $candidate;
                break;
            }
        }

        $this->assertNotNull($rule, 'nl-reiskosten-onbelast-tarief must exist in the corpus.');
        $this->assertTrue($rule['machineCheckable']);
        $this->assertSame('mandatory', $rule['severity']);
        $this->assertSame(0.23, $rule['parameters']['rateEurPerKm']);
        $this->assertContains(self::RULE_ID, array_column(RuleCatalogue::machineCheckable(), 'id'));

    }//end testRateIsVersionedCatalogueData()


    /**
     * REQ-MILE-003 "Over-rate mileage claim violates": EUR 0,30/km on a
     * business mileage claim raises a mandatory violation.
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testOverRateMileageClaimViolates(): void
    {
        $expense    = [
            'category'   => 'travel',
            'travelType' => 'business',
            'distanceKm' => 100,
            'amount'     => 30.00,
        ];
        $violations = RuleEngine::evaluate('Expense', $expense, ['jurisdiction' => 'NL']);

        $violation = $this->violationFor($violations, self::RULE_ID);
        $this->assertNotNull($violation, 'A per-km reimbursement above the onbelast rate must raise nl-reiskosten-onbelast-tarief.');
        $this->assertSame('mandatory', $violation->severity);

    }//end testOverRateMileageClaimViolates()


    /**
     * REQ-MILE-003 "At-or-under-rate mileage claim passes": the same claim at
     * EUR 0,23/km (exactly the onbelast rate) raises no violation.
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testAtRateMileageClaimPasses(): void
    {
        $expense    = [
            'category'   => 'travel',
            'travelType' => 'business',
            'distanceKm' => 100,
            'amount'     => 23.00,
        ];
        $violations = RuleEngine::evaluate('Expense', $expense, ['jurisdiction' => 'NL']);

        $this->assertFalse($this->hasViolation($violations, self::RULE_ID));

    }//end testAtRateMileageClaimPasses()


    /**
     * A commute (woon-werkverkeer) claim under the rate also passes -- both
     * travelType values are in scope of the same rate.
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testUnderRateCommuteClaimPasses(): void
    {
        $expense    = [
            'category'   => 'travel',
            'travelType' => 'commute',
            'distanceKm' => 200,
            'amount'     => 20.00,
        ];
        $violations = RuleEngine::evaluate('Expense', $expense, ['jurisdiction' => 'NL']);

        $this->assertFalse($this->hasViolation($violations, self::RULE_ID));

    }//end testUnderRateCommuteClaimPasses()


    /**
     * REQ-MILE-003 "Non-mileage Expense is vacuously out of scope": a
     * category meals expense with no travelType/distanceKm is never flagged.
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testNonTravelCategoryIsVacuous(): void
    {
        $expense    = ['category' => 'meals', 'amount' => 500.00];
        $violations = RuleEngine::evaluate('Expense', $expense, ['jurisdiction' => 'NL']);

        $this->assertFalse($this->hasViolation($violations, self::RULE_ID));

    }//end testNonTravelCategoryIsVacuous()


    /**
     * A travel-category claim with no travelType is vacuous (not every
     * travel expense is a per-km mileage claim, e.g. train/hotel).
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testMissingTravelTypeIsVacuous(): void
    {
        $expense    = ['category' => 'travel', 'amount' => 999.00];
        $violations = RuleEngine::evaluate('Expense', $expense, ['jurisdiction' => 'NL']);

        $this->assertFalse($this->hasViolation($violations, self::RULE_ID));

    }//end testMissingTravelTypeIsVacuous()


    /**
     * An invalid (not business/commute) travelType is vacuous, same as
     * missing.
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testInvalidTravelTypeIsVacuous(): void
    {
        $expense    = [
            'category'   => 'travel',
            'travelType' => 'holiday',
            'distanceKm' => 100,
            'amount'     => 999.00,
        ];
        $violations = RuleEngine::evaluate('Expense', $expense, ['jurisdiction' => 'NL']);

        $this->assertFalse($this->hasViolation($violations, self::RULE_ID));

    }//end testInvalidTravelTypeIsVacuous()


    /**
     * Absent or non-positive (zero/negative) distanceKm is vacuous -- a
     * division by zero must never be attempted.
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testAbsentOrNonPositiveDistanceKmIsVacuous(): void
    {
        $missing = ['category' => 'travel', 'travelType' => 'business', 'amount' => 999.00];
        $zero    = ['category' => 'travel', 'travelType' => 'business', 'distanceKm' => 0, 'amount' => 999.00];
        $negative = ['category' => 'travel', 'travelType' => 'business', 'distanceKm' => -5, 'amount' => 999.00];

        $this->assertFalse($this->hasViolation(RuleEngine::evaluate('Expense', $missing, ['jurisdiction' => 'NL']), self::RULE_ID));
        $this->assertFalse($this->hasViolation(RuleEngine::evaluate('Expense', $zero, ['jurisdiction' => 'NL']), self::RULE_ID));
        $this->assertFalse($this->hasViolation(RuleEngine::evaluate('Expense', $negative, ['jurisdiction' => 'NL']), self::RULE_ID));

    }//end testAbsentOrNonPositiveDistanceKmIsVacuous()


    /**
     * A non-numeric amount is vacuous -- never a fatal type error.
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testNonNumericAmountIsVacuous(): void
    {
        $expense    = [
            'category'   => 'travel',
            'travelType' => 'business',
            'distanceKm' => 100,
            'amount'     => null,
        ];
        $violations = RuleEngine::evaluate('Expense', $expense, ['jurisdiction' => 'NL']);

        $this->assertFalse($this->hasViolation($violations, self::RULE_ID));

    }//end testNonNumericAmountIsVacuous()


    /**
     * The predicate's own signature is `fn(array $o, array $context): bool`
     * per the CheckProvider contract -- it tolerates an empty/missing
     * $context (the same calling convention RuleEngine::evaluate() uses) and
     * never throws, which is what lets a genuinely unreadable catalogue entry
     * (rule id missing from the corpus, or a non-array/non-numeric
     * `parameters.rateEurPerKm`) degrade to vacuous rather than a fatal error
     * -- `rateEurPerKm()`'s own `is_array()`/`is_numeric()` guards return null
     * for exactly that case, and `onbelastTariefSatisfied()` treats a null
     * rate as vacuous, mirroring NlWageTaxFilingChecks::tijdvakcodeParameters().
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testPredicateContractToleratesMissingContext(): void
    {
        $checks  = NlTravelExpenseChecks::checks()['Expense'][self::RULE_ID];
        $expense = [
            'category'   => 'travel',
            'travelType' => 'business',
            'distanceKm' => 100,
            'amount'     => 30.00,
        ];

        $this->assertIsBool($checks($expense, []));
        $this->assertFalse($checks($expense, []), 'EUR 0,30/km still violates when $context is empty -- the predicate does not depend on context.');

    }//end testPredicateContractToleratesMissingContext()


}//end class
