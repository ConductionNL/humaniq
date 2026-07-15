<?php

/**
 * Proforma Payslip Service
 *
 * A stateless, persist-nothing front door to the existing
 * `payroll-core-engine` calculator (design.md D1): given hypothetical inputs
 * (bruto maandsalaris, loonheffingstabel, dateOfBirth/AOW hint, part-time
 * factor, one-off bijzondere beloning, period), builds a `CalculationInput`,
 * loads the tax-year `TaxTables` for the period, runs the **existing**
 * `PayrollCalculator::calculate()` and maps the returned `CalculationResult`
 * to a euro-decimal breakdown. It holds no state between calls, injects no
 * ObjectService and creates or mutates NO object — no Employee,
 * EmploymentContract, PayrollRun or Payslip is ever touched. This service
 * adds ZERO tax logic: every figure comes from the reused calculator.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-001
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCA\Hrmq\Payroll\CalculationInput;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\TaxTables;

/**
 * Stateless builder: hypothetical params in, full gross-to-net breakdown out,
 * nothing persisted.
 */
class ProformaPayslipService
{


    /**
     * @param PayrollCalculator $calculator      The existing, reused-as-is pure calculator.
     * @param SettingsService   $settingsService The employer-level Aof/Whk knob source.
     */
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly SettingsService $settingsService,
    ) {

    }//end __construct()


    /**
     * Compute the full gross-to-net breakdown for one hypothetical input set.
     * Holds no state between calls, calls no ObjectService method and writes
     * no object — the entire persistence contract is "nothing stored"
     * (design.md D1).
     *
     * @param array<string, mixed> $params Raw hypothetical params: gross, table, loonheffingskorting,
     *                                     dateOfBirth, period, parttime, bijzonder, aof, whk.
     *
     * @return array<string, mixed> The euro-decimal breakdown.
     *
     * @throws \InvalidArgumentException When any input is malformed (the controller maps this to HTTP 400).
     *
     * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-001
     * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-006
     */
    public function simulate(array $params): array
    {
        $gross          = self::requireNumeric($params['gross'] ?? null, 'Bruto maandsalaris');
        $table          = self::requireTableColor($params['table'] ?? null);
        $korting        = self::toBool($params['loonheffingskorting'] ?? null, true);
        $dateOfBirth    = self::nullableString($params['dateOfBirth'] ?? null);
        $period         = self::requirePeriod($params['period'] ?? null);
        $parttimeFactor = self::requirePositiveFloat($params['parttime'] ?? null, 1.0, 'Deeltijdfactor');
        $bijzonder      = self::requireNonNegativeFloat($params['bijzonder'] ?? null, 0.0, 'Bijzondere beloning');

        $tableId = 'nl-'.substr($period, 0, 4);

        try {
            $tables = TaxTables::load($tableId);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException('Belastingtabel voor periode '.$period.' is niet beschikbaar: '.$e->getMessage());
        }

        $whkDefault = (float) $tables->werknemersverzekeringen()['whkDefault'];

        $aofTariff     = self::requireAofTariff($params['aof'] ?? null, $this->settingsService->getPayrollAofTariff());
        $whkPercentage = self::requireNonNegativeFloat($params['whk'] ?? null, $this->settingsService->getPayrollWhkPercentage($whkDefault), 'Whk-percentage');

        // design.md D2: gross fed to the calculator = (gross x parttimeFactor) +
        // the one-off bijzondere beloning, folded in as a combined-loon
        // estimate (design.md D3) — no new tax logic, the sum simply runs
        // through the existing regular maandtabel chain.
        $grossMonthlySalaryCents = (int) round((($gross * $parttimeFactor) + $bijzonder) * 100);

        $input = new CalculationInput(
            grossMonthlySalaryCents: $grossMonthlySalaryCents,
            taxTableColor: $table,
            loonheffingskortingToegepast: $korting,
            dateOfBirth: $dateOfBirth,
            period: $period,
            awfTariff: 'low',
            aofTariff: $aofTariff,
            whkPercentage: $whkPercentage
        );

        // The existing, reused-as-is calculator. Zero new tax logic — every
        // figure in the returned breakdown originates here.
        $result = $this->calculator->calculate($input, $tables);

        return [
            'input'                    => [
                'gross'               => $gross,
                'table'               => $table,
                'loonheffingskorting' => $korting,
                'dateOfBirth'         => $dateOfBirth,
                'period'              => $period,
                'parttime'            => $parttimeFactor,
                'bijzonder'           => $bijzonder,
                'aof'                 => $aofTariff,
                'whk'                 => $whkPercentage,
                'awfTariff'           => 'low',
                'awfTariffNote'       => 'Geen contract om van te lezen — de proforma-simulatie gaat altijd uit van de lage Awf-premie; werkgeverslasten zijn hierdoor mogelijk optimistisch. Dit raakt het netto salaris van de werknemer niet.',
                'engineVersion'       => $tables->id(),
            ],
            'bijzondereBeloningNote'   => 'Een eenmalige bijzondere beloning wordt bij het periodesalaris opgeteld en via de reguliere maandtabel doorgerekend (combinedLoon-schatting) — dit is NIET het wettelijke bijzonder tarief, dat is een fast-follow van de payroll-core-engine.',
            'grossPay'                 => $this->euros($result->grossPayCents),
            'loonheffing'              => $this->euros($result->loonheffingCents),
            'arbeidskorting'           => $this->euros($result->arbeidskortingCents),
            'volksverzekeringen'       => $this->euros($result->volksverzekeringenCents),
            'zvw'                      => $this->euros($result->zvwCents),
            'zvwMode'                  => $result->zvwMode,
            'zvwRate'                  => $result->zvwRate,
            'appliedTaxRate'           => $result->appliedTaxRate,
            'nettoPay'                 => $this->euros($result->nettoPayCents),
            'vakantiegeldReserved'     => $this->euros($result->vakantiegeldReservedCents),
            'vakantiegeldRate'         => $result->vakantiegeldRate,
            'awf'                      => $this->euros($result->awfCents),
            'aof'                      => $this->euros($result->aofCents),
            'wko'                      => $this->euros($result->wkoCents),
            'whk'                      => $this->euros($result->whkCents),
            'werknemersverzekeringen'  => $this->euros($result->werknemersverzekeringenCents),
            'employerCharges'          => $this->euros($result->employerChargesCents),
            'aboveLmax'                => $result->aboveLmax,
        ];

    }//end simulate()


    /**
     * Convert integer cents to a euro float rounded to 2 decimals.
     *
     * @param int $cents The cents amount.
     *
     * @return float
     */
    private function euros(int $cents): float
    {
        return round(($cents / 100), 2);

    }//end euros()


    /**
     * Require a numeric value, coercing string/int/float, throwing a Dutch
     * `\InvalidArgumentException` when absent or non-numeric.
     *
     * @param mixed  $value The raw value.
     * @param string $label The Dutch field label used in the error message.
     *
     * @return float
     *
     * @throws \InvalidArgumentException
     */
    private static function requireNumeric(mixed $value, string $label): float
    {
        if (is_numeric($value) === false) {
            throw new \InvalidArgumentException($label.' is verplicht en moet numeriek zijn.');
        }

        return (float) $value;

    }//end requireNumeric()


    /**
     * Require a positive numeric value (`> 0`), or fall back to `$default`
     * when the raw value is absent.
     *
     * @param mixed  $value   The raw value.
     * @param float  $default The default when the value is absent (null).
     * @param string $label   The Dutch field label used in the error message.
     *
     * @return float
     *
     * @throws \InvalidArgumentException
     */
    private static function requirePositiveFloat(mixed $value, float $default, string $label): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value) === false || ((float) $value) <= 0.0) {
            throw new \InvalidArgumentException($label.' moet een getal groter dan 0 zijn.');
        }

        return (float) $value;

    }//end requirePositiveFloat()


    /**
     * Require a non-negative numeric value (`>= 0`), or fall back to
     * `$default` when the raw value is absent.
     *
     * @param mixed  $value   The raw value.
     * @param float  $default The default when the value is absent (null).
     * @param string $label   The Dutch field label used in the error message.
     *
     * @return float
     *
     * @throws \InvalidArgumentException
     */
    private static function requireNonNegativeFloat(mixed $value, float $default, string $label): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value) === false || ((float) $value) < 0.0) {
            throw new \InvalidArgumentException($label.' moet een getal van 0 of hoger zijn.');
        }

        return (float) $value;

    }//end requireNonNegativeFloat()


    /**
     * Validate/default the loonheffingstabel colour (design.md D2: `wit`/
     * `groen`, default `wit`).
     *
     * @param mixed $value The raw value.
     *
     * @return string `wit` or `groen`.
     *
     * @throws \InvalidArgumentException
     */
    private static function requireTableColor(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'wit';
        }

        $color = strtolower(trim((string) $value));
        if (in_array($color, ['wit', 'groen'], true) === false) {
            throw new \InvalidArgumentException('Onbekende loonheffingstabel "'.$value.'" — moet "wit" of "groen" zijn.');
        }

        return $color;

    }//end requireTableColor()


    /**
     * Validate/default the Aof-tariefklasse (`laag`/`hoog`, default the
     * passed-in employer setting).
     *
     * @param mixed  $value   The raw value.
     * @param string $default The `SettingsService::getPayrollAofTariff()` default.
     *
     * @return string `laag` or `hoog`.
     *
     * @throws \InvalidArgumentException
     */
    private static function requireAofTariff(mixed $value, string $default): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $tariff = strtolower(trim((string) $value));
        if (in_array($tariff, ['laag', 'hoog'], true) === false) {
            throw new \InvalidArgumentException('Onbekende Aof-tariefklasse "'.$value.'" — moet "laag" of "hoog" zijn.');
        }

        return $tariff;

    }//end requireAofTariff()


    /**
     * Validate/default the wage period (`YYYY-MM`, default the current
     * month).
     *
     * @param mixed $value The raw value.
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private static function requirePeriod(mixed $value): string
    {
        if ($value === null || $value === '') {
            return (new \DateTimeImmutable())->format('Y-m');
        }

        $period = trim((string) $value);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new \InvalidArgumentException('Periode "'.$value.'" moet het formaat JJJJ-MM hebben.');
        }

        return $period;

    }//end requirePeriod()


    /**
     * Coerce a possibly-string/bool/int truthy value to bool, defaulting when
     * absent.
     *
     * @param mixed $value   The raw value.
     * @param bool  $default The default when the value is null.
     *
     * @return bool
     */
    private static function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value) === true) {
            return $value;
        }

        $normalised = strtolower(trim((string) $value));
        return in_array($normalised, ['1', 'true', 'ja', 'yes'], true) === true;

    }//end toBool()


    /**
     * Trim a possibly-empty raw value to a nullable string.
     *
     * @param mixed $value The raw value.
     *
     * @return string|null
     */
    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        return $string === '' ? null : $string;

    }//end nullableString()


}//end class
