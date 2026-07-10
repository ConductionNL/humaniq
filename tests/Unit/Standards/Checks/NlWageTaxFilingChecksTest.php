<?php

/**
 * NlWageTaxFilingChecks unit tests
 *
 * Covers the `nl-loonaangifte-termijn` predicate's `onOrBefore()` date-comparison
 * boundary conditions (strictly before / equal / after the deadline, and the
 * unparseable-date failure path) via the public predicate exposed by checks(),
 * plus the tijdvak and retention predicates.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hrmq-test-coverage-baseline/specs/hrmq-test-coverage-baseline/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlWageTaxFilingChecks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hrmq\Standards\Checks\NlWageTaxFilingChecks
 */
final class NlWageTaxFilingChecksTest extends TestCase
{


    /**
     * Resolve a LoonaangifteFiling predicate by rule id.
     *
     * @param string $ruleId The catalogue rule id.
     *
     * @return callable
     */
    private function predicate(string $ruleId): callable
    {
        $checks = NlWageTaxFilingChecks::checks();
        $this->assertArrayHasKey('LoonaangifteFiling', $checks);
        $this->assertArrayHasKey($ruleId, $checks['LoonaangifteFiling']);
        return $checks['LoonaangifteFiling'][$ruleId];

    }//end predicate()


    /**
     * A submittedDate strictly before the deadline satisfies the termijn rule.
     *
     * @return void
     */
    public function testDeadlinePredicateTrueWhenBeforeDeadline(): void
    {
        $predicate = $this->predicate('nl-loonaangifte-termijn');
        $this->assertTrue(
            $predicate(['filingType' => 'loonaangifte', 'submittedDate' => '2026-02-20', 'deadline' => '2026-02-27'])
        );

    }//end testDeadlinePredicateTrueWhenBeforeDeadline()


    /**
     * A submittedDate equal to the deadline satisfies the rule (the boundary is
     * inclusive — `<=`).
     *
     * @return void
     */
    public function testDeadlinePredicateTrueWhenEqualToDeadline(): void
    {
        $predicate = $this->predicate('nl-loonaangifte-termijn');
        $this->assertTrue(
            $predicate(['filingType' => 'loonaangifte', 'submittedDate' => '2026-02-27', 'deadline' => '2026-02-27'])
        );

    }//end testDeadlinePredicateTrueWhenEqualToDeadline()


    /**
     * A submittedDate strictly after the deadline violates the rule.
     *
     * @return void
     */
    public function testDeadlinePredicateFalseWhenAfterDeadline(): void
    {
        $predicate = $this->predicate('nl-loonaangifte-termijn');
        $this->assertFalse(
            $predicate(['filingType' => 'loonaangifte', 'submittedDate' => '2026-03-01', 'deadline' => '2026-02-27'])
        );

    }//end testDeadlinePredicateFalseWhenAfterDeadline()


    /**
     * An unparseable date fails the comparison (strtotime returns false), so the
     * rule is treated as violated — the documented "cannot compare" behaviour.
     *
     * @return void
     */
    public function testDeadlinePredicateFalseWhenDateUnparseable(): void
    {
        $predicate = $this->predicate('nl-loonaangifte-termijn');
        $this->assertFalse(
            $predicate(['filingType' => 'loonaangifte', 'submittedDate' => 'not-a-date', 'deadline' => '2026-02-27'])
        );
        $this->assertFalse(
            $predicate(['filingType' => 'loonaangifte', 'submittedDate' => '2026-02-20', 'deadline' => ''])
        );

    }//end testDeadlinePredicateFalseWhenDateUnparseable()


    /**
     * The termijn rule short-circuits to satisfied for a non-loonaangifte filing
     * type (the predicate only governs loonaangifte filings).
     *
     * @return void
     */
    public function testDeadlinePredicateIgnoresOtherFilingTypes(): void
    {
        $predicate = $this->predicate('nl-loonaangifte-termijn');
        $this->assertTrue(
            $predicate(['filingType' => 'form-941', 'submittedDate' => '2026-03-01', 'deadline' => '2026-02-27'])
        );

    }//end testDeadlinePredicateIgnoresOtherFilingTypes()


    /**
     * The tijdvak rule requires an electronic filing with a recognised NL period.
     *
     * @return void
     */
    public function testTijdvakPredicate(): void
    {
        $predicate = $this->predicate('nl-loonaangifte-tijdvak');
        $this->assertTrue(
            $predicate(['filingType' => 'loonaangifte', 'electronicallyFiled' => true, 'tijdvak' => 'maand'])
        );
        $this->assertFalse(
            $predicate(['filingType' => 'loonaangifte', 'electronicallyFiled' => true, 'tijdvak' => 'weekly'])
        );
        $this->assertFalse(
            $predicate(['filingType' => 'loonaangifte', 'electronicallyFiled' => false, 'tijdvak' => 'maand'])
        );

    }//end testTijdvakPredicate()


    /**
     * The retention rule requires records retained at least 7 years after the end
     * of the filing period's year.
     *
     * @return void
     */
    public function testRetentionPredicate(): void
    {
        $predicate = $this->predicate('nl-loonadministratie-bewaarplicht');
        // Period 2026 => retain until at least 2033-12-31.
        $this->assertTrue(
            $predicate(['filingType' => 'loonaangifte', 'period' => '2026-01', 'retainedUntil' => '2033-12-31'])
        );
        $this->assertFalse(
            $predicate(['filingType' => 'loonaangifte', 'period' => '2026-01', 'retainedUntil' => '2030-12-31'])
        );

    }//end testRetentionPredicate()


}//end class
