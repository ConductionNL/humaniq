<?php

/**
 * Employee Cost Rate Service
 *
 * Resolves what one hour of an employee's time COSTS THE EMPLOYER — the
 * number a domain app multiplies by logged hours to get a cost allocation
 * (ADR-081 decision 4).
 *
 * This is not a wage. `EmploymentContract.hourlyWage` is the employee's gross
 * hourly wage; the employer additionally carries the werknemersverzekeringen
 * (Awf, Aof, Wko, Whk) and the Zvw werkgeversheffing. Costing case work at the
 * gross wage understates the real cost by the whole employer burden, which is
 * exactly the mistake this service exists to prevent — so `hourlyWage` is
 * never read here.
 *
 * Two sources, in priority order:
 *
 * 1. **Override** — `Employee.hourlyCostRateOverrideCents`, when set. Used
 *    verbatim. An override with no reason is refused rather than silently
 *    accepted: this number reaches a statutory IV3 submission through
 *    Shillinq, and an unexplained hand-entered rate is unauditable.
 * 2. **Derived** — the loaded cost from the employee's most recent Payslip:
 *    `(grossPay + werknemersverzekeringen + zvw) / hours`. Numerator and
 *    denominator come from the SAME payslip, so they cannot disagree about
 *    which period they describe.
 *
 * The denominator prefers the payslip's own `hoursWorked`, falling back to
 * contracted hours derived from `EmploymentContract.hoursPerWeek`. ADR-081
 * decision 4 words the denominator as "contracted hours"; taking the payslip's
 * own hours when it has them keeps both halves of the fraction on one record,
 * which matters for anyone paid by the hour, where contracted hours and paid
 * hours legitimately differ. For a salaried employee the two coincide and the
 * distinction is moot.
 *
 * Returns null rather than guessing when there is nothing to derive from. A
 * null rate is a visible "cannot cost this yet" state for the caller to
 * surface; a zero or a fabricated default would book a case as free.
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
 * Resolves an employee's employer cost per hour, in integer cents.
 */
class EmployeeCostRateService
{

    /**
     * Weeks in a year, for turning contracted hours-per-week into the hours
     * behind one monthly payslip. 52 whole weeks, not 52.18 — a payroll month
     * is an administrative twelfth of a year, not an astronomical one.
     *
     * @var float
     */
    private const WEEKS_PER_YEAR = 52.0;

    /**
     * Months in a year.
     *
     * @var int
     */
    private const MONTHS_PER_YEAR = 12;

    /**
     * Rate came from a hand-set override.
     *
     * @var string
     */
    public const SOURCE_OVERRIDE = 'override';

    /**
     * Rate was derived from the employee's most recent payslip.
     *
     * @var string
     */
    public const SOURCE_DERIVED = 'derived';


    /**
     * Resolve the employer cost per hour for one employee.
     *
     * Pure — the caller supplies the already-loaded Employee record and its
     * most recent Payslip, so this is testable without OpenRegister and cannot
     * pick a different payslip than the caller believes it is using.
     *
     * @param array<string, mixed>      $employee The Employee object as an array.
     * @param array<string, mixed>|null $payslip  Most recent Payslip, or null when the employee has never been paid.
     * @param float|null                $hoursPerWeek Contracted hours per week from the active EmploymentContract, when known.
     *
     * @return array{centsPerHour: int, source: string, basis: string}|null
     *         Null when no rate can be established. `basis` names what the
     *         number was computed from, for display next to a cost figure.
     *
     * @throws InvalidArgumentException When an override amount is set without a reason.
     */
    public function resolve(array $employee, ?array $payslip=null, ?float $hoursPerWeek=null): ?array
    {
        $override = $this->resolveOverride(employee: $employee);
        if ($override !== null) {
            return $override;
        }

        return $this->deriveFromPayslip(payslip: $payslip, hoursPerWeek: $hoursPerWeek);

    }//end resolve()


    /**
     * Read the hand-set override, refusing an unexplained one.
     *
     * @param array<string, mixed> $employee The Employee object as an array.
     *
     * @return array{centsPerHour: int, source: string, basis: string}|null Null when no override is set.
     *
     * @throws InvalidArgumentException When an amount is set without a reason.
     */
    private function resolveOverride(array $employee): ?array
    {
        $cents = ($employee['hourlyCostRateOverrideCents'] ?? null);
        if ($cents === null || $cents === '') {
            return null;
        }

        $cents = (int) $cents;
        if ($cents < 0) {
            throw new InvalidArgumentException('hourlyCostRateOverrideCents must not be negative, got: '.$cents);
        }

        $reason = trim((string) ($employee['hourlyCostRateOverrideReason'] ?? ''));
        if ($reason === '') {
            // Refused rather than ignored: silently falling back to the derived
            // rate would hide that someone deliberately set a number, and
            // silently using it would put an unauditable figure into an IV3
            // submission.
            throw new InvalidArgumentException(
                'hourlyCostRateOverrideCents is set without hourlyCostRateOverrideReason; '
                .'an override that reaches a statutory submission must say why it exists'
            );
        }

        return [
            'centsPerHour' => $cents,
            'source'       => self::SOURCE_OVERRIDE,
            'basis'        => $reason,
        ];

    }//end resolveOverride()


    /**
     * Derive the loaded cost per hour from one payslip.
     *
     * @param array<string, mixed>|null $payslip      The Payslip object as an array.
     * @param float|null                $hoursPerWeek Contracted hours per week, used only when the payslip carries no hours.
     *
     * @return array{centsPerHour: int, source: string, basis: string}|null Null when the payslip cannot yield a rate.
     */
    private function deriveFromPayslip(?array $payslip, ?float $hoursPerWeek): ?array
    {
        if ($payslip === null) {
            return null;
        }

        $grossCents = $this->toCents(value: ($payslip['grossPay'] ?? null));
        if ($grossCents === null || $grossCents <= 0) {
            return null;
        }

        // The employer burden. Both are informative lines on the payslip and
        // both are genuinely paid by the employer, so both belong in a cost
        // rate. A missing line is treated as zero — a payslip that omits Zvw
        // is understating the cost, not invalidating it.
        $employerChargesCents = ($this->toCents(value: ($payslip['werknemersverzekeringen'] ?? null)) ?? 0)
            + ($this->toCents(value: ($payslip['zvw'] ?? null)) ?? 0);

        $hours = $this->resolveHours(payslip: $payslip, hoursPerWeek: $hoursPerWeek);
        if ($hours === null || $hours <= 0.0) {
            return null;
        }

        $loadedCents = ($grossCents + $employerChargesCents);
        $period      = (string) ($payslip['period'] ?? '');
        $basis       = 'derived from payslip';
        if ($period !== '') {
            $basis = 'derived from payslip '.$period;
        }

        return [
            'centsPerHour' => (int) round($loadedCents / $hours),
            'source'       => self::SOURCE_DERIVED,
            'basis'        => $basis,
        ];

    }//end deriveFromPayslip()


    /**
     * Hours behind one payslip: its own `hoursWorked` when it has them, else
     * contracted hours for a month.
     *
     * @param array<string, mixed> $payslip      The Payslip object as an array.
     * @param float|null           $hoursPerWeek Contracted hours per week.
     *
     * @return float|null Null when neither source yields hours.
     */
    private function resolveHours(array $payslip, ?float $hoursPerWeek): ?float
    {
        $worked = ($payslip['hoursWorked'] ?? null);
        if ($worked !== null && $worked !== '' && (float) $worked > 0.0) {
            return (float) $worked;
        }

        if ($hoursPerWeek === null || $hoursPerWeek <= 0.0) {
            return null;
        }

        return ($hoursPerWeek * self::WEEKS_PER_YEAR / self::MONTHS_PER_YEAR);

    }//end resolveHours()


    /**
     * Convert a euro amount to integer cents.
     *
     * Payslip money fields are euro decimals, while a cost rate is carried in
     * cents so that rate x hours cannot accumulate IEEE-754 drift across a
     * quarter's allocations.
     *
     * @param mixed $value Raw field value.
     *
     * @return int|null Null when the value is absent or not numeric.
     */
    private function toCents(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_numeric($value) === false) {
            return null;
        }

        return (int) round(((float) $value) * 100);

    }//end toCents()
}//end class
