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
 * The Employee rules live in two sibling providers as decision tables
 * (rules-onto-or-decision-tables): NlEmployeeDocumentChecks (ID-copy and
 * loonheffingenverklaring retention, the A1 certificate) and
 * NlThirtyPercentRulingChecks (the 30%-ruling); the Employee seed stays here
 * with the other payroll samples.
 *
 * The three EmploymentContract rules (Awf tariff, minimum hourly wage,
 * contracted hours) and the withholding-liability clearing on PayrollRun are
 * declared here as decision tables too (tables()); their closures stay as
 * contractChecks() and legacyChecks(), the oracle NlPayrollTableParityTest
 * compares with. The GL reconciliation recomputes the run totals and
 * compares cents, so it stays a closure.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Payroll\AwfHoursReview;
use OCA\Humaniq\Payroll\AwfTariffResolver;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Support\RoundsToCents;

/**
 * Dutch payroll / loonheffingen + global GL-control + EU A1 executable checks.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
final class NlPayrollChecks implements CheckProvider, ProvidesTables, SeedsObjects {
	use BuildsSatisfiedTables;
	use PayrollSampleFields;
	use ReadsRecordedFields;
	use RoundsToCents;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	public static function checks(): array {
		return [
			'Payslip' => [...self::payslipWithholdingChecks(), ...self::payslipStatementChecks()],
			'PayrollRun' => self::payrollRunChecks(),
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * EmploymentContract: the recorded Awf tariff is the one the contract
	 * implies; a numeric hourly wage of at least EUR 14,71; a numeric, positive
	 * weekly hours figure with a numeric hourly wage. PayrollRun: a posted or
	 * paid run has its withholdings cleared, with a numeric zero balance.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function tables(): array {
		return [
			'EmploymentContract' => [
				// Wfsv (Wab), Handboek Loonheffingen 2026 par. 7.2 — the applied Awf tariff matches the contract.
				'nl-awf-laag-hoog-tarief' => [
					'derive' => static fn (array $o): array => ['tariffRecorded' => self::present($o, 'awfTariff'), 'tariffAsExpected' => ((string)($o['awfTariff'] ?? '') === self::expectedAwfTariff($o))],
					'table' => self::satisfiedTable(
						[['name' => 'tariffRecorded', 'type' => 'boolean'], ['name' => 'tariffAsExpected', 'type' => 'boolean']],
						[
							['id' => 'awf-tariff-missing', 'inputEntries' => ['false', '-'], 'outputEntries' => [false]],
							['id' => 'awf-tariff-not-the-contracts', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]],
						]
					),
				],
				// WML art. 8 — from 1 Jan 2026 the minimum hourly wage is EUR 14,71 (21+).
				'nl-minimumloon-2026' => [
					'derive' => static fn (array $o): array => ['wageRecorded' => self::numeric($o, 'hourlyWage'), 'hourlyWage' => (float)($o['hourlyWage'] ?? 0)],
					'table' => self::satisfiedTable(
						[['name' => 'wageRecorded', 'type' => 'boolean'], ['name' => 'hourlyWage', 'type' => 'number']],
						[
							['id' => 'hourly-wage-missing', 'inputEntries' => ['false', '-'], 'outputEntries' => [false]],
							['id' => 'hourly-wage-below-minimum', 'inputEntries' => ['true', '< 14.71'], 'outputEntries' => [false]],
						]
					),
				],
				// Wet invoering minimumuurloon — contracted hours are a positive number, beside an hourly wage.
				'nl-minimumuurloon-wet' => [
					'derive' => static fn (array $o): array => ['hoursRecorded' => self::numeric($o, 'hoursPerWeek'), 'hoursPerWeek' => (float)($o['hoursPerWeek'] ?? 0), 'wageRecorded' => self::numeric($o, 'hourlyWage')],
					'table' => self::satisfiedTable(
						[['name' => 'hoursRecorded', 'type' => 'boolean'], ['name' => 'hoursPerWeek', 'type' => 'number'], ['name' => 'wageRecorded', 'type' => 'boolean']],
						[
							['id' => 'contracted-hours-missing', 'inputEntries' => ['false', '-', '-'], 'outputEntries' => [false]],
							['id' => 'contracted-hours-not-positive', 'inputEntries' => ['true', '<= 0', '-'], 'outputEntries' => [false]],
							['id' => 'hourly-wage-missing', 'inputEntries' => ['-', '-', 'false'], 'outputEntries' => [false]],
						]
					),
				],
			],
			'PayrollRun' => [
				// Liability-clearing control — a posted or paid run carries a zero residual withholding liability.
				'xc-withholding-liability-clearing' => [
					'derive' => static fn (array $o): array => [
						'posted' => self::isGlPosted($o),
						'cleared' => (($o['withholdingsClearedToZero'] ?? false) === true),
						'balanceRecorded' => self::numeric($o, 'withholdingLiabilityBalance'),
						'balanceCents' => (int)round(((float)($o['withholdingLiabilityBalance'] ?? 0)) * 100),
					],
					'table' => self::satisfiedTable(
						[['name' => 'posted', 'type' => 'boolean'], ['name' => 'cleared', 'type' => 'boolean'], ['name' => 'balanceRecorded', 'type' => 'boolean'], ['name' => 'balanceCents', 'type' => 'number']],
						[
							['id' => 'withholdings-not-cleared', 'inputEntries' => ['true', 'false', '-', '-'], 'outputEntries' => [false]],
							['id' => 'liability-balance-missing', 'inputEntries' => ['true', '-', 'false', '-'], 'outputEntries' => [false]],
							['id' => 'liability-balance-not-zero', 'inputEntries' => ['true', '-', '-', '!= 0'], 'outputEntries' => [false]],
						]
					),
				],
			],
		];

	}//end tables()

	/**
	 * Payslip checks on the withheld loonheffing and the insurance premiums.
	 *
	 * @return array<string, callable>
	 */
	private static function payslipWithholdingChecks(): array {
		return [
			// Handboek Loonheffingen 2026 par. 7.2.3 — a low-premium employee on 30
			// contracted hours a week or less, paid more than 30% above the contract
			// this year so far, is heading for the review (filings-premium-differentiation).
			'nl-awf-herziening-uren-signaal' => self::awfHoursWithinLine(...),
			// Wet LB 1964 art. 27 — a loonheffing must be withheld at each payment.
			'nl-loonheffingen-inhouding' => self::loonheffingWithheld(...),
			// Wfsv / art. 27b — premie volksverzekeringen is levied inside the combined
			// loonheffing, so it must be present and not exceed the total loonheffing.
			'nl-loonheffingen-volksverzekeringen' => self::volksverzekeringenWithinLoonheffing(...),
			// Wfsv — employer-borne werknemersverzekeringen (WW/WIA/ZW) over the wage.
			'nl-werknemersverzekeringen-werkgever' => self::werknemersverzekeringenPresent(...),
			// Zvw werkgeversheffing — when the employer-levy mode applies, the 2026 rate is 6,10%.
			'nl-zvw-werkgeversheffing' => self::zvwWerkgeversheffingAtRate(...),
			// Zvw ingehouden bijdrage — when the withheld mode applies, the 2026 rate is 4,85%.
			'nl-zvw-inhouding' => self::zvwInhoudingAtRate(...),
		];

	}//end payslipWithholdingChecks()

	/**
	 * Payslip checks on the applied rates, the reservations and the statement content.
	 *
	 * @return array<string, callable>
	 */
	private static function payslipStatementChecks(): array {
		return [
			// Wet LB 1964 art. 26b — the anoniementarief is exactly 52% when applied, and
			// the effective applied rate must be consistent with that flag.
			'nl-anoniementarief' => self::anoniementariefConsistent(...),
			// WML art. 15 — vakantiebijslag is at least 8% of gross, and the reserved
			// amount reconciles to that rate applied to the gross pay (to the cent).
			'nl-vakantiebijslag-8procent' => self::vakantiebijslagReserved(...),
			// Werkkostenregeling — designated allowances charged to the vrije ruimte may
			// not push the remaining ruimte negative without recording the excess.
			'nl-wkr-vrije-ruimte' => self::wkrVrijeRuimteCovered(...),
			// Wet LB 1964 art. 31a — any WKR excess over the vrije ruimte carries an
			// 80% eindheffing; no excess means no eindheffing is owed.
			'nl-wkr-eindheffing-80' => self::wkrEindheffingAtRate(...),
			// BW 7:626 lid 1 — the payslip must state gross wage, deduction basis, the
			// applicable minimum wage, and employer/employee identification.
			'nl-loonstrook-inhoud' => self::loonstrookComplete(...),
			// Wet LB 1964 art. 31a (aftopping WNT-norm) — the recorded 30%-ruling
			// exemption re-derives cents-exact from the WNT-capped formula
			// (30-procent-regeling): the NUMERIC enforcement the boolean-only
			// nl-30-regeling-aftoppingsgrens never provided.
			'nl-30-regeling-aftoppingsgrens-bedrag' => self::thirtyPercentExemptionMatchesFormula(...),
		];

	}//end payslipStatementChecks()

	/**
	 * EmploymentContract checks: the Awf tariff and the statutory minimum wage.
	 *
	 * @return array<string, callable>
	 */
	private static function contractChecks(): array {
		return [
			// Wfsv (Wab), Handboek Loonheffingen 2026 par. 7.2 — the Awf low tariff applies
			// to permanent written contracts and signed BBL contracts without an
			// uitzendbeding; every other contract takes the high tariff. The applied
			// tariff must match (the under-21 hours exception is per period, on the payslip).
			'nl-awf-laag-hoog-tarief' => self::awfTariffMatches(...),
			// WML art. 8 — from 1 Jan 2026 the statutory minimum hourly wage is EUR 14,71
			// for employees aged 21+; the contract hourly wage must meet it.
			'nl-minimumloon-2026' => self::minimumHourlyWageMet(...),
			// Wet invoering minimumuurloon — a single hourly minimum applies; weekly/monthly
			// minimums derive from it, so contracted hours must be a positive number.
			'nl-minimumuurloon-wet' => self::contractedHoursPositive(...),
		];

	}//end contractChecks()

	/**
	 * PayrollRun checks: the GL reconciliation and the liability clearing.
	 *
	 * @return array<string, callable>
	 */
	private static function payrollRunChecks(): array {
		return [
			// Payroll-to-GL control — the GL liability/expense postings reconcile to the
			// run totals: expense = gross + employer charges; liability = gross + charges − net.
			// Scoped to posted/paid runs (payroll-core-engine): a draft/approved run has no
			// GL postings yet by definition, so checking it would be a false positive —
			// never surfaced before because the only seeded run is `posted`.
			'xc-payroll-gl-reconciliation' => self::glPostingsReconcile(...),
		];

	}//end payrollRunChecks()

	/**
	 * Whether a non-negative loonheffing is withheld.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function loonheffingWithheld(array $o): bool {
		return self::numeric($o, 'loonheffing') && ((float)$o['loonheffing']) >= 0.0;

	}//end loonheffingWithheld()

	/**
	 * Whether the volksverzekeringen premium is present and within the loonheffing.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function volksverzekeringenWithinLoonheffing(array $o): bool {
		return self::numeric($o, 'volksverzekeringen')
			&& self::numeric($o, 'loonheffing')
			&& ((float)$o['volksverzekeringen']) >= 0.0
			&& ((float)$o['volksverzekeringen']) <= ((float)$o['loonheffing']);

	}//end volksverzekeringenWithinLoonheffing()

	/**
	 * Whether a non-negative employer werknemersverzekeringen amount is present.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function werknemersverzekeringenPresent(array $o): bool {
		return self::numeric($o, 'werknemersverzekeringen')
			&& ((float)$o['werknemersverzekeringen']) >= 0.0;

	}//end werknemersverzekeringenPresent()

	/**
	 * Whether the employer-levy Zvw mode, when it applies, uses the 6,10% rate.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function zvwWerkgeversheffingAtRate(array $o): bool {
		return ((string)($o['zvwMode'] ?? '') !== 'werkgeversheffing')
			|| (self::numeric($o, 'zvwRate') && self::ratesEqual((float)$o['zvwRate'], 6.10) && self::numeric($o, 'zvw'));

	}//end zvwWerkgeversheffingAtRate()

	/**
	 * Whether the withheld Zvw mode, when it applies, uses the 4,85% rate.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function zvwInhoudingAtRate(array $o): bool {
		return ((string)($o['zvwMode'] ?? '') !== 'inhouding')
			|| (self::numeric($o, 'zvwRate') && self::ratesEqual((float)$o['zvwRate'], 4.85) && self::numeric($o, 'zvw'));

	}//end zvwInhoudingAtRate()

	/**
	 * Whether the applied tax rate agrees with the anoniementarief flag.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function anoniementariefConsistent(array $o): bool {
		return self::numeric($o, 'appliedTaxRate')
			&& ((($o['anoniementariefApplied'] ?? false) === true)
			? self::ratesEqual((float)$o['appliedTaxRate'], 52.0)
			: ((float)$o['appliedTaxRate']) < 52.0);

	}//end anoniementariefConsistent()

	/**
	 * Whether the vakantiebijslag rate is at least 8% and the reserve matches it.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function vakantiebijslagReserved(array $o): bool {
		return self::numeric($o, 'vakantiegeldRate')
			&& ((float)$o['vakantiegeldRate']) >= 8.0
			&& self::centsEqual((float)($o['vakantiegeldReserved'] ?? 0), (((float)($o['grossPay'] ?? 0)) * ((float)$o['vakantiegeldRate']) / 100));

	}//end vakantiebijslagReserved()

	/**
	 * Whether the remaining vrije ruimte is non-negative or the excess is recorded.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function wkrVrijeRuimteCovered(array $o): bool {
		return self::numeric($o, 'wkrVrijeRuimteRemaining')
			&& (((float)$o['wkrVrijeRuimteRemaining']) >= 0.0 || self::numeric($o, 'wkrExcess'));

	}//end wkrVrijeRuimteCovered()

	/**
	 * Whether a WKR excess carries the 80% eindheffing.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function wkrEindheffingAtRate(array $o): bool {
		return (((float)($o['wkrExcess'] ?? 0)) <= 0.0)
			|| (self::numeric($o, 'wkrEindheffingRate') && self::ratesEqual((float)$o['wkrEindheffingRate'], 80.0));

	}//end wkrEindheffingAtRate()

	/**
	 * Whether the payslip states every mandatory item.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 */
	private static function loonstrookComplete(array $o): bool {
		return (($o['showsGrossWage'] ?? false) === true)
			&& (($o['showsDeductionBasis'] ?? false) === true)
			&& (($o['showsMinimumWage'] ?? false) === true)
			&& (($o['showsEmployerEmployeeIds'] ?? false) === true);

	}//end loonstrookComplete()

	/**
	 * Whether the contract carries the Awf tariff its terms call for.
	 *
	 * @param array<string, mixed> $o The EmploymentContract.
	 *
	 * @return bool
	 */
	private static function awfTariffMatches(array $o): bool {
		return self::present($o, 'awfTariff')
			&& ((string)$o['awfTariff'] === self::expectedAwfTariff($o));

	}//end awfTariffMatches()

	/**
	 * Whether the contract hourly wage meets the 2026 statutory minimum.
	 *
	 * @param array<string, mixed> $o The EmploymentContract.
	 *
	 * @return bool
	 */
	private static function minimumHourlyWageMet(array $o): bool {
		return self::numeric($o, 'hourlyWage')
			&& ((float)$o['hourlyWage']) >= 14.71;

	}//end minimumHourlyWageMet()

	/**
	 * Whether the contract has positive contracted hours and an hourly wage.
	 *
	 * @param array<string, mixed> $o The EmploymentContract.
	 *
	 * @return bool
	 */
	private static function contractedHoursPositive(array $o): bool {
		return self::numeric($o, 'hoursPerWeek')
			&& ((float)$o['hoursPerWeek']) > 0.0
			&& self::numeric($o, 'hourlyWage');

	}//end contractedHoursPositive()

	/**
	 * Whether a posted run's GL postings reconcile to its totals.
	 *
	 * @param array<string, mixed> $o The PayrollRun.
	 *
	 * @return bool
	 */
	private static function glPostingsReconcile(array $o): bool {
		return self::isGlPosted($o) === false
			|| (self::numeric($o, 'glExpensePosted')
			&& self::numeric($o, 'glLiabilityPosted')
			&& self::centsEqual((float)$o['glExpensePosted'], (((float)($o['totalGross'] ?? 0)) + ((float)($o['totalEmployerCharges'] ?? 0))))
			&& self::centsEqual((float)$o['glLiabilityPosted'], (((float)($o['totalGross'] ?? 0)) + ((float)($o['totalEmployerCharges'] ?? 0)) - ((float)($o['totalNet'] ?? 0)))));

	}//end glPostingsReconcile()

	/**
	 * Whether a posted run's withholding liability is cleared to zero.
	 *
	 * @param array<string, mixed> $o The PayrollRun.
	 *
	 * @return bool
	 */
	private static function withholdingLiabilityCleared(array $o): bool {
		return self::isGlPosted($o) === false
			|| ((($o['withholdingsClearedToZero'] ?? false) === true)
			&& self::numeric($o, 'withholdingLiabilityBalance')
			&& self::centsEqual((float)$o['withholdingLiabilityBalance'], 0.0));

	}//end withholdingLiabilityCleared()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec exclude Seed samples the standards self-test runs its checks against; no behavioural requirement.
	 */
	public static function seedObjects(): array {
		return [
			'Employee'           => [array_merge(self::seedEmployee(), self::seedNeutralEmployee())],
			'EmploymentContract' => [self::seedContract()],
			'Payslip'            => [array_merge(self::seedPayslip(), self::seedNeutralPayslip())],
			'PayrollRun'         => [self::seedPayrollRun()],
		];
	}//end seedObjects()

	/**
	 * The seeded Dutch employee: identity, salary, tax table and 30%-ruling fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedEmployee(): array {
		return [
			'employeeNumber' => 'EMP-NL-0001',
			'bsn' => '123456782',
			'firstName' => 'Sanne',
			'lastName' => 'de Vries',
			'dateOfBirth' => '1990-04-12',
			'startDate' => '2022-01-01',
			'endDate' => null,
			'grossMonthlySalary' => 3800.00,
			'taxTableColor' => 'wit',
			'identityDocumentVerified' => true,
			'identityDocumentRetainedUntil' => '2035-12-31',
			'loonheffingenVerklaringOnFile' => true,
			'postedWorker' => false,
			'a1CertificateNumber' => null,
			'a1ValidUntil' => null,
			'thirtyPercentRulingGranted' => true,
			'thirtyPercentRulingRate' => 30.0,
			'thirtyPercentCappedAtWntNorm' => true,
			// 30-procent-regeling: the seeded ruling qualifies for the reduced
			// (under-30 master's) salary norm, so its €45.600 annualised salary
			// clears €36.497 -- nl-30-regeling-salarisnorm passes. (startDate/
			// endDate are left absent: nl-30-regeling-looptijd-5jaar treats an
			// incomplete-but-not-contradictory ruling as vacuous, D5.)
			'thirtyPercentRulingReducedNormApplies' => true,
		];
	}//end seedEmployee()

	/**
	 * Employee fields the DE/FR/US checks read, plus placeholder bank details, so a cross-jurisdiction audit of the NL sample also passes.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedNeutralEmployee(): array {
		return [
			// Cross-jurisdiction-neutral fields so a DE/FR/US audit of this NL
			// sample also reports zero violations (the matching country checks).
			'elstamRetrieved' => true,
			'steuerklasse' => 'I',
			'w4OnFile' => true,
			'i9VerifiedWithinThreeDays' => true,
			'newHireReportedDate' => '2022-01-05',
			// payroll-sepa-netpay-shillinq: placeholder bank details so
			// `occ humaniq:rules:audit` stays green under nl-netpay-iban-present
			// for this seeded run/payslip (both payable).
			'iban' => 'NL00BANK0000000001',
			'tenaamstelling' => 'S. de Vries',
		];
	}//end seedNeutralEmployee()

	/**
	 * The seeded employee's permanent contract.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedContract(): array {
		return [
			'employeeId' => 'EMP-NL-0001',
			'type' => 'permanent',
			'writtenContract' => true,
			'startDate' => '2022-01-01',
			'endDate' => null,
			'hoursPerWeek' => 36.0,
			'hourlyWage' => 24.36,
			'cao' => 'CAO Metalektro',
			'awfTariff' => 'low',
			// Cross-jurisdiction-neutral fields (DE/FR/US contract checks).
			'workingTimeDocumented' => true,
			'overtimeMultiplier' => 1.5,
			'ftePartOfYearMinijob' => false,
			'dpaeFiledBeforeStart' => true,
		];
	}//end seedContract()

	/**
	 * The seeded January payslip: the Dutch wage, tax and statement fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedPayslip(): array {
		return [
			'employeeId' => 'EMP-NL-0001',
			'period' => '2026-01',
			'jurisdiction' => 'NL',
			'currency' => 'EUR',
			'grossPay' => 3800.00,
			'hoursWorked' => 156.0,
			'loonheffing' => 1102.00,
			'volksverzekeringen' => 712.50,
			'werknemersverzekeringen' => 418.00,
			'zvw' => 231.80,
			'zvwMode' => 'werkgeversheffing',
			'zvwRate' => 6.10,
			'anoniementariefApplied' => false,
			'appliedTaxRate' => 29.0,
			'nettoPay' => 2698.00,
			'vakantiegeldReserved' => 304.00,
			'vakantiegeldRate' => 8.0,
			'wkrUsed' => 0.00,
			'wkrVrijeRuimteRemaining' => 1200.00,
			'wkrExcess' => 0.00,
			'wkrEindheffingRate' => null,
			'pensionContribution' => 190.00,
			...self::statementFlags(),
		];
	}//end seedPayslip()

	/**
	 * Payslip fields the DE/FR/US checks read, so a cross-jurisdiction audit of the NL payslip also passes.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedNeutralPayslip(): array {
		return [
			// Cross-jurisdiction-neutral fields so a DE/FR/US audit of this NL
			// payslip also reports zero violations (the matching country checks).
			...self::germanTaxAbsent(),
			...self::germanSocialRates(3800.00),
			...self::usWithholding(3800.00, 0.00),
			...self::frenchContributionsAbsent(),
			'netImposable' => 3800.00,
			'netAPayer' => 2698.00,
			'montantNetSocial' => 3200.00,
			'conventionCollective' => 'n/a',
		];
	}//end seedNeutralPayslip()

	/**
	 * The seeded posted payroll run for January.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedPayrollRun(): array {
		return [
			'period' => '2026-01',
			'administrationId' => 'ADM-001',
			'jurisdiction' => 'NL',
			'status' => 'posted',
			'totalGross' => 3800.00,
			'totalLoonheffing' => 1102.00,
			'totalEmployerCharges' => 649.80,
			'totalWithholdings' => 1102.00,
			'totalNet' => 2698.00,
			'glExpensePosted' => 4449.80,
			'glLiabilityPosted' => 1751.80,
			'withholdingsClearedToZero' => true,
			'withholdingLiabilityBalance' => 0.00,
		];
	}//end seedPayrollRun()

	/**
	 * Whether a PayrollRun has reached the GL-posting stage (posted/paid) —
	 * the applicability scope of the xc-payroll-gl-reconciliation and
	 * xc-withholding-liability-clearing controls (payroll-core-engine: draft
	 * engine runs have no GL postings yet by definition).
	 *
	 * @param array<string, mixed> $o The PayrollRun.
	 *
	 * @return bool
	 */
	private static function isGlPosted(array $o): bool {
		return in_array((string)($o['status'] ?? ''), ['posted', 'paid'], true);
	}//end isGlPosted()

	/**
	 * The Awf tariff a contract should carry: the shared resolution
	 * (filings-premium-differentiation D1), low for a permanent written
	 * contract and a signed BBL contract, high otherwise.
	 *
	 * @param array<string, mixed> $o The EmploymentContract.
	 *
	 * @return string 'low' or 'high'.
	 *
	 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The pure Awf helpers are static by design.
	 */
	private static function expectedAwfTariff(array $o): string {
		return AwfTariffResolver::contractTariff($o);

	}//end expectedAwfTariff()

	/**
	 * Whether a payslip stays inside the extra-hours line: anything but a
	 * reviewable low payslip with year-to-date figures passes.
	 *
	 * @param array<string, mixed> $o The Payslip.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The pure Awf helpers are static by design.
	 */
	private static function awfHoursWithinLine(array $o): bool {
		if ((string)($o['awfTariff'] ?? '') !== 'low'
			|| in_array((string)($o['awfTariffBasis'] ?? ''), AwfTariffResolver::REVIEWABLE_BASES, true) === false
			|| self::numeric($o, 'awfContractHoursYearToDate') === false
		) {
			return true;
		}

		return AwfHoursReview::exceeds(
			[
				'contract' => (float)$o['awfContractHoursYearToDate'],
				'averageHoursPerWeek' => (int)($o['awfAverageContractHoursPerWeek'] ?? 0),
				'overrunPercent' => (int)($o['awfOverrunPercentYearToDate'] ?? 0),
			]
		) === false;

	}//end awfHoursWithinLine()

	/**
	 * Compare two amounts at cent precision (avoids float-equality issues).
	 *
	 * @param float $a Left amount.
	 * @param float $b Right amount.
	 *
	 * @return bool
	 */
	private static function centsEqual(float $a, float $b): bool {
		return (int)round($a * 100) === (int)round($b * 100);
	}//end centsEqual()

	/**
	 * Compare two rate percentages at basis-point precision.
	 *
	 * @param float $a Left rate.
	 * @param float $b Right rate.
	 *
	 * @return bool
	 */
	private static function ratesEqual(float $a, float $b): bool {
		return (int)round($a * 100) === (int)round($b * 100);
	}//end ratesEqual()

	/**
	 * The `nl-30-regeling-aftoppingsgrens-bedrag` predicate (30-procent-regeling
	 * REQ-30P-004): vacuous when `thirtyPercentRulingExemption` is null. Else
	 * resolves the referenced Employee via `$context['payroll']
	 * ['employeesById']` (the `fleet.carAssignmentsById` precedent), re-derives
	 * `min(grossPay, dertigProcentRegeling.aftoppingsgrens.maand) ×
	 * employee.thirtyPercentRulingRate / 100` from the SAME versioned table the
	 * engine reads (via `TaxTables`, never a duplicated literal), and flags a
	 * cents-mismatch against the recorded amount. Vacuous on a dangling employee
	 * reference or a non-resolvable tax-year table (the `NlFleetChecks`
	 * vacuous-on-dangling posture).
	 *
	 * @param array<string, mixed> $o The Payslip object.
	 * @param array<string, mixed> $context Evaluation context; reads `payroll.employeesById`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-004
	 */
	private static function thirtyPercentExemptionMatchesFormula(array $o, array $context): bool {
		if (($o['thirtyPercentRulingExemption'] ?? null) === null) {
			// No 30%-ruling exemption recorded on this payslip — out of scope.
			return true;
		}

		$employeeId = trim((string)($o['employeeId'] ?? ''));
		$employee = ($context['payroll']['employeesById'][$employeeId] ?? null);
		if (is_array($employee) === false) {
			// Dangling employee reference — a different data-integrity problem,
			// not this rule's job (NlFleetChecks vacuous-on-dangling posture).
			return true;
		}

		$rate = ($employee['thirtyPercentRulingRate'] ?? null);
		if (is_numeric($rate) === false) {
			return true;
		}

		$tables = self::tablesFor((string)($o['period'] ?? ''));
		if ($tables === null) {
			// No resolvable tax-year table — nl-engine-table-version's concern,
			// not this arithmetic-consistency rule's. Vacuous.
			return true;
		}

		$capCents = $tables->dertigProcentRegeling()['aftoppingsgrensMaandCents'];
		$grossCents = self::cents($o['grossPay'] ?? null);
		$expectedCents = (int)round((min($grossCents, $capCents) * ((float)$rate)) / 100);
		$recordedCents = self::cents($o['thirtyPercentRulingExemption'] ?? null);

		return $expectedCents === $recordedCents;
	}//end thirtyPercentExemptionMatchesFormula()

	/**
	 * Load the versioned tax-year table for a Payslip's `period` (`YYYY-MM` ->
	 * `nl-{YYYY}`, the `NlFleetChecks::tablesFor()` precedent), or null when
	 * unparseable/unavailable. Never throws.
	 *
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return TaxTables|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TaxTables::load() is a pure value-object factory method — the same unguarded precedent NlFleetChecks::tablesFor() and PayrollRunService already use.
	 */
	private static function tablesFor(string $period): ?TaxTables {
		if (preg_match('/^(\d{4})/', trim($period), $matches) !== 1) {
			return null;
		}

		try {
			return TaxTables::load('nl-' . $matches[1]);
		} catch (\Throwable $e) {
			return null;
		}

	}//end tablesFor()

	/**
	 * The contract closures and the liability-clearing closure as registered
	 * before tables(); NlPayrollTableParityTest compares the tables with them.
	 * The engine does not register them.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 */
	public static function legacyChecks(): array {
		return [
			'EmploymentContract' => self::contractChecks(),
			'PayrollRun' => ['xc-withholding-liability-clearing' => self::withholdingLiabilityCleared(...)],
		];

	}//end legacyChecks()
}//end class
