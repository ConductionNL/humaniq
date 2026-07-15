<?php

/**
 * Payroll Calculator
 *
 * The pure, stateless, table-driven NL gross-to-net calculator (design.md D1,
 * D2): implements the Belastingdienst *Rekenvoorschriften voor de
 * geautomatiseerde loonadministratie 2026* witte/groene maandtabel formula
 * chain (Rekenvoorschriften 2026 sec. 2.1-2.2.4) over a `TaxTables` parameter
 * set — tabelloon, schijventarief, AHK/ARK/OUK heffingskortingen (with the
 * exact per-step floor/ceil-to-whole-euro and 5-decimal ARK-term rounding
 * rules), tijdvakbedragen, vakantiegeldreservering, the informative
 * volksverzekeringen split, Zvw werkgeversheffing, and the capped
 * Awf/Aof/Wko/Whk employer charges.
 *
 * Zero Nextcloud dependencies (design.md D1): no container, no clock, no IO
 * beyond the `TaxTables` instance passed in — directly unit-testable. Every
 * monetary intermediate is integer cents; the whole-euro Rekenvoorschriften
 * rounding points (tabelloon/schijventarief/heffingskortingen) are enforced by
 * flooring/ceiling to the nearest 100-cent boundary rather than accumulating
 * float error, and only the final tijdvakbedragen (loonheffing,
 * arbeidskorting, employer charges) round to the nearest cent.
 *
 * AOW-age and groene-tabel are table-set switches, not code branches
 * (design.md D3): the AOW-age bracket set + korting columns are selected by
 * `dateOfBirth` against the run period, and `taxTableColor: groen` runs the
 * identical chain with arbeidskorting skipped (RV2026 sec. 2.2.3.4).
 *
 * The MVP path is fixed monthly salary only (hourly x Timesheet hours is a
 * named fast-follow); no VCR (voortschrijdend cumulatief rekenen) — every
 * premium is period-capped, not cumulative-year-capped, a documented
 * limitation for wages fluctuating around the maximum (README disclaimer).
 *
 * dga-payroll-mode (design.md D1): step 9 gates on
 * `CalculationInput::$verzekeringsplichtig` — `false` (a DGA) zeroes
 * Awf/Aof/Wko/Whk (no rate lookup at all) while every other component
 * (loonheffing/arbeidskorting/volksverzekeringen/Zvw/vakantiegeldReserved/
 * nettoPay) is computed exactly as for `true` (the default): nothing
 * upstream or downstream of step 9 reads this flag.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll
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
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll;

/**
 * Pure NL gross-to-net calculator over a `TaxTables` parameter set.
 */
final class PayrollCalculator
{


    /**
     * Compute the full gross-to-net component breakdown for one employee in
     * one wage period (design.md D2, the witte/groene maandtabel chain).
     *
     * @param CalculationInput $in The calculation input.
     * @param TaxTables        $t  The tax-year parameter set.
     *
     * @return CalculationResult
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-002
     * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
     * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-002
     */
    public function calculate(CalculationInput $in, TaxTables $t): CalculationResult
    {
        $f    = $t->tijdvakFactor('maand');
        $lv   = $t->lv();
        $lmax = $t->lmax();
        $tvl  = max(0, $in->grossMonthlySalaryCents);

        $aow = $this->isAowAge($in->dateOfBirth, $in->period, $t->aowLeeftijdJaren());
        $wit = ($in->taxTableColor !== 'groen');

        // Step 2 (design.md D2): vakantiegeldreservering — 8% of the gross wage.
        $vakRate  = $t->vakantiebijslagRate();
        $vakCents = self::round2Cents(($tvl * $vakRate) / 100);

        // Step 3: tabelloon L = floor((tvl x F) / Lv) x Lv. Lv is a multiple
        // of 100 cents, so the result is automatically a whole-euro amount —
        // no separate floorEuro() needed here.
        $annualised = $tvl * $f;
        $lCents     = intdiv($annualised, $lv) * $lv;
        $aboveLmax  = ($annualised > $lmax);

        // Step 4: schijventarief — pick the bracket row for L in the
        // applicable schijven-set, X1 = floorEuro((L - a) x b/100 + c).
        $schijvenSet = $this->schijvenSet($aow, $in->dateOfBirth);
        $rows        = $t->schijven($schijvenSet);
        $bracket     = $this->selectBracket($rows, $lCents);
        $x1Raw       = ((($lCents - $bracket['a']) * $bracket['percentage']) / 100) + $bracket['c'];
        $x1          = self::floorEuroCents($x1Raw);

        // Step 5: heffingskortingen — only when loonheffingskortingToegepast.
        $ahkCents = 0;
        $arkCents = 0;
        $oukCents = 0;
        if ($in->loonheffingskortingToegepast === true) {
            $ahkParams = $t->ahk($aow);
            $ahkRaw    = $ahkParams['m1'] - (max(0, $lCents - $ahkParams['g1']) * $ahkParams['a1']);
            $ahkCents  = self::ceilEuroCents(max(0.0, $ahkRaw));

            if ($wit === true) {
                $arkParams = $t->ark($aow);
                $arkRaw    = $this->arkChain($lCents, $arkParams);
                $arkCents  = self::ceilEuroCents($arkRaw);
            }

            if ($aow === true) {
                $oukParams = $t->ouk();
                $oukRaw    = $oukParams['m1'] - (max(0, $lCents - $oukParams['g1']) * $oukParams['a1']);
                $oukCents  = self::ceilEuroCents(max(0.0, $oukRaw));
            }
        }

        // Step 6: loonheffing X = max(0, floorEuro(X1 - (AHK+OUK+ARK))),
        // tijdvakbedragen x = X/F and ark/F rounded to the nearest cent.
        $x                   = max(0, $x1 - ($ahkCents + $oukCents + $arkCents));
        $loonheffingCents    = self::round2Cents($x / $f);
        $arbeidskortingCents = self::round2Cents($arkCents / $f);
        $appliedTaxRate      = $tvl > 0 ? round((($loonheffingCents / $tvl) * 100), 2) : 0.0;

        // Step 7: volksverzekeringen (informative split only, design.md D2 —
        // the withholding itself is the combined loonheffing).
        $vvRates    = $t->volksverzekeringenRates();
        $vvRate     = $aow === true ? ($vvRates['anw'] + $vvRates['wlz']) : ($vvRates['aow'] + $vvRates['anw'] + $vvRates['wlz']);
        $schijf1Top = ($rows[0]['tot'] ?? $lCents);
        $vvJaarCents = min($lCents, $schijf1Top) * ($vvRate / 100);

        $volksverzekeringenCents = 0;
        if ($x1 > 0) {
            $volksverzekeringenCents = min($loonheffingCents, self::round2Cents(($loonheffingCents * $vvJaarCents) / $x1));
        }

        // Step 8: Zvw werkgeversheffing over the capped bijdrageloon.
        $zvwParams = $t->zvw();
        $zvwBase   = min($tvl, $zvwParams['maximumBijdrageloonMaand']);
        $zvwCents  = self::round2Cents(($zvwBase * $zvwParams['werkgeversheffing']) / 100);

        // Step 9: Awf/Aof/Wko/Whk employer charges over the capped premieloon
        // -- skipped entirely for a DGA (dga-payroll-mode design.md D1): a
        // director-major-shareholder is not verzekeringsplichtig for the
        // werknemersverzekeringen (Wfsv art. 6 lid 1 sub d jo. Regeling
        // aanwijzing directeur-grootaandeelhouder), so no rate is looked up
        // and no premium is computed-then-discarded.
        if ($in->verzekeringsplichtig === true) {
            $wnv     = $t->werknemersverzekeringen();
            $pl      = min($tvl, $wnv['maximumPremieloonMaand']);
            $awfRate = $in->awfTariff === 'high' ? $wnv['awfHoog'] : $wnv['awfLaag'];
            $aofRate = $in->aofTariff === 'hoog' ? $wnv['aofHoog'] : $wnv['aofLaag'];

            $awfCents = self::round2Cents(($pl * $awfRate) / 100);
            $aofCents = self::round2Cents(($pl * $aofRate) / 100);
            $wkoCents = self::round2Cents(($pl * $wnv['wkoOpslag']) / 100);
            $whkCents = self::round2Cents(($pl * $in->whkPercentage) / 100);
        } else {
            // DGA / not verzekeringsplichtig: all four employer premium
            // lines are zero -- they are drawn from the same
            // werknemersverzekeringen() bucket and the same capped
            // premieloon, so none is a partial subset.
            $awfCents = 0;
            $aofCents = 0;
            $wkoCents = 0;
            $whkCents = 0;
        }

        $werknemersverzekeringenCents = ($awfCents + $aofCents + $wkoCents + $whkCents);
        $employerChargesCents         = ($werknemersverzekeringenCents + $zvwCents);

        // Step 10: netto — employer charges never reduce net; Zvw is the
        // employer levy in the MVP mode (zvwMode always werkgeversheffing).
        $nettoPayCents = ($tvl - $loonheffingCents);

        return new CalculationResult(
            grossPayCents: $tvl,
            loonheffingCents: $loonheffingCents,
            arbeidskortingCents: $arbeidskortingCents,
            volksverzekeringenCents: $volksverzekeringenCents,
            zvwCents: $zvwCents,
            zvwMode: 'werkgeversheffing',
            zvwRate: $zvwParams['werkgeversheffing'],
            appliedTaxRate: $appliedTaxRate,
            nettoPayCents: $nettoPayCents,
            vakantiegeldReservedCents: $vakCents,
            vakantiegeldRate: $vakRate,
            awfCents: $awfCents,
            aofCents: $aofCents,
            wkoCents: $wkoCents,
            whkCents: $whkCents,
            werknemersverzekeringenCents: $werknemersverzekeringenCents,
            employerChargesCents: $employerChargesCents,
            aboveLmax: $aboveLmax
        );

    }//end calculate()


    /**
     * Whether AOW-age applies for this period (design.md D3): from the first
     * day of the calendar month in which the employee reaches the tables'
     * AOW-leeftijd. A missing/unparseable `dateOfBirth` defaults to below-AOW
     * (the conservative default — `dateOfBirth` is not a required Employee
     * field).
     *
     * @param string|null $dateOfBirth      ISO-8601 date of birth, or null.
     * @param string      $period           Wage period, `YYYY-MM`.
     * @param int         $aowLeeftijdJaren The statutory AOW-leeftijd in whole years.
     *
     * @return bool
     */
    private function isAowAge(?string $dateOfBirth, string $period, int $aowLeeftijdJaren): bool
    {
        $dateOfBirth = trim((string) $dateOfBirth);
        if ($dateOfBirth === '') {
            return false;
        }

        try {
            $dob     = new \DateTimeImmutable($dateOfBirth);
            $aowDate = $dob->modify('+'.$aowLeeftijdJaren.' years');
            $period  = new \DateTimeImmutable($period.'-01');
        } catch (\Throwable $e) {
            return false;
        }

        $periodEnd = $period->modify('last day of this month');

        return $aowDate <= $periodEnd;

    }//end isAowAge()


    /**
     * The schijven-set key for the applicable bracket table (design.md D3):
     * `belowAow`, or the birth-year-selected AOW-age set.
     *
     * @param bool        $aow         Whether AOW-age applies.
     * @param string|null $dateOfBirth ISO-8601 date of birth, or null.
     *
     * @return string
     */
    private function schijvenSet(bool $aow, ?string $dateOfBirth): string
    {
        if ($aow === false) {
            return 'belowAow';
        }

        $year = 1946;
        try {
            $year = (int) (new \DateTimeImmutable((string) $dateOfBirth))->format('Y');
        } catch (\Throwable $e) {
            // Defensive fallback; isAowAge() already validated a parseable date
            // before this method is reached in practice.
        }

        return $year <= 1945 ? 'aowBorn1945OrEarlier' : 'aowBorn1946OrLater';

    }//end schijvenSet()


    /**
     * Select the schijventarief bracket row whose `tot` (cents) is the first
     * to reach or exceed L, with a null `tot` matching unconditionally (the
     * unbounded top bracket).
     *
     * @param array<int, array{tot: int|null, percentage: float, a: int, c: int}> $rows   The schijven-set rows, ascending.
     * @param int                                                                  $lCents The tabelloon, in cents.
     *
     * @return array{tot: int|null, percentage: float, a: int, c: int}
     */
    private function selectBracket(array $rows, int $lCents): array
    {
        foreach ($rows as $row) {
            if ($row['tot'] === null || $lCents <= $row['tot']) {
                return $row;
            }
        }

        return end($rows);

    }//end selectBracket()


    /**
     * The ARK (arbeidskorting) opbouw "min-chain" (design.md D2 step 5): three
     * cumulative segment terms, each rounded to 5 decimals (of a euro — 3
     * decimals in cents-space) and capped at its own `arkm1/arkm2/arkm3`
     * boundary as the build progresses, then the tail term above `arkg3`
     * subtracts `arka1` per cent of excess and the whole chain floors to 0
     * above `arkg4`.
     *
     * @param int                                                                                                  $lCents The tabelloon, in cents.
     * @param array{o1: float, o2: float, o3: float, a1: float, g1: int, g2: int, g3: int, g4: int, m1: int, m2: int, m3: int} $ark    The ARK parameter set.
     *
     * @return float The raw (pre-ceilEuro) ARK amount, in cents, non-negative.
     */
    private function arkChain(int $lCents, array $ark): float
    {
        if ($lCents <= 0) {
            return 0.0;
        }

        $l1    = min($lCents, $ark['g1']);
        $term1 = min(self::round5Cents($l1 * $ark['o1']), (float) $ark['m1']);

        $chain = $term1;

        if ($lCents > $ark['g1']) {
            $l2    = (min($lCents, $ark['g2']) - $ark['g1']);
            $term2 = min(self::round5Cents($term1 + ($l2 * $ark['o2'])), (float) $ark['m2']);
            $chain = $term2;

            if ($lCents > $ark['g2']) {
                $l3    = (min($lCents, $ark['g3']) - $ark['g2']);
                $term3 = min(self::round5Cents($term2 + ($l3 * $ark['o3'])), (float) $ark['m3']);
                $chain = $term3;

                if ($lCents > $ark['g3']) {
                    $excess = ($lCents - $ark['g3']);
                    $chain  = self::round5Cents($term3 - ($excess * $ark['a1']));
                }
            }
        }

        if ($lCents > $ark['g4']) {
            $chain = 0.0;
        }

        return max(0.0, $chain);

    }//end arkChain()


    /**
     * Floor a raw cents amount to the nearest whole-euro (100-cent) boundary
     * (the Rekenvoorschriften "floorEuro" rounding rule). The input is
     * pre-rounded to 4 decimal places to absorb float noise from the
     * percentage arithmetic before flooring, well within the 100-cent
     * granularity being floored to.
     *
     * @param float $rawCents The raw cents amount.
     *
     * @return int
     */
    private static function floorEuroCents(float $rawCents): int
    {
        return ((int) floor(round($rawCents, 4) / 100)) * 100;

    }//end floorEuroCents()


    /**
     * Ceil a raw cents amount to the nearest whole-euro (100-cent) boundary
     * (the Rekenvoorschriften "ceilEuro" rounding rule), same float-noise
     * guard as `floorEuroCents()`.
     *
     * @param float $rawCents The raw cents amount.
     *
     * @return int
     */
    private static function ceilEuroCents(float $rawCents): int
    {
        return ((int) ceil(round($rawCents, 4) / 100)) * 100;

    }//end ceilEuroCents()


    /**
     * Round a raw cents amount to 5 decimal places of a euro (3 decimal
     * places in cents-space) — the ARK opbouw-term rounding rule
     * (design.md D2 step 5).
     *
     * @param float $rawCents The raw cents amount.
     *
     * @return float
     */
    private static function round5Cents(float $rawCents): float
    {
        return round($rawCents, 3);

    }//end round5Cents()


    /**
     * Round a raw cents amount to the nearest whole cent (the
     * Rekenvoorschriften tijdvakbedrag "rounded to 2 decimals" rule, applied
     * at cents granularity).
     *
     * @param float $rawCents The raw cents amount.
     *
     * @return int
     */
    private static function round2Cents(float $rawCents): int
    {
        return (int) round(round($rawCents, 4));

    }//end round2Cents()


}//end class
