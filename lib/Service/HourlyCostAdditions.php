<?php

/**
 * Hourly Cost Additions
 *
 * The per-hour employer costs that sit on top of the wage in
 * {@see EmployeeCostRateService} — overhead, equipment, workplace, overtime
 * (ADR-081 decision 4). Owns their validation, their two amount forms, and the
 * one composition rule that can silently produce a wrong number.
 *
 * Split out of `EmployeeCostRateService` so the wage base and the additions
 * are separately readable and separately testable: the service answers "what
 * does this employment cost per hour", this answers "what may be added to it
 * and how much does that come to".
 *
 * An addition states its amount EITHER as a fixed `centsPerHour` or as a
 * `percentageOfWage`, never both. A percentage is resolved against the WAGE
 * BASE ONLY, never a running total — compounding would make the result depend
 * on the order the additions happen to be listed in.
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
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use InvalidArgumentException;

/**
 * Validates and totals the per-hour cost additions.
 */
class HourlyCostAdditions
{

    /**
     * The addition key reserved for the overtime uplift — the one key with a
     * correctness rule attached.
     *
     * @var string
     */
    public const KEY_OVERTIME = 'overtime';


    /**
     * Coerce raw addition entries into the canonical shape, resolving each to
     * plain cents so everything downstream sees a simple sum.
     *
     * @param array<int, mixed> $raw       Raw addition entries.
     * @param int               $wageCents The wage base, for resolving percentages.
     *
     * @return array<int, array{key: string, centsPerHour: int, source: string, basis: string}>
     *
     * @throws InvalidArgumentException When an addition carries no key, no basis, or both amount forms.
     */
    public function normalise(array $raw, int $wageCents): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $cents = $this->resolveCents(entry: $entry, wageCents: $wageCents);
            if ($cents === null) {
                continue;
            }

            $out[] = [
                'key'          => $this->requireKey(entry: $entry),
                'centsPerHour' => $cents,
                'source'       => (trim((string) ($entry['source'] ?? '')) ?: 'manual'),
                'basis'        => $this->requireBasis(entry: $entry),
            ];
        }

        return $out;

    }//end normalise()


    /**
     * Refuse an overtime addition on a wage base that already blends overtime.
     *
     * A base that divides total pay by total hours has any overtime premium
     * already averaged across every hour; adding an overtime component on top
     * charges it twice — a wrong number that would reach a CBS submission
     * looking entirely plausible. Contract-derived bases never blend, which is
     * one reason the contract is the basis; an imported one might.
     *
     * @param array<int, array<string, mixed>> $additions             Normalised additions.
     * @param bool                             $wageBaseBlendsOvertime Whether the base already includes overtime pay.
     *
     * @return void
     *
     * @throws InvalidArgumentException When both are true.
     */
    public function assertCompatible(array $additions, bool $wageBaseBlendsOvertime): void
    {
        if ($wageBaseBlendsOvertime === false) {
            return;
        }

        foreach ($additions as $addition) {
            if (($addition['key'] ?? '') === self::KEY_OVERTIME) {
                throw new InvalidArgumentException(
                    'an "'.self::KEY_OVERTIME.'" addition cannot be applied to a wage base that '
                    .'already blends overtime: the base divides total pay by total hours, so the '
                    .'premium is already averaged into every hour and would be charged twice'
                );
            }
        }

    }//end assertCompatible()


    /**
     * Sum normalised additions.
     *
     * @param array<int, array<string, mixed>> $additions Normalised additions.
     *
     * @return int Total cents per hour.
     */
    public function total(array $additions): int
    {
        $total = 0;
        foreach ($additions as $addition) {
            $total += (int) $addition['centsPerHour'];
        }

        return $total;

    }//end total()


    /**
     * The addition's key, refusing an unnamed one.
     *
     * @param array<string, mixed> $entry The raw addition entry.
     *
     * @return string
     *
     * @throws InvalidArgumentException When absent.
     */
    private function requireKey(array $entry): string
    {
        $key = trim((string) ($entry['key'] ?? ''));
        if ($key === '') {
            throw new InvalidArgumentException('an hourly cost addition must carry a key');
        }

        return $key;

    }//end requireKey()


    /**
     * The addition's basis, refusing an unexplained one.
     *
     * Same reasoning as an unexplained override: this figure reaches a
     * statutory submission, and "+ EUR 12/h from somewhere" cannot be audited
     * or defended.
     *
     * @param array<string, mixed> $entry The raw addition entry.
     *
     * @return string
     *
     * @throws InvalidArgumentException When absent.
     */
    private function requireBasis(array $entry): string
    {
        $basis = trim((string) ($entry['basis'] ?? ''));
        if ($basis === '') {
            throw new InvalidArgumentException(
                'the hourly cost addition "'.trim((string) ($entry['key'] ?? '')).'" must carry a '
                .'basis explaining the amount'
            );
        }

        return $basis;

    }//end requireBasis()


    /**
     * Resolve one addition's amount to integer cents, from whichever of the
     * two forms it states.
     *
     * @param array<string, mixed> $entry     The raw addition entry.
     * @param int                  $wageCents The wage base, for a percentage.
     *
     * @return int|null Null when the entry states no usable amount.
     *
     * @throws InvalidArgumentException When both forms are present.
     */
    private function resolveCents(array $entry, int $wageCents): ?int
    {
        $fixed      = ($entry['centsPerHour'] ?? null);
        $percentage = ($entry['percentageOfWage'] ?? null);

        $hasFixed      = $this->isNumeric(value: $fixed);
        $hasPercentage = $this->isNumeric(value: $percentage);

        if ($hasFixed === true && $hasPercentage === true) {
            throw new InvalidArgumentException(
                'the hourly cost addition "'.trim((string) ($entry['key'] ?? '')).'" states both a fixed '
                .'centsPerHour and a percentageOfWage; an amount with two readings has no defensible value'
            );
        }

        if ($hasFixed === true) {
            return (int) $fixed;
        }

        if ($hasPercentage === true) {
            return (int) round($wageCents * ((float) $percentage / 100.0));
        }

        return null;

    }//end resolveCents()


    /**
     * Whether a raw field carries a usable number.
     *
     * @param mixed $value Raw field value.
     *
     * @return bool
     */
    private function isNumeric(mixed $value): bool
    {
        return ($value !== null && $value !== '' && is_numeric($value) === true);

    }//end isNumeric()
}//end class
