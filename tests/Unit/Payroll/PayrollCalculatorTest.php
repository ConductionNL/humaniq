<?php

/**
 * Unit tests for PayrollCalculator.
 *
 * Table-driven: every fixture under tests/fixtures/payroll-2026/*.json (plus
 * any dropped into official/*.json, design.md D8) is run through
 * `PayrollCalculator::calculate()` against the real `nl-2026` TaxTables and
 * asserted cents-exact against its `expected` block. The `anchor` fixture
 * pins the design.md D2 hand-computed worked example digit-for-digit.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Payroll
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-002
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-009
 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-002
 * @spec openspec/changes/30-procent-regeling/specs/30-procent-regeling/spec.md#REQ-30P-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Payroll;

use OCA\Hrmq\Payroll\CalculationInput;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Golden-fixture tests for PayrollCalculator.
 *
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 */
class PayrollCalculatorTest extends TestCase
{


    /**
     * @return void
     */
    public function testGoldenFixturesReproduceExpectedComponentsExactly(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $fixtures = self::loadFixtures();
        $this->assertNotEmpty($fixtures, 'No payroll-2026 fixtures found — the golden-test suite must not be empty.');

        foreach ($fixtures as $file => $fixture) {
            $input  = self::inputFromFixture($fixture['input']);
            $result = $calculator->calculate($input, $tables);

            $expected = $fixture['expected'];
            $label    = (string) ($fixture['name'] ?? $file);

            $this->assertSame(self::cents($expected['loonheffing']), $result->loonheffingCents, $label.': loonheffing');
            $this->assertSame(self::cents($expected['arbeidskorting']), $result->arbeidskortingCents, $label.': arbeidskorting');
            $this->assertSame(self::cents($expected['volksverzekeringen']), $result->volksverzekeringenCents, $label.': volksverzekeringen');
            $this->assertSame(self::cents($expected['zvw']), $result->zvwCents, $label.': zvw');
            $this->assertSame(self::cents($expected['awf']), $result->awfCents, $label.': awf');
            $this->assertSame(self::cents($expected['aof']), $result->aofCents, $label.': aof');
            $this->assertSame(self::cents($expected['wko']), $result->wkoCents, $label.': wko');
            $this->assertSame(self::cents($expected['whk']), $result->whkCents, $label.': whk');
            $this->assertSame(self::cents($expected['werknemersverzekeringen']), $result->werknemersverzekeringenCents, $label.': werknemersverzekeringen');
            $this->assertSame(self::cents($expected['employerCharges']), $result->employerChargesCents, $label.': employerCharges');
            $this->assertSame(self::cents($expected['vakantiegeldReserved']), $result->vakantiegeldReservedCents, $label.': vakantiegeldReserved');
            $this->assertSame(self::cents($expected['nettoPay']), $result->nettoPayCents, $label.': nettoPay');
        }//end foreach

    }//end testGoldenFixturesReproduceExpectedComponentsExactly()


    /**
     * The anchor fixture pins the design.md D2 hand-computed worked example
     * digit-for-digit (belt-and-braces on top of the generic loop above).
     *
     * @return void
     */
    public function testAnchorCaseReproducesTheHandComputedFigures(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 380000,
            taxTableColor: 'wit',
            loonheffingskortingToegepast: true,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: 1.52
        );

        $result = $calculator->calculate($input, $tables);

        $this->assertSame(71883, $result->loonheffingCents);
        $this->assertSame(47375, $result->arbeidskortingCents);
        $this->assertSame(23180, $result->zvwCents);
        $this->assertSame(41914, $result->werknemersverzekeringenCents);
        $this->assertSame(30400, $result->vakantiegeldReservedCents);
        $this->assertSame(308117, $result->nettoPayCents);
        $this->assertSame(18.92, $result->appliedTaxRate);

    }//end testAnchorCaseReproducesTheHandComputedFigures()


    /**
     * The dga-payroll-mode golden anchor (design.md D2): the same €3.800,00
     * anchor input with `verzekeringsplichtig: false` (a DGA). Pins that
     * Awf/Aof/Wko/Whk/werknemersverzekeringen are ALL zero, employerCharges
     * reduces to Zvw only (€231,80), and every other component --
     * loonheffing/arbeidskorting/zvw/vakantiegeldReserved/nettoPay -- is
     * byte-identical to the non-DGA anchor (REQ-DGA-001/REQ-DGA-002): netto
     * does NOT rise for a DGA, because werknemersverzekeringen never reduced
     * it in the first place (design.md D2 grounding correction).
     *
     * @return void
     */
    public function testDgaAnchorZeroesWerknemersverzekeringenAndLeavesNettoUnchanged(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 380000,
            taxTableColor: 'wit',
            loonheffingskortingToegepast: true,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: 1.52,
            verzekeringsplichtig: false
        );

        $result = $calculator->calculate($input, $tables);

        $this->assertSame(0, $result->awfCents, 'DGA: awf must be zero');
        $this->assertSame(0, $result->aofCents, 'DGA: aof must be zero');
        $this->assertSame(0, $result->wkoCents, 'DGA: wko must be zero');
        $this->assertSame(0, $result->whkCents, 'DGA: whk must be zero');
        $this->assertSame(0, $result->werknemersverzekeringenCents, 'DGA: werknemersverzekeringen must be zero');
        $this->assertSame(23180, $result->employerChargesCents, 'DGA: employerCharges must reduce to zvw only');

        // Byte-identical to the non-DGA anchor.
        $this->assertSame(71883, $result->loonheffingCents);
        $this->assertSame(47375, $result->arbeidskortingCents);
        $this->assertSame(47086, $result->volksverzekeringenCents);
        $this->assertSame(23180, $result->zvwCents);
        $this->assertSame(30400, $result->vakantiegeldReservedCents);
        $this->assertSame(308117, $result->nettoPayCents, 'DGA: nettoPay must be UNCHANGED from the non-DGA anchor (werknemersverzekeringen never reduce net)');
        $this->assertSame(18.92, $result->appliedTaxRate);

    }//end testDgaAnchorZeroesWerknemersverzekeringenAndLeavesNettoUnchanged()


    /**
     * Regression guard for design.md D1: `verzekeringsplichtig: true` (the
     * default, omitted) on the exact same anchor input must reproduce the
     * pre-existing non-DGA anchor figures unchanged -- proving the default
     * path is byte-identical after adding the DGA gate (REQ-DGA-001).
     *
     * @return void
     */
    public function testVerzekeringsplichtigTrueReproducesThePreExistingAnchorUnchanged(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 380000,
            taxTableColor: 'wit',
            loonheffingskortingToegepast: true,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: 1.52,
            verzekeringsplichtig: true
        );

        $result = $calculator->calculate($input, $tables);

        $this->assertSame(71883, $result->loonheffingCents);
        $this->assertSame(47375, $result->arbeidskortingCents);
        $this->assertSame(47086, $result->volksverzekeringenCents);
        $this->assertSame(23180, $result->zvwCents);
        $this->assertSame(10412, $result->awfCents);
        $this->assertSame(23826, $result->aofCents);
        $this->assertSame(1900, $result->wkoCents);
        $this->assertSame(5776, $result->whkCents);
        $this->assertSame(41914, $result->werknemersverzekeringenCents);
        $this->assertSame(65094, $result->employerChargesCents);
        $this->assertSame(30400, $result->vakantiegeldReservedCents);
        $this->assertSame(308117, $result->nettoPayCents);
        $this->assertSame(18.92, $result->appliedTaxRate);

    }//end testVerzekeringsplichtigTrueReproducesThePreExistingAnchorUnchanged()


    /**
     * The fleet-bijtelling golden anchor (design.md D4): the D2 anchor input
     * (€3.800,00) plus €500,00 bijtelling (cataloguswaarde €45.000,00 x 22% /
     * 12 = €825,00 - eigenBijdrage €325,00 = €500,00) folded into the taxable
     * gross BEFORE this calculator runs (`PayrollRunService`'s job, never
     * this class's) -> `tvl` = €4.300,00. Pins every D4 figure digit-for-digit
     * -- proving `PayrollCalculator` needs zero changes to correctly derive
     * every downstream component from a larger `tvl`.
     *
     * @return void
     */
    public function testBijtellingAnchorCaseReproducesTheHandComputedFigures(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 430000,
            taxTableColor: 'wit',
            loonheffingskortingToegepast: true,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: 1.52
        );

        $result = $calculator->calculate($input, $tables);

        $this->assertSame(430000, $result->grossPayCents);
        $this->assertSame(97083, $result->loonheffingCents);
        $this->assertSame(44133, $result->arbeidskortingCents);
        $this->assertSame(55920, $result->volksverzekeringenCents);
        $this->assertSame(26230, $result->zvwCents);
        $this->assertSame(11782, $result->awfCents);
        $this->assertSame(26961, $result->aofCents);
        $this->assertSame(2150, $result->wkoCents);
        $this->assertSame(6536, $result->whkCents);
        $this->assertSame(47429, $result->werknemersverzekeringenCents);
        $this->assertSame(73659, $result->employerChargesCents);
        $this->assertSame(34400, $result->vakantiegeldReservedCents);
        $this->assertSame(332917, $result->nettoPayCents);
        $this->assertSame(22.58, $result->appliedTaxRate);

    }//end testBijtellingAnchorCaseReproducesTheHandComputedFigures()


    /**
     * The 30%-ruling golden anchor (30-procent-regeling design.md D4): the same
     * €3.800,00 anchor input plus `thirtyPercentRulingRate: 30.0`. Pins every
     * D4 figure digit-for-digit: exemption €1.140,00 reduces the taxable base
     * to €2.660,00, so loonheffing DROPS to €251,17 and -- because `grossRef`
     * stays the unreduced `tvl` (D2) -- `grossPay` is UNCHANGED at €3.800,00
     * and `nettoPay` RISES to €3.548,83. appliedTaxRate 6,61 is divided by the
     * unreduced gross.
     *
     * @return void
     */
    public function testThirtyPercentRulingAnchorReproducesTheHandComputedFigures(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 380000,
            taxTableColor: 'wit',
            loonheffingskortingToegepast: true,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: 1.52,
            thirtyPercentRulingRate: 30.0
        );

        $result = $calculator->calculate($input, $tables);

        $this->assertSame(380000, $result->grossPayCents, '30%-ruling: grossPay must be UNCHANGED (grossRef stays @binding.tvl)');
        $this->assertSame(25117, $result->loonheffingCents);
        $this->assertSame(45158, $result->arbeidskortingCents);
        $this->assertSame(19427, $result->volksverzekeringenCents);
        $this->assertSame(16226, $result->zvwCents);
        $this->assertSame(7288, $result->awfCents);
        $this->assertSame(16678, $result->aofCents);
        $this->assertSame(1330, $result->wkoCents);
        $this->assertSame(4043, $result->whkCents);
        $this->assertSame(29339, $result->werknemersverzekeringenCents);
        $this->assertSame(45565, $result->employerChargesCents);
        $this->assertSame(21280, $result->vakantiegeldReservedCents);
        $this->assertSame(354883, $result->nettoPayCents, '30%-ruling: nettoPay must RISE to €3.548,83');
        $this->assertSame(6.61, $result->appliedTaxRate);

        // Regression guard (design.md D2): nettoPay HIGHER than the same-gross
        // non-ruling case (€3.081,17), never lower/unchanged -- the single most
        // important guard in this change.
        $this->assertGreaterThan(308117, $result->nettoPayCents);

    }//end testThirtyPercentRulingAnchorReproducesTheHandComputedFigures()


    /**
     * No-ruling regression (30-procent-regeling): the pre-existing anchor input
     * with `thirtyPercentRulingRate` OMITTED (defaults to 0.0) reproduces the
     * base anchor figures byte-for-byte -- the exemption degrades to zero and
     * `belastbaarLoon` equals `tvl`, so the non-ruling path is unchanged.
     *
     * @return void
     */
    public function testNoRulingReproducesThePreExistingAnchorByteForByte(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 380000,
            taxTableColor: 'wit',
            loonheffingskortingToegepast: true,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: 1.52
        );

        $result = $calculator->calculate($input, $tables);

        $this->assertSame(380000, $result->grossPayCents);
        $this->assertSame(71883, $result->loonheffingCents);
        $this->assertSame(47375, $result->arbeidskortingCents);
        $this->assertSame(47086, $result->volksverzekeringenCents);
        $this->assertSame(23180, $result->zvwCents);
        $this->assertSame(41914, $result->werknemersverzekeringenCents);
        $this->assertSame(30400, $result->vakantiegeldReservedCents);
        $this->assertSame(308117, $result->nettoPayCents);
        $this->assertSame(18.92, $result->appliedTaxRate);

    }//end testNoRulingReproducesThePreExistingAnchorByteForByte()


    /**
     * The WNT-aftoppingsgrens caps the exemption for a high earner
     * (30-procent-regeling design.md D4 second scenario): at €25.000,00/month
     * (well above the €21.833,33 monthly cap) the exemption is `min(25.000,00,
     * 21.833,33) × 30% = €6.550,00`, not the uncapped €7.500,00 -- so the
     * taxable base is `25.000,00 - 6.550,00 = €18.450,00`, NOT `25.000 × 70%`.
     * Cross-checked here through the engine: the taxable base a €18.450,00
     * no-ruling gross would produce yields the identical loonheffing.
     *
     * @return void
     */
    public function testWntAftoppingsgrensCapsTheExemptionForAHighEarner(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $ruling = $calculator->calculate(
            new CalculationInput(
                grossMonthlySalaryCents: 2500000,
                taxTableColor: 'wit',
                loonheffingskortingToegepast: true,
                dateOfBirth: '1990-04-12',
                period: '2026-02',
                awfTariff: 'low',
                aofTariff: 'laag',
                whkPercentage: 1.52,
                thirtyPercentRulingRate: 30.0
            ),
            $tables
        );

        // Capped taxable base = 25.000,00 - 6.550,00 = €18.450,00. A plain
        // no-ruling gross of €18.450,00 produces the identical loonheffing,
        // proving the cap bound at €6.550,00 (not the uncapped €7.500,00 that
        // would have left an €17.500,00 base).
        $cappedBase = $calculator->calculate(
            new CalculationInput(
                grossMonthlySalaryCents: 1845000,
                taxTableColor: 'wit',
                loonheffingskortingToegepast: true,
                dateOfBirth: '1990-04-12',
                period: '2026-02',
                awfTariff: 'low',
                aofTariff: 'laag',
                whkPercentage: 1.52
            ),
            $tables
        );

        $this->assertSame($cappedBase->loonheffingCents, $ruling->loonheffingCents, 'high earner: loonheffing must match the €18.450,00 capped taxable base (exemption capped at €6.550,00)');
        $this->assertSame(2500000, $ruling->grossPayCents, 'high earner: grossPay stays the full €25.000,00');

    }//end testWntAftoppingsgrensCapsTheExemptionForAHighEarner()


    /**
     * Without the loonheffingskorting election no kortingen apply (REQ-PCE-001
     * scenario): AHK/ARK are zero and loonheffing equals the un-reduced
     * tabelloon tijdvakbedrag.
     *
     * @return void
     */
    public function testWithoutLoonheffingskortingNoKortingenApply(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 380000,
            taxTableColor: 'wit',
            loonheffingskortingToegepast: false,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: 1.52
        );

        $result = $calculator->calculate($input, $tables);

        $this->assertSame(0, $result->arbeidskortingCents);
        $this->assertSame(136775, $result->loonheffingCents);

    }//end testWithoutLoonheffingskortingNoKortingenApply()


    /**
     * Groene tabel applies no arbeidskorting while AHK still applies
     * (REQ-PCE-002 scenario).
     *
     * @return void
     */
    public function testGroeneTabelAppliesNoArbeidskorting(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        $input = new CalculationInput(
            grossMonthlySalaryCents: 380000,
            taxTableColor: 'groen',
            loonheffingskortingToegepast: true,
            dateOfBirth: '1990-04-12',
            period: '2026-02',
            awfTariff: 'low',
            aofTariff: 'laag',
            whkPercentage: 1.52
        );

        $result = $calculator->calculate($input, $tables);

        $this->assertSame(0, $result->arbeidskortingCents);
        $this->assertGreaterThan(0, $result->loonheffingCents);

    }//end testGroeneTabelAppliesNoArbeidskorting()


    /**
     * Load every JSON fixture under tests/fixtures/payroll-2026/ (including
     * any officially-obtained cases dropped into official/, design.md D8).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function loadFixtures(): array
    {
        $dir   = __DIR__.'/../../fixtures/payroll-2026';
        $files = array_merge((glob($dir.'/*.json') ?: []), (glob($dir.'/official/*.json') ?: []));

        $fixtures = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) === true && isset($decoded['input'], $decoded['expected']) === true) {
                $fixtures[basename($file)] = $decoded;
            }
        }

        return $fixtures;

    }//end loadFixtures()


    /**
     * Build a `CalculationInput` from a fixture's `input` block.
     *
     * @param array<string, mixed> $input The fixture's `input` block.
     *
     * @return CalculationInput
     */
    private static function inputFromFixture(array $input): CalculationInput
    {
        return new CalculationInput(
            grossMonthlySalaryCents: self::cents((float) $input['grossMonthly']),
            taxTableColor: (string) $input['taxTableColor'],
            loonheffingskortingToegepast: (bool) $input['loonheffingskortingToegepast'],
            dateOfBirth: (string) $input['dateOfBirth'],
            period: (string) $input['period'],
            awfTariff: (string) $input['awfTariff'],
            aofTariff: (string) $input['aofTariff'],
            whkPercentage: (float) $input['whkPercentage'],
            verzekeringsplichtig: (bool) ($input['verzekeringsplichtig'] ?? true),
            thirtyPercentRulingRate: (float) ($input['thirtyPercentRulingRate'] ?? 0.0)
        );

    }//end inputFromFixture()


    /**
     * Convert a euro amount to integer cents (round-half-away-from-zero).
     *
     * @param float $euro The euro amount.
     *
     * @return int
     */
    private static function cents(float $euro): int
    {
        return (int) round($euro * 100);

    }//end cents()


}//end class
