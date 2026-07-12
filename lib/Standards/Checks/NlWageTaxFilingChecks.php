<?php

/**
 * NL Wage-Tax Filing Check Provider
 *
 * Executable checks for the Dutch loonaangifte filing + retention rules of the
 * payroll corpus (lib/Standards/rules/payroll.json), mapped onto the
 * LoonaangifteFiling object type: the filing must be electronic with a valid
 * tijdvak (nl-loonaangifte-tijdvak), submitted on or before the statutory
 * deadline (nl-loonaangifte-termijn), the payroll records retained for at
 * least 7 years (nl-loonadministratie-bewaarplicht), the tijdvakcode consistent
 * with the period + tijdvak (nl-loonaangifte-tijdvakcode), the deadline
 * correctly derived as period end + one calendar month with no weekend/holiday
 * extension (nl-loonaangifte-deadline-derivation), and an unsent filing not
 * approaching or past its deadline (nl-loonaangifte-deadline-alert). Each
 * predicate is side-effect free; the seedObjects() sample satisfies them all.
 *
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/changes/loonaangifte-filing-lifecycle/specs/loonaangifte-filing-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Standards\RuleCatalogue;

/**
 * Dutch loonaangifte filing + payroll-record-retention executable checks.
 */
final class NlWageTaxFilingChecks implements CheckProvider, SeedsObjects
{

    /**
     * Number of days before (and including) the deadline that an unsent filing
     * starts alerting (nl-loonaangifte-deadline-alert).
     *
     * @var int
     */
    private const ALERT_WINDOW_DAYS = 14;


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'LoonaangifteFiling' => [
                // Wet LB 1964 art. 28 / AWR art. 19 — the loonaangifte is filed
                // electronically for each wage period (a recognised NL tijdvak).
                'nl-loonaangifte-tijdvak' => static fn(array $o): bool => (($o['filingType'] ?? '') !== 'loonaangifte')
                    || ((($o['electronicallyFiled'] ?? false) === true)
                    && in_array((string) ($o['tijdvak'] ?? ''), ['maand', 'vierweken', 'kwartaal', 'jaar'], true)),
                // AWR art. 19 — the loonaangifte is filed and paid no later than the
                // deadline (last working day of the month following the wage period).
                'nl-loonaangifte-termijn' => static fn(array $o): bool => (($o['filingType'] ?? '') !== 'loonaangifte')
                    || self::onOrBefore($o, 'submittedDate', 'deadline'),
                // AWR art. 52 lid 4 — payroll administration is retained at least 7 years
                // after the end of the relevant financial year of the period.
                'nl-loonadministratie-bewaarplicht' => static fn(array $o): bool => (($o['filingType'] ?? '') !== 'loonaangifte')
                    || self::retainedYearsAfterPeriod($o, 7),
                // Belastingdienst LH 210 (2026) — the tijdvakcode matches the period + tijdvak.
                'nl-loonaangifte-tijdvakcode' => static fn(array $o): bool => self::isNlLoonaangifte($o) === false
                    || self::tijdvakcodeConsistent($o),
                // AWR art. 19 / LH 210 — the deadline is period end + 1 calendar month, no
                // weekend/holiday extension.
                'nl-loonaangifte-deadline-derivation' => static fn(array $o): bool => self::isNlLoonaangifte($o) === false
                    || self::deadlineDerivationCorrect($o),
                // AWR art. 19 — an unsent filing does not sit within 14 days of, or past, its deadline.
                'nl-loonaangifte-deadline-alert' => static fn(array $o): bool => self::isNlLoonaangifte($o) === false
                    || self::deadlineNotAlerting($o),
            ],
        ];

    }//end checks()


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, mixed>>
     */
    public static function seedSpec(): array
    {
        return [];

    }//end seedSpec()


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function seedObjects(): array
    {
        return [
            'LoonaangifteFiling' => [
                [
                    'period'              => '2026-01',
                    'jurisdiction'        => 'NL',
                    'filingType'          => 'loonaangifte',
                    'tijdvak'             => 'maand',
                    // 2026-02-28 (a Saturday) is the correct calendar-exact deadline for
                    // the 2026-01 period — nl-loonaangifte-deadline-derivation asserts no
                    // weekend/holiday extension, so this is NOT the same as "last working day".
                    'deadline'            => '2026-02-28',
                    'submittedDate'       => '2026-02-20',
                    'paidDate'            => '2026-02-20',
                    'electronicallyFiled' => true,
                    'retainedUntil'       => '2033-12-31',
                    'status'              => 'verzonden',
                    'tijdvakcode'         => '6010',
                ],
            ],
        ];

    }//end seedObjects()


    /**
     * True when the object is an NL loonaangifte filing (the scope of the
     * lifecycle-related tijdvakcode/deadline checks — DE/FR/US filings and
     * non-loonaangifte filingTypes are unaffected).
     *
     * @param array<string, mixed> $o The filing.
     *
     * @return bool
     */
    private static function isNlLoonaangifte(array $o): bool
    {
        return (string) ($o['filingType'] ?? '') === 'loonaangifte' && (string) ($o['jurisdiction'] ?? '') === 'NL';

    }//end isNlLoonaangifte()


    /**
     * True when the filing's tijdvakcode is consistent with its period + tijdvak
     * per the nl-loonaangifte-tijdvakcode rule's `parameters` code tables (read
     * from the corpus, never hard-coded here). A non-concept filing with no
     * tijdvakcode at all is a violation; a concept filing may still be missing
     * one. When the period/tijdvak shape yields no deterministic expectation
     * (e.g. an unrecognised vierweken period format), the check does not flag —
     * only structured, decidable mismatches are reported.
     *
     * @param array<string, mixed> $o The filing.
     *
     * @return bool
     */
    private static function tijdvakcodeConsistent(array $o): bool
    {
        $tijdvakcode = trim((string) ($o['tijdvakcode'] ?? ''));
        if ($tijdvakcode === '') {
            return (string) ($o['status'] ?? 'concept') === 'concept';
        }

        $parameters = self::tijdvakcodeParameters();
        if ($parameters === null) {
            return true;
        }

        $expected = self::expectedTijdvakcode(
            (string) ($o['tijdvak'] ?? ''),
            (string) ($o['period'] ?? ''),
            $parameters
        );

        return $expected === null || $tijdvakcode === $expected;

    }//end tijdvakcodeConsistent()


    /**
     * The `parameters` object of the nl-loonaangifte-tijdvakcode corpus rule, or
     * null when the rule is missing from the catalogue.
     *
     * @return array<string, mixed>|null
     */
    private static function tijdvakcodeParameters(): ?array
    {
        foreach (RuleCatalogue::all() as $rule) {
            if ((string) ($rule['id'] ?? '') !== 'nl-loonaangifte-tijdvakcode') {
                continue;
            }

            $parameters = ($rule['parameters'] ?? null);
            return is_array($parameters) === true ? $parameters : null;
        }

        return null;

    }//end tijdvakcodeParameters()


    /**
     * The expected tijdvakcode for a period + tijdvak, per the rule's code
     * tables. Returns null when the tijdvak/period shape has no deterministic
     * mapping (e.g. `jaar` needs only the year, `maand` needs YYYY-MM, and
     * `vierweken` needs a YYYY-P## period-index convention not otherwise
     * modelled on this schema).
     *
     * @param string               $tijdvak    The filing's tijdvak.
     * @param string               $period     The filing's period.
     * @param array<string, mixed> $parameters The rule's parameters.
     *
     * @return string|null
     */
    private static function expectedTijdvakcode(string $tijdvak, string $period, array $parameters): ?string
    {
        if ($tijdvak === 'jaar') {
            $jaarCode = ($parameters['jaarCode'] ?? null);
            return $jaarCode !== null ? (string) $jaarCode : null;
        }

        if ($tijdvak === 'maand' && preg_match('/^\d{4}-(\d{2})$/', $period, $m) === 1) {
            $maandCodes = (is_array($parameters['maandCodes'] ?? null) === true) ? $parameters['maandCodes'] : [];
            return isset($maandCodes[$m[1]]) === true ? (string) $maandCodes[$m[1]] : null;
        }

        if ($tijdvak === 'vierweken' && preg_match('/^\d{4}-P(\d{2})$/', $period, $m) === 1) {
            $codes = (is_array($parameters['vierwekenCodes'] ?? null) === true) ? $parameters['vierwekenCodes'] : [];
            $index = ((int) $m[1] - 1);
            return isset($codes[$index]) === true ? (string) $codes[$index] : null;
        }

        return null;

    }//end expectedTijdvakcode()


    /**
     * True when the filing's deadline equals period end + 1 calendar month, with
     * no weekend/holiday extension. Only `maand` (YYYY-MM) and `jaar` (YYYY..)
     * periods carry a calendar-derivable period end on this schema; `vierweken`
     * deadlines follow a different statutory table and are out of scope here.
     *
     * @param array<string, mixed> $o The filing.
     *
     * @return bool
     */
    private static function deadlineDerivationCorrect(array $o): bool
    {
        $tijdvak = (string) ($o['tijdvak'] ?? '');
        $period  = (string) ($o['period'] ?? '');

        if ($tijdvak === 'maand' && preg_match('/^(\d{4})-(\d{2})$/', $period, $m) === 1) {
            $expected = self::expectedDeadline((int) $m[1], (int) $m[2]);
        } elseif ($tijdvak === 'jaar' && preg_match('/^(\d{4})/', $period, $m) === 1) {
            $expected = self::expectedDeadline((int) $m[1], 12);
        } else {
            return true;
        }

        return (string) ($o['deadline'] ?? '') === $expected;

    }//end deadlineDerivationCorrect()


    /**
     * The last calendar day of the month following {$year}-{$month}, formatted
     * YYYY-MM-DD. No weekend/public-holiday adjustment is applied.
     *
     * @param int $year  Period-end year.
     * @param int $month Period-end month (1-12).
     *
     * @return string
     */
    private static function expectedDeadline(int $year, int $month): string
    {
        $followingMonth = ($month + 1);
        $followingYear  = $year;
        if ($followingMonth > 12) {
            $followingMonth = 1;
            $followingYear++;
        }

        $lastDay = (int) date('t', mktime(0, 0, 0, $followingMonth, 1, $followingYear));
        return sprintf('%04d-%02d-%02d', $followingYear, $followingMonth, $lastDay);

    }//end expectedDeadline()


    /**
     * True when the filing is already sent, or its deadline is more than 14
     * days away — false (a violation) once it is within the alert window or
     * already overdue, evaluated against the current date (the audit run date).
     *
     * @param array<string, mixed> $o The filing.
     *
     * @return bool
     */
    private static function deadlineNotAlerting(array $o): bool
    {
        if ((string) ($o['status'] ?? 'concept') === 'verzonden') {
            return true;
        }

        $deadline = strtotime((string) ($o['deadline'] ?? ''));
        if ($deadline === false) {
            return true;
        }

        $today         = (new \DateTimeImmutable('today'))->getTimestamp();
        $daysRemaining = (int) floor((($deadline - $today)) / 86400);

        return $daysRemaining > self::ALERT_WINDOW_DAYS;

    }//end deadlineNotAlerting()


    /**
     * True when the date at $dateKey is present and on or before the date at
     * $limitKey.
     *
     * @param array<string, mixed> $o        The filing.
     * @param string               $dateKey  Actual-date field.
     * @param string               $limitKey Deadline field.
     *
     * @return bool
     */
    private static function onOrBefore(array $o, string $dateKey, string $limitKey): bool
    {
        $date  = strtotime((string) ($o[$dateKey] ?? ''));
        $limit = strtotime((string) ($o[$limitKey] ?? ''));
        if ($date === false || $limit === false) {
            return false;
        }

        return $date <= $limit;

    }//end onOrBefore()


    /**
     * True when the retention date is at least $years after the end of the year
     * of the filing period.
     *
     * @param array<string, mixed> $o     The filing.
     * @param int                  $years Minimum retention years.
     *
     * @return bool
     */
    private static function retainedYearsAfterPeriod(array $o, int $years): bool
    {
        $retain = strtotime((string) ($o['retainedUntil'] ?? ''));
        $period = (string) ($o['period'] ?? '');
        if ($retain === false || preg_match('/^(\d{4})/', $period, $m) !== 1) {
            return false;
        }

        $required = mktime(0, 0, 0, 12, 31, ((int) $m[1] + $years));
        return $retain >= $required;

    }//end retainedYearsAfterPeriod()


}//end class
