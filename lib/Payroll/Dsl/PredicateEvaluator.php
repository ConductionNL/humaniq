<?php

/**
 * Predicate Evaluator
 *
 * The DSL's predicate vocabulary (jurisdiction-packs design.md D3), used by a
 * step's `when` gate and by boolean `derive` bindings: `eq ne lt lte gt gte`,
 * `and or not`, `ageReached(dob, years, asOf, granularity)` and `yearOf(date)`.
 *
 * These two date predicates are what turn NL's `isAowAge()` and `schijvenSet()`
 * PHP branches into declared data (REQ-JP-004): the AOW-age switch becomes a
 * table-set SELECTION rather than an interpreter branch.
 *
 * `ageReached` with `granularity: month` states explicitly what `isAowAge()`
 * only implied at HEAD — that AOW-age applies from the FIRST DAY OF THE MONTH
 * in which the employee reaches the statutory age. HEAD achieved that by
 * comparing the reach-date against the period's last day; declaring the
 * granularity makes the rule legible instead of an emergent property of which
 * date the caller happened to pass.
 *
 * An unparseable or absent date yields `false` (the conservative default —
 * `dateOfBirth` is not a required Employee field), exactly as at HEAD.
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl;

use DateTimeImmutable;
use Throwable;

/**
 * Evaluates the closed predicate vocabulary.
 */
final class PredicateEvaluator
{

    /**
     * The scalar comparison predicates.
     *
     * @var array<int, string>
     */
    public const COMPARISONS = ['eq', 'ne', 'lt', 'lte', 'gt', 'gte'];

    /**
     * The boolean composition predicates.
     *
     * @var array<int, string>
     */
    public const BOOLEANS = ['and', 'or', 'not'];

    /**
     * The date predicates.
     *
     * @var array<int, string>
     */
    public const DATES = ['ageReached', 'yearOf'];


    /**
     * @param RefResolver $refs The reference resolver.
     */
    public function __construct(private readonly RefResolver $refs)
    {

    }//end __construct()


    /**
     * Every predicate op this evaluator knows.
     *
     * @return array<int, string>
     */
    public function vocabulary(): array
    {
        return array_merge(self::COMPARISONS, self::BOOLEANS, self::DATES);

    }//end vocabulary()


    /**
     * Evaluate a predicate spec, or resolve a bare reference.
     *
     * @param mixed       $spec A predicate object, a reference, or a literal.
     * @param StepContext $ctx  The run context.
     *
     * @return mixed
     *
     * @throws DslException When the predicate op is unknown.
     */
    public function evaluate(mixed $spec, StepContext $ctx): mixed
    {
        if (is_array($spec) === false) {
            return $this->refs->value($spec, $ctx);
        }

        $op = (string) ($spec['op'] ?? '');

        if (in_array($op, self::COMPARISONS, true) === true) {
            return $this->compare($op, $spec, $ctx);
        }

        if (in_array($op, self::BOOLEANS, true) === true) {
            return $this->boolean($op, $spec, $ctx);
        }

        if ($op === 'ageReached') {
            return $this->ageReached($spec, $ctx);
        }

        if ($op === 'yearOf') {
            return $this->yearOf($spec, $ctx);
        }

        throw new DslException('Pack: onbekend predicaat "'.$op.'" (toegestaan: '.implode(', ', $this->vocabulary()).').');

    }//end evaluate()


    /**
     * Evaluate a spec and coerce it to a boolean gate.
     *
     * @param mixed       $spec The predicate spec.
     * @param StepContext $ctx  The run context.
     *
     * @return bool
     */
    public function truthy(mixed $spec, StepContext $ctx): bool
    {
        return (bool) $this->evaluate($spec, $ctx);

    }//end truthy()


    /**
     * Scalar comparison. Equality is strict (the HEAD calculator's `!==`
     * semantics); the ordering comparisons are numeric.
     *
     * @param string               $op   The comparison op.
     * @param array<string, mixed> $spec The predicate spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return bool
     */
    private function compare(string $op, array $spec, StepContext $ctx): bool
    {
        $left  = $this->evaluate(($spec['left'] ?? null), $ctx);
        $right = $this->evaluate(($spec['right'] ?? null), $ctx);

        return match ($op) {
            'eq'    => ($left === $right),
            'ne'    => ($left !== $right),
            'lt'    => ($left < $right),
            'lte'   => ($left <= $right),
            'gt'    => ($left > $right),
            default => ($left >= $right),
        };

    }//end compare()


    /**
     * Boolean composition over `of`.
     *
     * @param string               $op   The boolean op.
     * @param array<string, mixed> $spec The predicate spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return bool
     *
     * @throws DslException When `of` is missing.
     */
    private function boolean(string $op, array $spec, StepContext $ctx): bool
    {
        if (array_key_exists('of', $spec) === false) {
            throw new DslException('Pack: predicaat "'.$op.'" mist het veld "of".');
        }

        if ($op === 'not') {
            return $this->truthy($spec['of'], $ctx) === false;
        }

        $operands = $spec['of'];
        if (is_array($operands) === false || $operands === []) {
            throw new DslException('Pack: predicaat "'.$op.'" verwacht een niet-lege lijst in "of".');
        }

        return $this->fold($op, $operands, $ctx);

    }//end boolean()


    /**
     * Short-circuiting fold over an `and`/`or` operand list.
     *
     * @param string            $op       The boolean op.
     * @param array<int, mixed> $operands The operands.
     * @param StepContext       $ctx      The run context.
     *
     * @return bool
     */
    private function fold(string $op, array $operands, StepContext $ctx): bool
    {
        $shortCircuit = ($op === 'or');

        foreach ($operands as $operand) {
            if ($this->truthy($operand, $ctx) === $shortCircuit) {
                return $shortCircuit;
            }
        }

        return ($shortCircuit === false);

    }//end fold()


    /**
     * Whether a date of birth reaches a given age by a given date.
     *
     * `granularity: month` — true from the first day of the month in which the
     * age is reached (NL's AOW rule, RV2026 tabel 3 toelichting).
     * `granularity: day` — true from the exact day the age is reached.
     *
     * @param array<string, mixed> $spec The predicate spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return bool
     */
    private function ageReached(array $spec, StepContext $ctx): bool
    {
        $birth = trim((string) $this->evaluate(($spec['dob'] ?? null), $ctx));
        if ($birth === '') {
            return false;
        }

        $years       = (int) $this->evaluate(($spec['years'] ?? null), $ctx);
        $asOf        = (string) $this->evaluate(($spec['asOf'] ?? null), $ctx);
        $granularity = (string) ($spec['granularity'] ?? 'day');

        try {
            $reached = (new DateTimeImmutable($birth))->modify('+'.$years.' years');
            $limit   = new DateTimeImmutable($asOf);
        } catch (Throwable $e) {
            return false;
        }

        if ($granularity === 'month') {
            return $reached->format('Y-m') <= $limit->format('Y-m');
        }

        return $reached <= $limit;

    }//end ageReached()


    /**
     * The calendar year of a date, or a declared default when the date is
     * absent or unparseable.
     *
     * @param array<string, mixed> $spec The predicate spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return int
     *
     * @throws DslException When no default is declared for an unparseable date.
     */
    private function yearOf(array $spec, StepContext $ctx): int
    {
        $value = trim((string) $this->evaluate(($spec['value'] ?? null), $ctx));

        // An empty date must NOT reach DateTimeImmutable: `new DateTimeImmutable('')`
        // silently returns the CURRENT time rather than throwing, which would make
        // this a clock read (REQ-JP-008) and yield the running year instead of the
        // declared default.
        if ($value === '') {
            return $this->fallbackYear($spec, null);
        }

        try {
            return (int) (new DateTimeImmutable($value))->format('Y');
        } catch (Throwable $e) {
            return $this->fallbackYear($spec, $e);
        }

    }//end yearOf()


    /**
     * The declared `default` for an absent or unparseable `yearOf` date.
     *
     * @param array<string, mixed> $spec     The predicate spec.
     * @param Throwable|null      $previous The parse failure, when there was one.
     *
     * @return int
     *
     * @throws DslException When no default is declared.
     */
    private function fallbackYear(array $spec, ?Throwable $previous): int
    {
        if (array_key_exists('default', $spec) === false) {
            throw new DslException('Pack: onleesbare of ontbrekende datum voor "yearOf" en geen "default" gedeclareerd.', 0, $previous);
        }

        return (int) $spec['default'];

    }//end fallbackYear()


}//end class
