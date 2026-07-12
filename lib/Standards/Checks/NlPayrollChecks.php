<?php

/**
 * NL Payroll Check Provider
 *
 * Executable checks for the Dutch payroll / loonheffingen sub-domain of the
 * payroll corpus (lib/Standards/rules/payroll.json). Maps the machine-checkable
 * NL wage-tax, social-insurance, minimum-wage, vakantiebijslag, WKR, anoniemen-
 * tarief and 30%-ruling rules onto the Payslip / Employee / EmploymentContract
 * object types, plus the global payroll-to-GL reconciliation and withholding-
 * liability-clearing controls on PayrollRun and the EU A1 posted-worker rule on
 * Employee. Each predicate is side-effect free and decides compliance from the
 * structured fields declared in lib/Settings/register.d/hr-objects.json; the
 * seedObjects() samples satisfy every predicate keyed to their type.
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
 * Dutch payroll / loonheffingen + global GL-control + EU A1 executable checks.
 */
final class NlPayrollChecks implements CheckProvider, SeedsObjects
{


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'Payslip'            => [
                // Wet LB 1964 art. 27 — a loonheffing must be withheld at each payment.
                'nl-loonheffingen-inhouding'         => static fn(array $o): bool => self::numeric($o, 'loonheffing') && ((float) $o['loonheffing']) >= 0.0,
                // Wfsv / art. 27b — premie volksverzekeringen is levied inside the combined
                // loonheffing, so it must be present and not exceed the total loonheffing.
                'nl-loonheffingen-volksverzekeringen' => static fn(array $o): bool => self::numeric($o, 'volksverzekeringen')
                    && self::numeric($o, 'loonheffing')
                    && ((float) $o['volksverzekeringen']) >= 0.0
                    && ((float) $o['volksverzekeringen']) <= ((float) $o['loonheffing']),
                // Wfsv — employer-borne werknemersverzekeringen (WW/WIA/ZW) over the wage.
                'nl-werknemersverzekeringen-werkgever' => static fn(array $o): bool => self::numeric($o, 'werknemersverzekeringen')
                    && ((float) $o['werknemersverzekeringen']) >= 0.0,
                // Zvw werkgeversheffing — when the employer-levy mode applies, the 2026 rate is 6,10%.
                'nl-zvw-werkgeversheffing'           => static fn(array $o): bool => ((string) ($o['zvwMode'] ?? '') !== 'werkgeversheffing')
                    || (self::numeric($o, 'zvwRate') && self::ratesEqual((float) $o['zvwRate'], 6.10) && self::numeric($o, 'zvw')),
                // Zvw ingehouden bijdrage — when the withheld mode applies, the 2026 rate is 4,85%.
                'nl-zvw-inhouding'                   => static fn(array $o): bool => ((string) ($o['zvwMode'] ?? '') !== 'inhouding')
                    || (self::numeric($o, 'zvwRate') && self::ratesEqual((float) $o['zvwRate'], 4.85) && self::numeric($o, 'zvw')),
                // Wet LB 1964 art. 26b — the anoniementarief is exactly 52% when applied, and
                // the effective applied rate must be consistent with that flag.
                'nl-anoniementarief'                 => static fn(array $o): bool => self::numeric($o, 'appliedTaxRate')
                    && ((($o['anoniementariefApplied'] ?? false) === true)
                    ? self::ratesEqual((float) $o['appliedTaxRate'], 52.0)
                    : ((float) $o['appliedTaxRate']) < 52.0),
                // WML art. 15 — vakantiebijslag is at least 8% of gross, and the reserved
                // amount reconciles to that rate applied to the gross pay (to the cent).
                'nl-vakantiebijslag-8procent'        => static fn(array $o): bool => self::numeric($o, 'vakantiegeldRate')
                    && ((float) $o['vakantiegeldRate']) >= 8.0
                    && self::centsEqual((float) ($o['vakantiegeldReserved'] ?? 0), (((float) ($o['grossPay'] ?? 0)) * ((float) $o['vakantiegeldRate']) / 100)),
                // Werkkostenregeling — designated allowances charged to the vrije ruimte may
                // not push the remaining ruimte negative without recording the excess.
                'nl-wkr-vrije-ruimte'                => static fn(array $o): bool => self::numeric($o, 'wkrVrijeRuimteRemaining')
                    && (((float) $o['wkrVrijeRuimteRemaining']) >= 0.0 || self::numeric($o, 'wkrExcess')),
                // Wet LB 1964 art. 31a — any WKR excess over the vrije ruimte carries an
                // 80% eindheffing; no excess means no eindheffing is owed.
                'nl-wkr-eindheffing-80'              => static fn(array $o): bool => (((float) ($o['wkrExcess'] ?? 0)) <= 0.0)
                    || (self::numeric($o, 'wkrEindheffingRate') && self::ratesEqual((float) $o['wkrEindheffingRate'], 80.0)),
                // BW 7:626 lid 1 — the payslip must state gross wage, deduction basis, the
                // applicable minimum wage, and employer/employee identification.
                'nl-loonstrook-inhoud'               => static fn(array $o): bool => (($o['showsGrossWage'] ?? false) === true)
                    && (($o['showsDeductionBasis'] ?? false) === true)
                    && (($o['showsMinimumWage'] ?? false) === true)
                    && (($o['showsEmployerEmployeeIds'] ?? false) === true),
            ],
            'Employee'           => [
                // Handboek Loonheffingen — a verified ID-document copy is kept until at least
                // 5 years after the year employment ends.
                'nl-id-bewaarplicht-5jaar'           => static fn(array $o): bool => (($o['identityDocumentVerified'] ?? false) === true)
                    && self::present($o, 'identityDocumentRetainedUntil')
                    && self::retainedAtLeastYearsAfterEnd($o, 'identityDocumentRetainedUntil', 5),
                // Wet LB 1964 art. 31a (30%-regeling) — when granted, the applied tax-free
                // percentage must not exceed 30 for 2025-2026.
                'nl-30-percent-regeling'             => static fn(array $o): bool => (($o['thirtyPercentRulingGranted'] ?? false) !== true)
                    || (self::numeric($o, 'thirtyPercentRulingRate') && ((float) $o['thirtyPercentRulingRate']) <= 30.0),
                // Wet LB 1964 art. 31a (aftopping WNT-norm) — a granted 30%-ruling is capped
                // at the WNT-norm.
                'nl-30-regeling-aftoppingsgrens'     => static fn(array $o): bool => (($o['thirtyPercentRulingGranted'] ?? false) !== true)
                    || (($o['thirtyPercentCappedAtWntNorm'] ?? false) === true),
                // Regulation (EC) 883/2004 art. 12 — a posted worker holds a valid A1
                // certificate that runs no longer than 24 months from the posting.
                'eu-a1-posted-worker'                => static fn(array $o): bool => (($o['postedWorker'] ?? false) !== true)
                    || (self::present($o, 'a1CertificateNumber')
                    && self::present($o, 'a1ValidUntil')
                    && self::withinMonths($o, 'startDate', 'a1ValidUntil', 24)),
            ],
            'EmploymentContract' => [
                // Wfsv (Wab) — the Awf low tariff applies only to permanent written contracts;
                // every other contract takes the high tariff. The applied tariff must match.
                'nl-awf-laag-hoog-tarief'            => static fn(array $o): bool => self::present($o, 'awfTariff')
                    && ((string) $o['awfTariff'] === self::expectedAwfTariff($o)),
                // WML art. 8 — from 1 Jan 2026 the statutory minimum hourly wage is EUR 14,71
                // for employees aged 21+; the contract hourly wage must meet it.
                'nl-minimumloon-2026'                => static fn(array $o): bool => self::numeric($o, 'hourlyWage')
                    && ((float) $o['hourlyWage']) >= 14.71,
                // Wet invoering minimumuurloon — a single hourly minimum applies; weekly/monthly
                // minimums derive from it, so contracted hours must be a positive number.
                'nl-minimumuurloon-wet'              => static fn(array $o): bool => self::numeric($o, 'hoursPerWeek')
                    && ((float) $o['hoursPerWeek']) > 0.0
                    && self::numeric($o, 'hourlyWage'),
            ],
            'PayrollRun'         => [
                // Payroll-to-GL control — the GL liability/expense postings reconcile to the
                // run totals: expense = gross + employer charges; liability = gross + charges − net.
                'xc-payroll-gl-reconciliation'       => static fn(array $o): bool => self::numeric($o, 'glExpensePosted')
                    && self::numeric($o, 'glLiabilityPosted')
                    && self::centsEqual((float) $o['glExpensePosted'], (((float) ($o['totalGross'] ?? 0)) + ((float) ($o['totalEmployerCharges'] ?? 0))))
                    && self::centsEqual((float) $o['glLiabilityPosted'], (((float) ($o['totalGross'] ?? 0)) + ((float) ($o['totalEmployerCharges'] ?? 0)) - ((float) ($o['totalNet'] ?? 0)))),
                // Liability-clearing control — each withholding liability is cleared to zero on
                // remittance, so a cleared run carries a zero residual liability balance.
                'xc-withholding-liability-clearing'  => static fn(array $o): bool => (($o['withholdingsClearedToZero'] ?? false) === true)
                    && self::numeric($o, 'withholdingLiabilityBalance')
                    && self::centsEqual((float) $o['withholdingLiabilityBalance'], 0.0),
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
            'Employee'           => [
                [
                    'employeeNumber'                 => 'EMP-NL-0001',
                    'bsn'                            => '123456782',
                    'firstName'                      => 'Sanne',
                    'lastName'                       => 'de Vries',
                    'dateOfBirth'                    => '1990-04-12',
                    'startDate'                      => '2022-01-01',
                    'endDate'                        => null,
                    'grossMonthlySalary'             => 3800.00,
                    'taxTableColor'                  => 'wit',
                    'identityDocumentVerified'       => true,
                    'identityDocumentRetainedUntil'  => '2035-12-31',
                    'loonheffingenVerklaringOnFile'  => true,
                    'postedWorker'                   => false,
                    'a1CertificateNumber'            => null,
                    'a1ValidUntil'                   => null,
                    'thirtyPercentRulingGranted'     => true,
                    'thirtyPercentRulingRate'        => 30.0,
                    'thirtyPercentCappedAtWntNorm'   => true,
                    // Cross-jurisdiction-neutral fields so a DE/FR/US audit of this NL
                    // sample also reports zero violations (the matching country checks).
                    'elstamRetrieved'                => true,
                    'steuerklasse'                   => 'I',
                    'w4OnFile'                       => true,
                    'i9VerifiedWithinThreeDays'      => true,
                    'newHireReportedDate'            => '2022-01-05',
                    // payroll-sepa-netpay-shillinq: placeholder bank details so
                    // `occ hrmq:rules:audit` stays green under nl-netpay-iban-present
                    // for this seeded run/payslip (both payable).
                    'iban'                           => 'NL00BANK0000000001',
                    'tenaamstelling'                 => 'S. de Vries',
                ],
            ],
            'EmploymentContract' => [
                [
                    'employeeId'           => 'EMP-NL-0001',
                    'type'                 => 'permanent',
                    'writtenContract'      => true,
                    'startDate'            => '2022-01-01',
                    'endDate'              => null,
                    'hoursPerWeek'         => 36.0,
                    'hourlyWage'           => 24.36,
                    'cao'                  => 'CAO Metalektro',
                    'awfTariff'            => 'low',
                    // Cross-jurisdiction-neutral fields (DE/FR/US contract checks).
                    'workingTimeDocumented' => true,
                    'overtimeMultiplier'    => 1.5,
                    'ftePartOfYearMinijob'  => false,
                    'dpaeFiledBeforeStart'  => true,
                ],
            ],
            'Payslip'            => [
                [
                    'employeeId'                => 'EMP-NL-0001',
                    'period'                    => '2026-01',
                    'jurisdiction'              => 'NL',
                    'currency'                  => 'EUR',
                    'grossPay'                  => 3800.00,
                    'hoursWorked'               => 156.0,
                    'loonheffing'               => 1102.00,
                    'volksverzekeringen'        => 712.50,
                    'werknemersverzekeringen'   => 418.00,
                    'zvw'                        => 231.80,
                    'zvwMode'                   => 'werkgeversheffing',
                    'zvwRate'                   => 6.10,
                    'anoniementariefApplied'    => false,
                    'appliedTaxRate'            => 29.0,
                    'nettoPay'                  => 2698.00,
                    'vakantiegeldReserved'      => 304.00,
                    'vakantiegeldRate'          => 8.0,
                    'wkrUsed'                   => 0.00,
                    'wkrVrijeRuimteRemaining'   => 1200.00,
                    'wkrExcess'                 => 0.00,
                    'wkrEindheffingRate'        => null,
                    'pensionContribution'       => 190.00,
                    'statementProvided'         => true,
                    'showsGrossWage'            => true,
                    'showsDeductionBasis'       => true,
                    'showsMinimumWage'          => true,
                    'showsEmployerEmployeeIds'  => true,
                    // Cross-jurisdiction-neutral fields so a DE/FR/US audit of this NL
                    // payslip also reports zero violations (the matching country checks).
                    'lohnsteuer'                => 0.00,
                    'solzApplicable'            => false,
                    'solidaritaetszuschlag'     => 0.00,
                    'churchMember'              => false,
                    'kirchensteuer'             => 0.00,
                    'kirchensteuerRate'         => 9.0,
                    'rvEmployeeRate'            => 9.3,
                    'rvEmployerRate'            => 9.3,
                    'kvBaseRate'                => 14.6,
                    'avRate'                    => 2.6,
                    'pvBaseRate'                => 3.6,
                    'svContributionBase'        => 3800.00,
                    'kvPvContributionBase'      => 3800.00,
                    'ficaSsRate'                => 6.2,
                    'ficaSsWageBaseApplied'     => 3800.00,
                    'medicareRate'              => 1.45,
                    'additionalMedicareApplied' => false,
                    'federalIncomeTaxWithheld'  => 0.00,
                    'cotisations'               => 0.00,
                    'pasRate'                   => 0.0,
                    'prelevementSource'         => 0.00,
                    'reductionGeneraleApplied'  => false,
                    'netImposable'              => 3800.00,
                    'netAPayer'                 => 2698.00,
                    'montantNetSocial'          => 3200.00,
                    'conventionCollective'      => 'n/a',
                ],
            ],
            'PayrollRun'         => [
                [
                    'period'                       => '2026-01',
                    'administrationId'             => 'ADM-001',
                    'jurisdiction'                 => 'NL',
                    'status'                       => 'posted',
                    'totalGross'                   => 3800.00,
                    'totalLoonheffing'             => 1102.00,
                    'totalEmployerCharges'         => 649.80,
                    'totalWithholdings'            => 1102.00,
                    'totalNet'                     => 2698.00,
                    'glExpensePosted'              => 4449.80,
                    'glLiabilityPosted'            => 1751.80,
                    'withholdingsClearedToZero'    => true,
                    'withholdingLiabilityBalance'  => 0.00,
                ],
            ],
        ];

    }//end seedObjects()


    /**
     * The Awf tariff a contract should carry: low only for a permanent written
     * contract, high in every other case.
     *
     * @param array<string, mixed> $o The EmploymentContract.
     *
     * @return string 'low' or 'high'.
     */
    private static function expectedAwfTariff(array $o): string
    {
        $permanent = ((string) ($o['type'] ?? '') === 'permanent');
        $written   = (($o['writtenContract'] ?? false) === true);
        return ($permanent === true && $written === true) ? 'low' : 'high';

    }//end expectedAwfTariff()


    /**
     * True when an object field holds a non-empty value.
     *
     * @param array<string, mixed> $o   Object.
     * @param string               $key Field.
     *
     * @return bool
     */
    private static function present(array $o, string $key): bool
    {
        return isset($o[$key]) === true && trim((string) $o[$key]) !== '';

    }//end present()


    /**
     * True when an object field holds a present, numeric value.
     *
     * @param array<string, mixed> $o   Object.
     * @param string               $key Field.
     *
     * @return bool
     */
    private static function numeric(array $o, string $key): bool
    {
        return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;

    }//end numeric()


    /**
     * Compare two amounts at cent precision (avoids float-equality issues).
     *
     * @param float $a Left amount.
     * @param float $b Right amount.
     *
     * @return bool
     */
    private static function centsEqual(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);

    }//end centsEqual()


    /**
     * Compare two rate percentages at basis-point precision.
     *
     * @param float $a Left rate.
     * @param float $b Right rate.
     *
     * @return bool
     */
    private static function ratesEqual(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);

    }//end ratesEqual()


    /**
     * True when a retention date is at least $years after the end of the year in
     * which employment ended (or, while still employed, simply a future-dated and
     * present retention date).
     *
     * @param array<string, mixed> $o     The Employee.
     * @param string               $key   Retention-date field.
     * @param int                  $years Minimum number of years.
     *
     * @return bool
     */
    private static function retainedAtLeastYearsAfterEnd(array $o, string $key, int $years): bool
    {
        $retain = strtotime((string) ($o[$key] ?? ''));
        if ($retain === false) {
            return false;
        }

        $end = trim((string) ($o['endDate'] ?? ''));
        if ($end === '') {
            // Still employed: retention clock has not started; presence is enough.
            return true;
        }

        $endYear  = (int) date('Y', (int) strtotime($end));
        $required = mktime(0, 0, 0, 12, 31, ($endYear + $years));
        return $retain >= $required;

    }//end retainedAtLeastYearsAfterEnd()


    /**
     * True when the date at $endKey is no more than $months after the date at
     * $startKey.
     *
     * @param array<string, mixed> $o        The object.
     * @param string               $startKey Start-date field.
     * @param string               $endKey   End-date field.
     * @param int                  $months   Maximum number of months.
     *
     * @return bool
     */
    private static function withinMonths(array $o, string $startKey, string $endKey, int $months): bool
    {
        $start = strtotime((string) ($o[$startKey] ?? ''));
        $end   = strtotime((string) ($o[$endKey] ?? ''));
        if ($start === false || $end === false || $end < $start) {
            return false;
        }

        $limit = strtotime('+'.$months.' months', $start);
        return $end <= $limit;

    }//end withinMonths()


}//end class
