<?php

/**
 * Bracket Op
 *
 * `bracket(value, table, unit, mode: affine)` (jurisdiction-packs design.md D3).
 *
 * Selects the first row whose `tot` reaches or exceeds `value` (a `null` `tot`
 * matches unconditionally — the unbounded top bracket), then computes the
 * affine form `(value - a) * percentage / 100 + c`.
 *
 * NL use: the schijventarief X1. NL's tables ship precomputed `a`/`c`
 * constants per row, so the affine form is exact; a progressive-sum mode
 * (accumulating each bracket's own slice) is a NAMED FOLLOW-UP, not part of
 * this MVP (design.md D3 / the proposal's Non-Goals).
 *
 * **`unit` is required and declared, never assumed.** The corpus carries no
 * unit marker on its rows: `tot`/`a`/`c` are euro-denominated in NL's tables
 * while `percentage` is always a percentage. A pack declares `unit: "euro"`
 * and the op converts through `TaxTables`' own euro-to-cents rule — the same
 * conversion `TaxTables::schijven()` applied at HEAD, in the same place.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll\Dsl\Ops
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

namespace OCA\Hrmq\Payroll\Dsl\Ops;

use OCA\Hrmq\Payroll\Dsl\DslException;
use OCA\Hrmq\Payroll\Dsl\StepContext;

/**
 * Affine bracket-table lookup.
 */
final class BracketOp extends AbstractOp
{

    /**
     * The money units a bracket table may declare for its `tot`/`a`/`c` fields.
     *
     * @var array<int, string>
     */
    public const UNITS = ['euro', 'cent'];


    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function name(): string
    {
        return 'bracket';

    }//end name()


    /**
     * Pick the applicable row and apply the affine form.
     *
     * @param array<string, mixed> $spec The declared spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return int|float
     *
     * @throws DslException When the mode/unit is unknown or the table is malformed.
     */
    public function evaluate(array $spec, StepContext $ctx): mixed
    {
        $mode = (string) ($spec['mode'] ?? '');
        if ($mode !== 'affine') {
            throw new DslException('Pack: op "bracket" ondersteunt alleen mode "affine", niet "'.$mode.'" (progressive is een benoemde follow-up).');
        }

        $unit = (string) ($spec['unit'] ?? '');
        if (in_array($unit, self::UNITS, true) === false) {
            throw new DslException('Pack: op "bracket" verwacht een gedeclareerde "unit" ('.implode('|', self::UNITS).'); de tabelcorpus draagt zelf geen eenheid.');
        }

        $value = $this->num($spec, 'value', $ctx);
        $rows  = $this->refs->value(($spec['table'] ?? null), $ctx);

        if (is_array($rows) === false || $rows === []) {
            throw new DslException('Pack: op "bracket" verwacht een niet-lege rijenlijst in "table".');
        }

        $row = $this->select($rows, $value, $unit, $ctx);

        $a = $this->money($row['a'], $unit, $ctx);
        $c = $this->money($row['c'], $unit, $ctx);

        return ((($value - $a) * (float) $row['percentage']) / 100) + $c;

    }//end evaluate()


    /**
     * The first row whose `tot` reaches or exceeds the value; a null `tot`
     * matches unconditionally. Falls back to the last row, as at HEAD.
     *
     * @param array<int, array<string, mixed>> $rows  The bracket rows, ascending.
     * @param int|float                        $value The subject value.
     * @param string                           $unit  The declared money unit.
     * @param StepContext                      $ctx   The run context.
     *
     * @return array<string, mixed>
     */
    private function select(array $rows, int|float $value, string $unit, StepContext $ctx): array
    {
        foreach ($rows as $row) {
            if (($row['tot'] ?? null) === null) {
                return $row;
            }

            if ($value <= $this->money($row['tot'], $unit, $ctx)) {
                return $row;
            }
        }

        return end($rows);

    }//end select()


    /**
     * Convert a declared row money field to cents per the declared unit.
     *
     * @param mixed       $value The raw field value.
     * @param string      $unit  The declared money unit.
     * @param StepContext $ctx   The run context.
     *
     * @return int
     */
    private function money(mixed $value, string $unit, StepContext $ctx): int
    {
        if ($unit === 'cent') {
            return (int) $value;
        }

        return $ctx->tables()->toCents((float) $value);

    }//end money()


}//end class
