<?php

/**
 * Unit tests for the administration-level WKR check (NlWkrChecks).
 *
 * Pins the `nl-wkr-eindheffing-exposure` predicate (design.md D4, spec.md
 * REQ-WKR-004): vacuous when the `$context['wkr'][administrationId][year]`
 * aggregate is absent, satisfied when `used <= available`, satisfied when
 * `used > available` AND the assessment recorded the exposure
 * (status/excess/eindheffingRate/eindheffingDue all consistent), and a
 * violation when `used > available` and the exposure was NOT recorded. The
 * available vrije ruimte is read from the REAL `lib/Standards/tables/
 * nl-2026.json` `wkr` group (never a hardcoded percentage, REQ-WKR-002) via
 * `NlWkrChecks::availableVrijeRuimteCents()`. The suite closes with a REAL
 * `RuleEngine::evaluate()` integration test (catalogue + auto-discovered
 * providers + the nl-2026 table) proving the rule is genuinely reachable, not
 * an orphaned capability.
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
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlWkrChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlWkrChecks (raw predicate + through the REAL RuleEngine).
 *
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-002
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-004
 */
class NlWkrChecksTest extends TestCase
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
        NlWkrChecks::reset();
        $this->checks = NlWkrChecks::checks();

    }//end setUp()


    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RuleEngine::reset();
        NlWkrChecks::reset();

    }//end tearDown()


    /**
     * A `context['wkr']` fixture for one (administrationId, year) aggregate.
     *
     * @param string $administrationId    The administration.
     * @param int    $year                The fiscal year.
     * @param float  $loonsom              Fiscale loonsom (euros).
     * @param float  $payslipWkrUsed       Σ Payslip.wkrUsed (euros).
     * @param float  $vrijeRuimteDeclared  Σ vrije-ruimte WkrDeclaration.amount (euros).
     *
     * @return array<string, mixed>
     */
    private function context(string $administrationId, int $year, float $loonsom, float $payslipWkrUsed=0.0, float $vrijeRuimteDeclared=0.0): array
    {
        return [
            'jurisdiction' => 'NL',
            'wkr'          => [
                $administrationId => [
                    $year => [
                        'loonsom'             => $loonsom,
                        'payslipWkrUsed'      => $payslipWkrUsed,
                        'vrijeRuimteDeclared' => $vrijeRuimteDeclared,
                        'eindheffingDeclared' => 0.0,
                    ],
                ],
            ],
        ];

    }//end context()


    /**
     * A WkrAssessment fixture, overridable per test.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function assessment(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'                  => 'wa-1',
                'administrationId'    => 'ADM-001',
                'year'                => 2026,
                'status'              => 'binnen-vrije-ruimte',
                'excess'              => 0.00,
                'eindheffingRate'     => null,
                'eindheffingDue'      => 0.00,
                'engineVersion'       => 'nl-2026',
            ],
            $overrides
        );

    }//end assessment()


    /**
     * REQ-WKR-002 — the tranche percentages/grens are read from the REAL
     * `nl-2026.json` `wkr` group, never hardcoded: 2,00% of €200.000 (below
     * the €400.000 grens) is €4.000,00.
     *
     * @return void
     */
    public function testAvailableVrijeRuimteReadsFromTheRealTable(): void
    {
        self::assertSame(400000, NlWkrChecks::availableVrijeRuimteCents(20000000));
        self::assertSame(80.0, NlWkrChecks::eindheffingPercent());

    }//end testAvailableVrijeRuimteReadsFromTheRealTable()


    /**
     * REQ-WKR-004 Scenario 3 — an administration/year with no payslips
     * (absent aggregate) is vacuously compliant.
     *
     * @return void
     */
    public function testAbsentAggregateIsVacuouslyCompliant(): void
    {
        $check = $this->checks['WkrAssessment']['nl-wkr-eindheffing-exposure'];

        self::assertTrue($check($this->assessment(), []));
        self::assertTrue($check($this->assessment(), ['wkr' => []]));
        self::assertTrue($check($this->assessment(['administrationId' => 'ADM-999']), $this->context('ADM-001', 2026, 200000.0)));

    }//end testAbsentAggregateIsVacuouslyCompliant()


    /**
     * Used within the available vrije ruimte is satisfied regardless of the
     * assessment's recorded status.
     *
     * @return void
     */
    public function testUsedWithinAvailableIsSatisfied(): void
    {
        $check   = $this->checks['WkrAssessment']['nl-wkr-eindheffing-exposure'];
        $context = $this->context('ADM-001', 2026, 200000.0, 0.0, 300.0);

        self::assertTrue($check($this->assessment(), $context));

    }//end testUsedWithinAvailableIsSatisfied()


    /**
     * REQ-WKR-004 Scenario 1 — an over-budget administration that correctly
     * flagged the eindheffing is compliant.
     *
     * @return void
     */
    public function testOverBudgetWithExposureRecordedIsSatisfied(): void
    {
        $check = $this->checks['WkrAssessment']['nl-wkr-eindheffing-exposure'];

        // Loonsom €200.000 -> available €4.000,00; used €4.500,00 -> excess
        // €500,00 -> eindheffing 80% = €400,00.
        $context    = $this->context('ADM-001', 2026, 200000.0, 0.0, 4500.0);
        $assessment = $this->assessment(
            [
                'status'          => 'eindheffing-verschuldigd',
                'excess'          => 500.00,
                'eindheffingRate' => 80.0,
                'eindheffingDue'  => 400.00,
            ]
        );

        self::assertTrue($check($assessment, $context));

    }//end testOverBudgetWithExposureRecordedIsSatisfied()


    /**
     * REQ-WKR-004 Scenario 2 — an over-budget administration that did NOT
     * flag the eindheffing is a violation, through the raw predicate.
     *
     * @return void
     */
    public function testOverBudgetWithoutExposureRecordedViolates(): void
    {
        $check = $this->checks['WkrAssessment']['nl-wkr-eindheffing-exposure'];

        $context = $this->context('ADM-001', 2026, 200000.0, 0.0, 4500.0);

        self::assertFalse($check($this->assessment(), $context));

    }//end testOverBudgetWithoutExposureRecordedViolates()


    /**
     * A recorded exposure with the wrong eindheffingDue still violates (the
     * cents-equality guard).
     *
     * @return void
     */
    public function testOverBudgetWithWrongEindheffingDueViolates(): void
    {
        $check   = $this->checks['WkrAssessment']['nl-wkr-eindheffing-exposure'];
        $context = $this->context('ADM-001', 2026, 200000.0, 0.0, 4500.0);

        $assessment = $this->assessment(
            [
                'status'          => 'eindheffing-verschuldigd',
                'excess'          => 500.00,
                'eindheffingRate' => 80.0,
                'eindheffingDue'  => 1.00,
            ]
        );

        self::assertFalse($check($assessment, $context));

    }//end testOverBudgetWithWrongEindheffingDueViolates()


    /**
     * REQ-WKR-004 — the SAME over-budget/no-exposure-recorded scenario,
     * driven through the REAL `RuleEngine::evaluate()` (catalogue +
     * auto-discovered CheckProviders + the nl-2026 table), proving
     * `nl-wkr-eindheffing-exposure` is genuinely reachable via
     * `occ hrmq:rules:audit` and not an orphaned capability.
     *
     * @return void
     */
    public function testRealRuleEngineFiresTheViolationWhenExposureIsNotRecorded(): void
    {
        $context = $this->context('ADM-001', 2026, 200000.0, 0.0, 4500.0);

        $violations = RuleEngine::evaluate('WkrAssessment', $this->assessment(), $context);

        $ruleIds = array_map(static fn($v) => $v->ruleId, $violations);
        self::assertContains('nl-wkr-eindheffing-exposure', $ruleIds, 'The real RuleEngine must fire nl-wkr-eindheffing-exposure when used > available and the exposure is not recorded.');

    }//end testRealRuleEngineFiresTheViolationWhenExposureIsNotRecorded()


    /**
     * The mirror-image REAL RuleEngine check: a correctly-recorded exposure
     * produces NO violation.
     *
     * @return void
     */
    public function testRealRuleEngineIsSilentWhenExposureIsCorrectlyRecorded(): void
    {
        $context    = $this->context('ADM-001', 2026, 200000.0, 0.0, 4500.0);
        $assessment = $this->assessment(
            [
                'status'          => 'eindheffing-verschuldigd',
                'excess'          => 500.00,
                'eindheffingRate' => 80.0,
                'eindheffingDue'  => 400.00,
            ]
        );

        $violations = RuleEngine::evaluate('WkrAssessment', $assessment, $context);

        $ruleIds = array_map(static fn($v) => $v->ruleId, $violations);
        self::assertNotContains('nl-wkr-eindheffing-exposure', $ruleIds);

    }//end testRealRuleEngineIsSilentWhenExposureIsCorrectlyRecorded()


}//end class
