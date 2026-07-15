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
            whkPercentage: (float) $input['whkPercentage']
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
