<?php

/**
 * Unit tests for the retro-adjustment consistency check (NlRetroChecks).
 *
 * Pins the retro-adjustments corpus predicate (design.md D7): a
 * PayrollAdjustment carrying `engineVersion` must have every `delta*` field
 * equal to an independent recompute minus the sealed original Payslip's stored
 * figures, cents-exact; a null-`engineVersion` adjustment is vacuous; a
 * tampered delta fails. Crucially, the predicate is driven through the REAL
 * `RuleEngine::evaluate('PayrollAdjustment', ...)` (not just the raw callable)
 * so the auto-discovery + rule-index wiring is exercised end-to-end: a
 * consistent adjustment produces zero Violations, a tampered one produces the
 * `nl-retro-adjustment-consistency` Violation at `mandatory` severity. The
 * "consistent" delta is built from the SAME pure `PayrollCalculator` +
 * `nl-2026` tables the predicate reconstructs, so the fixture is the engine's
 * own output diffed against a zeroed stored payslip -- no hand-computed
 * figures to drift.
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
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Payroll\CalculationInput;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\TaxTables;
use OCA\Hrmq\Standards\Checks\NlRetroChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlRetroChecks (raw predicate + through RuleEngine::evaluate).
 *
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */
class NlRetroChecksTest extends TestCase
{

    /**
     * The delta fields mapped to their recompute component.
     *
     * @var array<string, string>
     */
    private const DELTA_MAP = [
        'deltaGross'                   => 'grossPayCents',
        'deltaLoonheffing'             => 'loonheffingCents',
        'deltaNet'                     => 'nettoPayCents',
        'deltaWerknemersverzekeringen' => 'werknemersverzekeringenCents',
        'deltaZvw'                     => 'zvwCents',
        'deltaVolksverzekeringen'      => 'volksverzekeringenCents',
        'deltaVakantiegeldReserved'    => 'vakantiegeldReservedCents',
    ];


    /**
     * @return void
     */
    protected function setUp(): void
    {
        RuleEngine::reset();

    }//end setUp()


    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RuleEngine::reset();

    }//end tearDown()


    /**
     * Build a CONSISTENT adjustment: delta = recomputed(nl-2026, 3800) minus a
     * zeroed stored payslip -- i.e. the engine's own euro output. Returns
     * `[adjustment, context]`.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function consistentAdjustmentAndContext(): array
    {
        $tables = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 380000,
            taxTableColor: 'wit',
            loonheffingskortingToegepast: true,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: (float) $tables->werknemersverzekeringen()['whkDefault']
        );

        $result = (new PayrollCalculator())->calculate($input, $tables);

        $delta = [];
        foreach (self::DELTA_MAP as $field => $component) {
            // Stored payslip is zeroed, so delta = recomputed, euro-denominated.
            $delta[$field] = round(($result->$component / 100), 2);
        }

        $adjustment = array_merge(
            [
                'id'                          => 'adj-1',
                'employeeId'                  => 'emp-1',
                'originalPeriod'              => '2026-02',
                'originalPayslipId'           => 'ps-orig',
                'correctedGrossMonthlySalary' => 3800.00,
                'engineVersion'               => 'nl-2026',
                'jurisdiction'                => 'NL',
                'status'                      => 'applied',
            ],
            $delta
        );

        $context = [
            'jurisdiction' => 'NL',
            'retro'        => [
                'payslipsById'          => [
                    // The sealed original, all components zero.
                    'ps-orig' => [
                        'id'                      => 'ps-orig',
                        'grossPay'                => 0.0,
                        'loonheffing'             => 0.0,
                        'nettoPay'                => 0.0,
                        'werknemersverzekeringen' => 0.0,
                        'zvw'                     => 0.0,
                        'volksverzekeringen'      => 0.0,
                        'vakantiegeldReserved'    => 0.0,
                    ],
                ],
                'employeesById'         => [
                    'emp-1' => [
                        'dateOfBirth'                  => '1990-04-12',
                        'taxTableColor'                => 'wit',
                        'loonheffingskortingToegepast' => true,
                    ],
                ],
                'contractsByEmployeeId' => [
                    'emp-1' => ['type' => 'permanent', 'writtenContract' => true, 'awfTariff' => 'low'],
                ],
                'aofTariff'             => 'laag',
                'whkPercentageOverride' => null,
            ],
        ];

        return [$adjustment, $context];

    }//end consistentAdjustmentAndContext()


    /**
     * A null-engineVersion adjustment is vacuously compliant (not yet
     * computed) -- both through the raw predicate and RuleEngine::evaluate.
     *
     * @return void
     */
    public function testNullEngineVersionIsVacuouslyCompliant(): void
    {
        $check = NlRetroChecks::checks()['PayrollAdjustment']['nl-retro-adjustment-consistency'];

        $this->assertTrue($check(['engineVersion' => null, 'status' => 'draft'], []));

        $violations = RuleEngine::evaluate('PayrollAdjustment', ['engineVersion' => null, 'jurisdiction' => 'NL', 'status' => 'draft'], ['jurisdiction' => 'NL']);
        $this->assertSame([], $violations, 'A not-yet-computed adjustment produces no violation.');

    }//end testNullEngineVersionIsVacuouslyCompliant()


    /**
     * A consistent delta passes -- through the REAL RuleEngine::evaluate, so
     * the predicate is proven auto-discovered + reachable, not merely
     * callable.
     *
     * @return void
     */
    public function testConsistentDeltaPassesThroughRuleEngine(): void
    {
        [$adjustment, $context] = $this->consistentAdjustmentAndContext();

        // Sanity: the raw predicate agrees.
        $check = NlRetroChecks::checks()['PayrollAdjustment']['nl-retro-adjustment-consistency'];
        $this->assertTrue($check($adjustment, $context));

        // The real engine: zero violations for the consistent adjustment.
        $violations = RuleEngine::evaluate('PayrollAdjustment', $adjustment, $context);
        $this->assertSame([], $violations, 'A cents-exact delta produces no violation through RuleEngine::evaluate.');

    }//end testConsistentDeltaPassesThroughRuleEngine()


    /**
     * A tampered delta fires the `nl-retro-adjustment-consistency` violation at
     * `mandatory` severity -- through the REAL RuleEngine::evaluate.
     *
     * @return void
     */
    public function testTamperedDeltaFiresMandatoryViolationThroughRuleEngine(): void
    {
        [$adjustment, $context] = $this->consistentAdjustmentAndContext();

        // Hand-edit deltaNet to disagree with the recorded corrected input.
        $adjustment['deltaNet'] = ((float) $adjustment['deltaNet'] + 1.00);

        $violations = RuleEngine::evaluate('PayrollAdjustment', $adjustment, $context);

        $this->assertCount(1, $violations, 'Exactly the retro-consistency rule fires.');
        $this->assertSame('nl-retro-adjustment-consistency', $violations[0]->ruleId);
        $this->assertSame('mandatory', $violations[0]->severity, 'The retro-consistency rule carries ONE static mandatory severity.');
        $this->assertTrue(RuleEngine::hasMandatory($violations));

    }//end testTamperedDeltaFiresMandatoryViolationThroughRuleEngine()


    /**
     * The rule is enforceable -- its id appears in the engine's registered
     * check set (proves NlRetroChecks was auto-discovered).
     *
     * @return void
     */
    public function testRuleIsRegisteredAndEnforceable(): void
    {
        $this->assertContains('nl-retro-adjustment-consistency', RuleEngine::checkedRuleIds());
        $this->assertContains('PayrollAdjustment', RuleEngine::supportedTypes());

    }//end testRuleIsRegisteredAndEnforceable()


}//end class
