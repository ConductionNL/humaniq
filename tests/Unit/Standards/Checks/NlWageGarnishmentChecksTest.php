<?php

/**
 * Unit tests for NlWageGarnishmentChecks.
 *
 * Pins both loonbeslag predicates (design.md D6, spec.md REQ-BESLAG-002/
 * -005/-007): `nl-loonbeslag-beslagvrije-voet-floor` (Payslip, vacuous when
 * `loonbeslagId` is null or the reference is dangling, else cents-exact
 * `nettoPay >= beslagvrijeVoet`) and `nl-loonbeslag-single-active`
 * (Loonbeslag, vacuous when the record is not `actief` or the employee has
 * zero/one `actief` Loonbeslag, else a violation for every record in an
 * overlapping-effective-range group of two or more `actief` records for the
 * same employee). The suite closes with a REAL `RuleEngine::evaluate()`
 * integration test proving both rules are genuinely reachable via
 * `occ hrmq:rules:audit`, not an orphaned capability.
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
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-005
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlWageGarnishmentChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlWageGarnishmentChecks (raw predicates + through the REAL RuleEngine).
 *
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-005
 */
class NlWageGarnishmentChecksTest extends TestCase
{

    /**
     * The registered predicates.
     *
     * @var array<string, array<string, callable>>
     */
    private array $checks;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        RuleEngine::reset();
        $this->checks = NlWageGarnishmentChecks::checks();

    }//end setUp()


    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RuleEngine::reset();

    }//end tearDown()


    /**
     * A Loonbeslag fixture, overridable per test.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function loonbeslag(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'              => 'lb-1',
                'employeeId'      => 'emp-1',
                'creditor'        => 'Gerechtsdeurwaarderskantoor Van Dijk',
                'dossierRef'      => 'GDW-2026-00123',
                'totalClaim'      => 4200.00,
                'orderedAmount'   => 800.00,
                'beslagvrijeVoet' => 2950.00,
                'status'          => 'actief',
                'effectiveFrom'   => '2026-01-01',
                'effectiveTo'     => null,
            ],
            $overrides
        );

    }//end loonbeslag()


    /**
     * A Payslip fixture, overridable per test.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function payslip(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'           => 'ps-1',
                'employeeId'   => 'emp-1',
                'period'       => '2026-02',
                'nettoPay'     => 2950.00,
                'loonbeslagId' => 'lb-1',
                'loonbeslag'   => 131.17,
            ],
            $overrides
        );

    }//end payslip()


    /**
     * A `context['payroll']['loonbeslagenById']` fixture.
     *
     * @param array<int, array<string, mixed>> $loonbeslagen The Loonbeslag rows.
     *
     * @return array<string, mixed>
     */
    private function context(array $loonbeslagen): array
    {
        $byId = [];
        foreach ($loonbeslagen as $loonbeslag) {
            $byId[(string) $loonbeslag['id']] = $loonbeslag;
        }

        return ['jurisdiction' => 'NL', 'payroll' => ['loonbeslagenById' => $byId]];

    }//end context()


    /**
     * REQ-BESLAG-004 Scenario 1 — a Payslip with `loonbeslagId` null is
     * vacuously compliant (out of scope).
     *
     * @return void
     */
    public function testNullLoonbeslagIdIsVacuouslyCompliant(): void
    {
        $check = $this->checks['Payslip']['nl-loonbeslag-beslagvrije-voet-floor'];

        self::assertTrue($check($this->payslip(['loonbeslagId' => null, 'nettoPay' => 1.00]), []));
        self::assertTrue($check($this->payslip(['loonbeslagId' => '', 'nettoPay' => 1.00]), $this->context([$this->loonbeslag()])));

    }//end testNullLoonbeslagIdIsVacuouslyCompliant()


    /**
     * A Payslip whose `loonbeslagId` cannot be resolved (dangling reference)
     * is vacuously compliant -- a different, pre-existing class of
     * data-integrity problem, not this rule's job.
     *
     * @return void
     */
    public function testDanglingLoonbeslagReferenceIsVacuouslyCompliant(): void
    {
        $check = $this->checks['Payslip']['nl-loonbeslag-beslagvrije-voet-floor'];

        self::assertTrue($check($this->payslip(['loonbeslagId' => 'lb-ghost', 'nettoPay' => 1.00]), $this->context([$this->loonbeslag()])));

    }//end testDanglingLoonbeslagReferenceIsVacuouslyCompliant()


    /**
     * REQ-BESLAG-002 — a payslip whose nettoPay sits exactly on (or above)
     * the beslagvrije voet is compliant.
     *
     * @return void
     */
    public function testNettoPayAtOrAboveTheFloorIsSatisfied(): void
    {
        $check   = $this->checks['Payslip']['nl-loonbeslag-beslagvrije-voet-floor'];
        $context = $this->context([$this->loonbeslag()]);

        self::assertTrue($check($this->payslip(['nettoPay' => 2950.00]), $context), 'Exactly on the voet is compliant (>=, not >).');
        self::assertTrue($check($this->payslip(['nettoPay' => 3031.17]), $context), 'Above the voet (a smaller orderedAmount left more headroom) is compliant.');

    }//end testNettoPayAtOrAboveTheFloorIsSatisfied()


    /**
     * REQ-BESLAG-002 Scenario 4 — a tampered payslip whose nettoPay was
     * edited to fall below the beslagvrije voet is a violation, through the
     * raw predicate.
     *
     * @return void
     */
    public function testNettoPayBelowTheFloorViolates(): void
    {
        $check   = $this->checks['Payslip']['nl-loonbeslag-beslagvrije-voet-floor'];
        $context = $this->context([$this->loonbeslag()]);

        self::assertFalse($check($this->payslip(['nettoPay' => 2949.99]), $context));

    }//end testNettoPayBelowTheFloorViolates()


    /**
     * REQ-BESLAG-002 Scenario 4, through the REAL `RuleEngine::evaluate()`
     * (catalogue + auto-discovered CheckProviders), proving
     * `nl-loonbeslag-beslagvrije-voet-floor` is genuinely reachable via
     * `occ hrmq:rules:audit` and not an orphaned capability.
     *
     * @return void
     */
    public function testRealRuleEngineFiresTheFloorViolation(): void
    {
        $context    = $this->context([$this->loonbeslag()]);
        $violations = RuleEngine::evaluate('Payslip', $this->payslip(['nettoPay' => 2000.00]), $context);

        $ruleIds = array_map(static fn($v) => $v->ruleId, $violations);
        self::assertContains('nl-loonbeslag-beslagvrije-voet-floor', $ruleIds, 'The real RuleEngine must fire the floor violation for a Payslip below its Loonbeslag beslagvrijeVoet.');

    }//end testRealRuleEngineFiresTheFloorViolation()


    /**
     * The mirror-image REAL RuleEngine check: a settled loonbeslag whose
     * nettoPay sits on the voet produces NO floor violation.
     *
     * @return void
     */
    public function testRealRuleEngineIsSilentWhenTheFloorHolds(): void
    {
        $context    = $this->context([$this->loonbeslag()]);
        $violations = RuleEngine::evaluate('Payslip', $this->payslip(['nettoPay' => 2950.00]), $context);

        $ruleIds = array_map(static fn($v) => $v->ruleId, $violations);
        self::assertNotContains('nl-loonbeslag-beslagvrije-voet-floor', $ruleIds);

    }//end testRealRuleEngineIsSilentWhenTheFloorHolds()


    /**
     * A non-`actief` Loonbeslag can never conflict for the single-active
     * check (vacuous).
     *
     * @return void
     */
    public function testNonActiefLoonbeslagIsVacuouslyCompliant(): void
    {
        $check = $this->checks['Loonbeslag']['nl-loonbeslag-single-active'];

        $concept = $this->loonbeslag(['id' => 'lb-1', 'status' => 'concept']);
        $other   = $this->loonbeslag(['id' => 'lb-2', 'status' => 'actief']);

        self::assertTrue($check($concept, $this->context([$concept, $other])));

    }//end testNonActiefLoonbeslagIsVacuouslyCompliant()


    /**
     * REQ-BESLAG-005 — the employee has zero or one `actief` Loonbeslag ->
     * vacuously compliant.
     *
     * @return void
     */
    public function testSingleActiveLoonbeslagIsVacuouslyCompliant(): void
    {
        $check = $this->checks['Loonbeslag']['nl-loonbeslag-single-active'];

        $solo = $this->loonbeslag(['id' => 'lb-1']);
        self::assertTrue($check($solo, $this->context([$solo])));

        // A second actief Loonbeslag for a DIFFERENT employee never conflicts.
        $otherEmployee = $this->loonbeslag(['id' => 'lb-2', 'employeeId' => 'emp-2']);
        self::assertTrue($check($solo, $this->context([$solo, $otherEmployee])));

    }//end testSingleActiveLoonbeslagIsVacuouslyCompliant()


    /**
     * Two `actief` Loonbeslag records for the same employee whose effective
     * ranges do NOT overlap (sequential garnishments) do not conflict.
     *
     * @return void
     */
    public function testNonOverlappingSequentialLoonbeslagenAreCompliant(): void
    {
        $check = $this->checks['Loonbeslag']['nl-loonbeslag-single-active'];

        $first  = $this->loonbeslag(['id' => 'lb-1', 'effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-06-30']);
        $second = $this->loonbeslag(['id' => 'lb-2', 'effectiveFrom' => '2026-07-01', 'effectiveTo' => null]);

        $context = $this->context([$first, $second]);

        self::assertTrue($check($first, $context));
        self::assertTrue($check($second, $context));

    }//end testNonOverlappingSequentialLoonbeslagenAreCompliant()


    /**
     * REQ-BESLAG-005 Scenario 2 — two `actief` Loonbeslag records for the
     * same employee with OVERLAPPING effective ranges are BOTH flagged,
     * through the raw predicate.
     *
     * @return void
     */
    public function testOverlappingActiveLoonbeslagenViolateBothWays(): void
    {
        $check = $this->checks['Loonbeslag']['nl-loonbeslag-single-active'];

        $first  = $this->loonbeslag(['id' => 'lb-1', 'effectiveFrom' => '2026-01-01', 'effectiveTo' => null]);
        $second = $this->loonbeslag(['id' => 'lb-2', 'effectiveFrom' => '2026-03-01', 'effectiveTo' => null]);

        $context = $this->context([$first, $second]);

        self::assertFalse($check($first, $context), 'The first record is flagged too -- both records in the conflicting group.');
        self::assertFalse($check($second, $context), 'The second (later-starting) record is flagged.');

    }//end testOverlappingActiveLoonbeslagenViolateBothWays()


    /**
     * REQ-BESLAG-005 Scenario 2, through the REAL `RuleEngine::evaluate()`,
     * proving `nl-loonbeslag-single-active` is genuinely reachable via
     * `occ hrmq:rules:audit` and not an orphaned capability.
     *
     * @return void
     */
    public function testRealRuleEngineFiresTheSingleActiveViolation(): void
    {
        $first  = $this->loonbeslag(['id' => 'lb-1', 'effectiveFrom' => '2026-01-01', 'effectiveTo' => null]);
        $second = $this->loonbeslag(['id' => 'lb-2', 'effectiveFrom' => '2026-03-01', 'effectiveTo' => null]);

        $context    = $this->context([$first, $second]);
        $violations = RuleEngine::evaluate('Loonbeslag', $first, $context);

        $ruleIds = array_map(static fn($v) => $v->ruleId, $violations);
        self::assertContains('nl-loonbeslag-single-active', $ruleIds);

    }//end testRealRuleEngineFiresTheSingleActiveViolation()


}//end class
