<?php

/**
 * NL Wage-Tax Filing Check Provider
 *
 * Executable checks for the Dutch loonaangifte filing + retention rules of the
 * payroll corpus (lib/Standards/rules/payroll.json), mapped onto the
 * LoonaangifteFiling object type: the filing must be electronic with a valid
 * tijdvak (nl-loonaangifte-tijdvak), submitted on or before the statutory
 * deadline (nl-loonaangifte-termijn), and the payroll records retained for at
 * least 7 years (nl-loonadministratie-bewaarplicht). Each predicate is
 * side-effect free; the seedObjects() sample satisfies them all.
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
 * @spec openspec/changes/hrm-rule-engine/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Dutch loonaangifte filing + payroll-record-retention executable checks.
 */
final class NlWageTaxFilingChecks implements CheckProvider, SeedsObjects
{


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
                    'deadline'            => '2026-02-27',
                    'submittedDate'       => '2026-02-20',
                    'paidDate'            => '2026-02-20',
                    'electronicallyFiled' => true,
                    'retainedUntil'       => '2033-12-31',
                ],
            ],
        ];

    }//end seedObjects()


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
