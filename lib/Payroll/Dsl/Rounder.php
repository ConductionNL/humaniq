<?php

/**
 * Rounder
 *
 * The DSL's `round` modifier (jurisdiction-packs design.md D3), applicable to
 * every step and binding: `{mode: floor|ceil|nearest, unit: cent|euro|decimals}`.
 *
 * NL's Rekenvoorschriften rounding arcana is per-step DATA, not control flow —
 * this class is the whole of it, and it reproduces `PayrollCalculator`'s four
 * deleted private helpers at HEAD bit-for-bit:
 *
 * - `{floor, euro}`             = `floorEuroCents()` — schijventarief X1
 * - `{ceil, euro}`              = `ceilEuroCents()`  — AHK/ARK/OUK heffingskortingen
 * - `{nearest, cent}`           = `round2Cents()`    — every tijdvakbedrag
 * - `{nearest, decimals: 3}`    = `round5Cents()`    — the ARK opbouw-term rule
 *   (5 decimals of a euro = 3 decimals in cents-space)
 *
 * The euro/cent modes pre-round to 4 decimal places before flooring/ceiling to
 * absorb float noise from the percentage arithmetic — the exact guard the HEAD
 * helpers carried, well within the 100-cent granularity being floored to.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl;

/**
 * Applies a declared `round` modifier to a computed value.
 */
final class Rounder
{

    /**
     * The rounding modes a `round` modifier may declare.
     *
     * @var array<int, string>
     */
    public const MODES = ['floor', 'ceil', 'nearest'];

    /**
     * The rounding units a `round` modifier may declare.
     *
     * @var array<int, string>
     */
    public const UNITS = ['cent', 'euro', 'decimals'];


    /**
     * Apply a declared `round` modifier, or pass the value through unchanged
     * when the step declares none.
     *
     * @param int|float                 $value The raw computed value.
     * @param array<string, mixed>|null $spec  The declared `round` modifier, or null.
     *
     * @return int|float
     *
     * @throws DslException When the modifier declares an unknown mode or unit.
     *
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
     */
    public function apply(int|float $value, ?array $spec): int|float
    {
        if ($spec === null) {
            return $value;
        }

        $mode = (string) ($spec['mode'] ?? '');
        $unit = (string) ($spec['unit'] ?? '');

        if (in_array($mode, self::MODES, true) === false) {
            throw new DslException('Pack: onbekende afrondingsmodus "'.$mode.'" (toegestaan: '.implode(', ', self::MODES).').');
        }

        return match ($unit) {
            'euro'     => $this->toEuro((float) $value, $mode),
            'cent'     => $this->toCent((float) $value, $mode),
            'decimals' => $this->toDecimals((float) $value, $mode, (int) ($spec['decimals'] ?? 0)),
            default    => throw new DslException('Pack: onbekende afrondingseenheid "'.$unit.'" (toegestaan: '.implode(', ', self::UNITS).').'),
        };

    }//end apply()


    /**
     * Round to a whole-euro (100-cent) boundary — the Rekenvoorschriften
     * floorEuro/ceilEuro rule.
     *
     * @param float  $value The raw cents amount.
     * @param string $mode  One of floor/ceil/nearest.
     *
     * @return int
     */
    private function toEuro(float $value, string $mode): int
    {
        $euros = (round($value, 4) / 100);

        return match ($mode) {
            'floor'   => ((int) floor($euros) * 100),
            'ceil'    => ((int) ceil($euros) * 100),
            default   => ((int) round($euros) * 100),
        };

    }//end toEuro()


    /**
     * Round to a whole cent — the Rekenvoorschriften tijdvakbedrag rule.
     *
     * @param float  $value The raw cents amount.
     * @param string $mode  One of floor/ceil/nearest.
     *
     * @return int
     */
    private function toCent(float $value, string $mode): int
    {
        $cents = round($value, 4);

        return match ($mode) {
            'floor'  => (int) floor($cents),
            'ceil'   => (int) ceil($cents),
            default  => (int) round($cents),
        };

    }//end toCent()


    /**
     * Round to a fixed number of decimal places (in the value's own scale).
     *
     * @param float  $value    The raw value.
     * @param string $mode     One of floor/ceil/nearest.
     * @param int    $decimals The number of decimal places.
     *
     * @return float
     */
    private function toDecimals(float $value, string $mode, int $decimals): float
    {
        $factor = (10 ** $decimals);

        return match ($mode) {
            'floor'  => (floor($value * $factor) / $factor),
            'ceil'   => (ceil($value * $factor) / $factor),
            default  => round($value, $decimals),
        };

    }//end toDecimals()


}//end class
