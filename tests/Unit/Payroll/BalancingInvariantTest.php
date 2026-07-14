<?php

/**
 * Balancing Invariant Test
 *
 * Independent arithmetic checks that hold across EVERY payroll-2026 golden
 * fixture regardless of the calculator's own internals (design.md D8): the
 * spec-1 `nl-engine-output-consistency` net equation, the
 * `volksverzekeringen <= loonheffing` bound (`nl-loonheffingen-
 * volksverzekeringen`), the `werknemersverzekeringen = awf+aof+wko+whk` sum,
 * and a cross-check that the `nl-2026.json` tables agree with the values the
 * existing NlPayrollChecks rule statements assert (Zvw 6,10/4,85, WML 14,71)
 * so a tables/corpus divergence fails the build instead of silently
 * co-existing (the risk noted in design.md Risks).
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-009
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Payroll;

use OCA\Hrmq\Payroll\CalculationInput;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Cross-fixture balancing invariants + tables-vs-corpus cross-check.
 *
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-009
 */
class BalancingInvariantTest extends TestCase
{


    /**
     * @return void
     */
    public function testNetEquationHoldsCentsExactAcrossAllFixtures(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        foreach (self::loadFixtures() as $file => $fixture) {
            $input  = self::inputFromFixture($fixture['input']);
            $result = $calculator->calculate($input, $tables);

            // MVP mode is always werkgeversheffing (employer levy — never
            // reduces net) with no pension contribution modelled, so the
            // spec-1 consistency equation reduces to grossPay - loonheffing.
            $expectedNet = ($result->grossPayCents - $result->loonheffingCents);

            $this->assertSame(
                $expectedNet,
                $result->nettoPayCents,
                $file.': nettoPay must equal grossPay - loonheffing (zvwMode=werkgeversheffing, pensionContribution=0).'
            );
        }

    }//end testNetEquationHoldsCentsExactAcrossAllFixtures()


    /**
     * @return void
     */
    public function testVolksverzekeringenNeverExceedsLoonheffingAcrossAllFixtures(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        foreach (self::loadFixtures() as $file => $fixture) {
            $input  = self::inputFromFixture($fixture['input']);
            $result = $calculator->calculate($input, $tables);

            $this->assertGreaterThanOrEqual(0, $result->volksverzekeringenCents, $file.': volksverzekeringen must not be negative.');
            $this->assertLessThanOrEqual(
                $result->loonheffingCents,
                $result->volksverzekeringenCents,
                $file.': volksverzekeringen must never exceed loonheffing (nl-loonheffingen-volksverzekeringen).'
            );
        }

    }//end testVolksverzekeringenNeverExceedsLoonheffingAcrossAllFixtures()


    /**
     * @return void
     */
    public function testWerknemersverzekeringenIsTheSumOfItsFourLinesAcrossAllFixtures(): void
    {
        $calculator = new PayrollCalculator();
        $tables     = TaxTables::load('nl-2026');

        foreach (self::loadFixtures() as $file => $fixture) {
            $input  = self::inputFromFixture($fixture['input']);
            $result = $calculator->calculate($input, $tables);

            $sum = ($result->awfCents + $result->aofCents + $result->wkoCents + $result->whkCents);

            $this->assertSame(
                $sum,
                $result->werknemersverzekeringenCents,
                $file.': werknemersverzekeringen must equal awf+aof+wko+whk.'
            );

            $this->assertSame(
                ($result->werknemersverzekeringenCents + $result->zvwCents),
                $result->employerChargesCents,
                $file.': employerCharges must equal werknemersverzekeringen + zvw.'
            );
        }

    }//end testWerknemersverzekeringenIsTheSumOfItsFourLinesAcrossAllFixtures()


    /**
     * Cross-check that `nl-2026.json` agrees with the fixed rate values the
     * existing NlPayrollChecks rule statements assert — a divergence here
     * means the tables and the corpus have drifted apart and the build must
     * fail loudly (design.md Risks).
     *
     * @return void
     */
    public function testTablesAgreeWithTheExistingCorpusRuleStatements(): void
    {
        $path    = __DIR__.'/../../../lib/Standards/tables/nl-2026.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'nl-2026.json must parse.');

        $params = $decoded['parameters'];

        $this->assertSame(
            6.10,
            (float) $params['zvw']['werkgeversheffing']['value'],
            'nl-2026.json Zvw werkgeversheffing must equal the 6,10% NlPayrollChecks nl-zvw-werkgeversheffing asserts.'
        );

        $this->assertSame(
            4.85,
            (float) $params['zvw']['inhouding']['value'],
            'nl-2026.json Zvw inhouding must equal the 4,85% NlPayrollChecks nl-zvw-inhouding asserts.'
        );

        $this->assertSame(
            14.71,
            (float) $params['wml']['hourly21Plus']['value']['2026-01-01'],
            'nl-2026.json WML hourly minimum (2026-01-01) must equal the €14,71 NlPayrollChecks nl-minimumloon-2026 asserts.'
        );

        $this->assertSame(
            8.0,
            (float) $params['vakantiebijslag']['minRatePercent']['value'],
            'nl-2026.json vakantiebijslag rate must equal the 8% NlPayrollChecks nl-vakantiebijslag-8procent asserts.'
        );

    }//end testTablesAgreeWithTheExistingCorpusRuleStatements()


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
            grossMonthlySalaryCents: (int) round(((float) $input['grossMonthly']) * 100),
            taxTableColor: (string) $input['taxTableColor'],
            loonheffingskortingToegepast: (bool) $input['loonheffingskortingToegepast'],
            dateOfBirth: (string) $input['dateOfBirth'],
            period: (string) $input['period'],
            awfTariff: (string) $input['awfTariff'],
            aofTariff: (string) $input['aofTariff'],
            whkPercentage: (float) $input['whkPercentage']
        );

    }//end inputFromFixture()


}//end class
