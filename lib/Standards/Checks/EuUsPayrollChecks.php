<?php

/**
 * EU/US Payroll Check Provider
 *
 * Executable checks for the German (Lohnsteuer + Sozialversicherung), French
 * (DSN + URSSAF) and United-States (FICA/FUTA/income-tax + deposits + year-end
 * reporting) sub-domains of the payroll corpus (lib/Standards/rules/payroll.json).
 * Each rule is jurisdiction-gated by the RuleEngine, so these predicates only
 * fire when the audit/guard runs with the matching country context; they are
 * dormant (never evaluated) under the default NL context.
 *
 * The checks map onto the Payslip / Employee / EmploymentContract / PayrollRun /
 * LoonaangifteFiling object types and decide compliance from the structured
 * fields declared in lib/Settings/register.d/hr-objects.json. The seedObjects()
 * samples are fully cross-compliant: each one satisfies the NL + EU + global
 * rules that apply under the default NL audit AND the DE/FR/US rules that apply
 * under their own jurisdiction context, so every audit jurisdiction reports zero
 * violations.
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
 * German + French + US payroll, social-security, filing and reporting checks.
 */
final class EuUsPayrollChecks implements CheckProvider, SeedsObjects {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'Payslip' => self::payslipChecks(),
			'Employee' => self::employeeChecks(),
			'EmploymentContract' => self::contractChecks(),
			'LoonaangifteFiling' => self::filingChecks(),
			'PayrollRun' => [],
		];

	}//end checks()

	/**
	 * DE/FR/US Payslip-level checks (withholding presence + statutory rates +
	 * mandatory bulletin/payslip content).
	 *
	 * @return array<string, callable>
	 */
	private static function payslipChecks(): array {
		return [
			// EStG §38 — Lohnsteuer is withheld at each payment.
			'de-lohnsteuer-einbehalt' => static fn (array $o): bool => self::numeric($o, 'lohnsteuer') && ((float)$o['lohnsteuer']) >= 0.0,
			// SolzG §3-4 — Soli is 5,5% of the Lohnsteuer where the Freigrenze is exceeded.
			'de-solidaritaetszuschlag' => static fn (array $o): bool => (($o['solzApplicable'] ?? false) !== true)
				|| self::centsEqual((float)($o['solidaritaetszuschlag'] ?? 0), (((float)($o['lohnsteuer'] ?? 0)) * 0.055)),
			// KiStG / EStG §51a — Kirchensteuer is 8% or 9% of the Lohnsteuer for members.
			'de-kirchensteuer' => static fn (array $o): bool => (($o['churchMember'] ?? false) !== true)
				|| (self::numeric($o, 'kirchensteuerRate')
				&& in_array((int)round((float)$o['kirchensteuerRate']), [8, 9], true)
				&& self::centsEqual((float)($o['kirchensteuer'] ?? 0), (((float)($o['lohnsteuer'] ?? 0)) * ((float)$o['kirchensteuerRate']) / 100))),
			// SGB VI §158 — RV 2026 rate 18,6% shared 9,3% / 9,3%.
			'de-rv-beitragssatz-2026' => static fn (array $o): bool => self::rate($o, 'rvEmployeeRate', 9.3) && self::rate($o, 'rvEmployerRate', 9.3),
			// SGB V §241 — KV 2026 general base rate 14,6%.
			'de-kv-beitragssatz-2026' => static fn (array $o): bool => self::rate($o, 'kvBaseRate', 14.6),
			// SGB III §341 — AV 2026 rate 2,6%.
			'de-av-beitragssatz-2026' => static fn (array $o): bool => self::rate($o, 'avRate', 2.6),
			// SGB XI §55 — PV 2026 base rate 3,6%.
			'de-pv-beitragssatz-2026' => static fn (array $o): bool => self::rate($o, 'pvBaseRate', 3.6),
			// SVRechGrößen 2026 — RV/AV BBG EUR 8.450/month: contribution base is capped.
			'de-bbg-rv-av-2026' => static fn (array $o): bool => self::numeric($o, 'svContributionBase') && ((float)$o['svContributionBase']) <= 8450.0,
			// SVRechGrößen 2026 — KV/PV BBG EUR 5.812,50/month: KV/PV base is capped.
			'de-bbg-kv-pv-2026' => static fn (array $o): bool => self::numeric($o, 'kvPvContributionBase') && ((float)$o['kvPvContributionBase']) <= 5812.50,
			// IRC §3101(a) — employee Social Security (OASDI) rate 6,2%.
			'us-fica-social-security' => static fn (array $o): bool => self::rate($o, 'ficaSsRate', 6.2),
			// SSA 2026 wage base USD 184.500 — OASDI taxable wage is capped.
			'us-social-security-wage-base-2026' => static fn (array $o): bool => self::numeric($o, 'ficaSsWageBaseApplied') && ((float)$o['ficaSsWageBaseApplied']) <= 184500.0,
			// IRC §3101(b)(1) — employee Medicare rate 1,45%.
			'us-fica-medicare' => static fn (array $o): bool => self::rate($o, 'medicareRate', 1.45),
			// IRC §3101(b)(2) — additional 0,9% Medicare is a boolean applicability flag.
			'us-additional-medicare-tax' => static fn (array $o): bool => array_key_exists('additionalMedicareApplied', $o) && is_bool($o['additionalMedicareApplied']),
			// IRC §3402 — federal income tax is withheld (a non-negative amount present).
			'us-federal-income-tax-withholding' => static fn (array $o): bool => self::numeric($o, 'federalIncomeTaxWithheld') && ((float)$o['federalIncomeTaxWithheld']) >= 0.0,
			// CSS art. L241-1 — URSSAF cotisations on the gross assiette.
			'fr-urssaf-cotisations' => static fn (array $o): bool => self::numeric($o, 'cotisations') && ((float)$o['cotisations']) >= 0.0,
			// CGI art. 204A — PAS applied at the administration-transmitted rate.
			'fr-prelevement-a-la-source' => static fn (array $o): bool => self::numeric($o, 'pasRate')
				&& self::centsEqual((float)($o['prelevementSource'] ?? 0), (((float)($o['netImposable'] ?? 0)) * ((float)$o['pasRate']) / 100)),
			// CSS art. L241-13 — réduction générale is a boolean applicability flag.
			'fr-reduction-generale' => static fn (array $o): bool => array_key_exists('reductionGeneraleApplied', $o) && is_bool($o['reductionGeneraleApplied']),
			// Code du travail art. R3243-1 — mandatory bulletin mentions present.
			'fr-bulletin-de-paie-mentions' => static fn (array $o): bool => (($o['showsGrossWage'] ?? false) === true)
				&& self::numeric($o, 'cotisations')
				&& self::numeric($o, 'netImposable')
				&& self::numeric($o, 'netAPayer')
				&& self::numeric($o, 'montantNetSocial')
				&& self::present($o, 'conventionCollective'),
			// Arrêté 31/01/2023 — the montant net social is displayed.
			'fr-montant-net-social' => static fn (array $o): bool => self::numeric($o, 'montantNetSocial'),
		];

	}//end payslipChecks()

	/**
	 * DE/US Employee-level checks (ELStAM, W-4, I-9, new-hire reporting).
	 *
	 * @return array<string, callable>
	 */
	private static function employeeChecks(): array {
		return [
			// EStG §39e — ELStAM retrieved and a Steuerklasse applied.
			'de-elstam-abruf' => static fn (array $o): bool => (($o['elstamRetrieved'] ?? false) === true) && self::present($o, 'steuerklasse'),
			// IRS Form W-4 — a W-4 is on file (or the default highest withholding applies).
			'us-w4-on-file' => static fn (array $o): bool => (($o['w4OnFile'] ?? false) === true),
			// IRCA / Form I-9 — work eligibility verified within 3 business days of start.
			'us-i9-verification' => static fn (array $o): bool => (($o['i9VerifiedWithinThreeDays'] ?? false) === true),
			// PRWORA — the new hire is reported to the state Directory within 20 days of hire.
			'us-new-hire-reporting' => static fn (array $o): bool => self::present($o, 'newHireReportedDate')
				&& self::withinDaysOf($o, 'startDate', 'newHireReportedDate', 20),
		];

	}//end employeeChecks()

	/**
	 * DE/FR/US EmploymentContract-level checks (minimum wage, working-time
	 * documentation, overtime, DPAE, Minijob flat-rate).
	 *
	 * @return array<string, callable>
	 */
	private static function contractChecks(): array {
		return [
			// MiLoG §1 — DE minimum wage 2026 EUR 13,90/hour shall not be undercut.
			'de-mindestlohn-2026' => static fn (array $o): bool => self::numeric($o, 'hourlyWage') && ((float)$o['hourlyWage']) >= 13.90,
			// MiLoG §17 — daily working time documented for §17 sectors/Minijobs.
			'de-mindestlohn-arbeitszeit-doku' => static fn (array $o): bool => (in_array((string)($o['type'] ?? ''), ['minijob', 'agency'], true) === false)
				|| (($o['workingTimeDocumented'] ?? false) === true),
			// SGB IV §8 — a Minijob uses flat-rate contributions; the flag must be consistent.
			'de-minijob-pauschale' => static fn (array $o): bool => ((string)($o['type'] ?? '') !== 'minijob')
				|| (($o['ftePartOfYearMinijob'] ?? false) === true),
			// Code du travail art. L3231-2 — FR SMIC 2026 ~EUR 11,88/hour minimum.
			'fr-smic-2026' => static fn (array $o): bool => self::numeric($o, 'hourlyWage') && ((float)$o['hourlyWage']) >= 11.88,
			// FLSA §206 — US federal minimum wage USD 7,25/hour (or higher state minimum).
			'us-flsa-minimum-wage' => static fn (array $o): bool => self::numeric($o, 'hourlyWage') && ((float)$o['hourlyWage']) >= 7.25,
			// FLSA §207 — non-exempt overtime at ≥1,5× the regular rate.
			'us-flsa-overtime' => static fn (array $o): bool => self::numeric($o, 'overtimeMultiplier') && ((float)$o['overtimeMultiplier']) >= 1.5,
			// Code du travail art. L1221-10 — DPAE filed in the 8 days before the hire.
			'fr-dpae' => static fn (array $o): bool => (($o['dpaeFiledBeforeStart'] ?? false) === true),
		];

	}//end contractChecks()

	/**
	 * DE/FR/US LoonaangifteFiling-level checks (filing windows, deposit schedules,
	 * year-end statements, retention).
	 *
	 * @return array<string, callable>
	 */
	private static function filingChecks(): array {
		return [
			// EStG §41a Abs. 1 — Lohnsteuer-Anmeldung filed electronically by the deadline.
			'de-lohnsteuer-anmeldung' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'lohnsteuer-anmeldung')
				|| ((($o['electronicallyFiled'] ?? false) === true) && self::onOrBefore($o, 'submittedDate', 'deadline')),
			// EStG §41a Abs. 2 — Anmeldungszeitraum derived from prior-year liability thresholds.
			'de-lohnsteuer-anmeldungszeitraum' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'lohnsteuer-anmeldung')
				|| ((string)($o['tijdvak'] ?? '') === self::expectedDeAnmeldungszeitraum($o)),
			// LStDV §4 Abs. 2 Nr. 8 / AO §147 — Lohnkonto retained until end of the 6th year.
			'de-lohnkonto-aufbewahrung' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'lohnsteuer-anmeldung')
				|| self::retainedYearsAfterPeriod($o, 6),
			// EStG §41b — Lohnsteuerbescheinigung transmitted after year-end / on termination.
			'de-lohnsteuerbescheinigung' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'lohnsteuerbescheinigung')
				|| self::present($o, 'furnishedToRecipientDate'),
			// SGB IV §28a — DEÜV-Meldung transmitted electronically.
			'de-sozialversicherung-meldepflicht' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'deuv-meldung')
				|| (($o['electronicallyFiled'] ?? false) === true),
			// SGB IV §28e — full Gesamtsozialversicherungsbeitrag paid (a paid date present).
			'de-sv-gesamtbeitrag-abfuehren' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'beitragsnachweis')
				|| self::present($o, 'paidDate'),
			// SGB IV §23 Abs. 1 — contributions paid on or before the due date.
			'de-sv-faelligkeit' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'beitragsnachweis')
				|| self::onOrBefore($o, 'paidDate', 'deadline'),
			// SGB IV §28f Abs. 3 — Beitragsnachweis transmitted by the deadline.
			'de-beitragsnachweis' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'beitragsnachweis')
				|| ((($o['electronicallyFiled'] ?? false) === true) && self::onOrBefore($o, 'submittedDate', 'deadline')),
			// CSS art. L133-5-3 — monthly DSN transmitted electronically.
			'fr-dsn-mensuelle' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'dsn')
				|| ((($o['electronicallyFiled'] ?? false) === true) && ((string)($o['tijdvak'] ?? '') === 'month')),
			// CSS art. R243-6 — DSN + payment by the deadline.
			'fr-dsn-echeance' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'dsn')
				|| self::onOrBefore($o, 'submittedDate', 'deadline'),
			// DSN signalements — event signals within 5 working days.
			'fr-dsn-evenementielle' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'dsn')
				|| (($o['eventSignalDays'] ?? null) === null)
				|| (is_numeric($o['eventSignalDays']) && ((float)$o['eventSignalDays']) <= 5.0),
			// Code du travail art. L3243-4 — bulletin duplicate retained 5 years.
			'fr-bulletin-conservation' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'dsn')
				|| self::retainedYearsAfterPeriod($o, 5),
			// IRS Form 941 — quarterly return filed by the deadline for a quarterly tijdvak.
			'us-form-941-quarterly' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-941')
				|| (((string)($o['tijdvak'] ?? '') === 'quarter') && self::onOrBefore($o, 'submittedDate', 'deadline')),
			// IRS Pub. 15 — deposit schedule is monthly ≤USD 50.000 lookback, else semiweekly.
			'us-deposit-schedule-lookback' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'deposit')
				|| ((string)($o['depositSchedule'] ?? '') === self::expectedUsDepositSchedule($o)),
			// Treas. Reg. §31.6302-1 (monthly) — monthly depositor deposits by the deadline.
			'us-deposit-monthly' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'deposit')
				|| ((string)($o['depositSchedule'] ?? '') !== 'monthly')
				|| self::onOrBefore($o, 'paidDate', 'deadline'),
			// Treas. Reg. §31.6302-1 (semiweekly) — semiweekly depositor uses the semiweekly tijdvak.
			'us-deposit-semiweekly' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'deposit')
				|| ((string)($o['depositSchedule'] ?? '') !== 'semiweekly')
				|| ((string)($o['tijdvak'] ?? '') === 'semiweekly'),
			// Treas. Reg. §31.6302-1 (one-day) — ≥USD 100.000 liability deposited next day.
			'us-deposit-100k-next-day' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'deposit')
				|| (((float)($o['accumulatedLiability'] ?? 0)) < 100000.0)
				|| (($o['nextDayDepositMade'] ?? false) === true),
			// Treas. Reg. §31.6302-1(h) — federal deposits made via EFTPS (electronic).
			'us-eftps-deposit' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'deposit')
				|| (($o['electronicallyFiled'] ?? false) === true),
			// IRC §3301 / Form 940 — FUTA reported annually (a yearly filing by the deadline).
			'us-futa-form-940' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-940')
				|| (((string)($o['tijdvak'] ?? '') === 'year') && self::onOrBefore($o, 'submittedDate', 'deadline')),
			// IRC §3302 — FUTA state-credit is a numeric accumulated-liability driven filing.
			'us-futa-state-credit' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-940')
				|| self::numeric($o, 'accumulatedLiability'),
			// Form 940 instructions — FUTA deposited quarterly once liability exceeds USD 500.
			'us-futa-deposit-500' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-940')
				|| (((float)($o['accumulatedLiability'] ?? 0)) <= 500.0)
				|| self::present($o, 'paidDate'),
			// IRC §6051 / W-2 — furnished to the employee by the deadline (Jan 31).
			'us-form-w2-to-employee' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-w2')
				|| (self::present($o, 'furnishedToRecipientDate') && self::onOrBefore($o, 'furnishedToRecipientDate', 'deadline')),
			// IRC §6051 / W-3 — Copy A + W-3 filed with the SSA by the deadline.
			'us-form-w2-w3-to-ssa' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-w2')
				|| (self::present($o, 'filedWithAuthorityDate') && self::onOrBefore($o, 'filedWithAuthorityDate', 'deadline')),
			// Treas. Reg. §301.6011-2 — ≥10 returns must be e-filed.
			'us-w2-efile-threshold' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-w2')
				|| (((float)($o['returnCountInYear'] ?? 0)) < 10.0)
				|| (($o['electronicallyFiled'] ?? false) === true),
			// Treas. Reg. §31.6001-1 — employment-tax records retained at least 4 years.
			'us-payroll-record-retention-irc' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-941')
				|| self::retainedYearsAfterPeriod($o, 4),
			// 29 CFR §516.5 (FLSA) — payroll records retained at least 3 years.
			'us-flsa-record-retention' => static fn (array $o): bool => (($o['filingType'] ?? '') !== 'form-941')
				|| self::retainedYearsAfterPeriod($o, 3),
		];

	}//end filingChecks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * {@inheritDoc}
	 *
	 * Cross-compliant samples: each carries both the NL+EU+global compliant fields
	 * (so the default NL audit reports zero violations on them) and the DE/FR/US
	 * fields (so a `--jurisdiction=DE|FR|US` audit also reports zero violations).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'Employee' => [
				self::deEmployee(),
				self::frEmployee(),
				self::usEmployee(),
			],
			'EmploymentContract' => [
				self::deContract(),
				self::frContract(),
				self::usContract(),
			],
			'Payslip' => [
				self::dePayslip(),
				self::frPayslip(),
				self::usPayslip(),
			],
			'LoonaangifteFiling' => [
				self::deAnmeldung(),
				self::deBeitragsnachweis(),
				self::deDeuv(),
				self::deBescheinigung(),
				self::frDsn(),
				self::usForm941(),
				self::usDeposit(),
				self::usForm940(),
				self::usFormW2(),
			],
		];

	}//end seedObjects()

	/**
	 * Base Employee fields satisfying the NL + EU rules that apply under the
	 * default NL audit (so cross-jurisdiction samples never trip an NL check).
	 *
	 * @return array<string, mixed>
	 */
	private static function nlSafeEmployee(): array {
		return [
			'identityDocumentVerified' => true,
			'identityDocumentRetainedUntil' => '2035-12-31',
			'postedWorker' => false,
			'a1CertificateNumber' => null,
			'a1ValidUntil' => null,
			'thirtyPercentRulingGranted' => false,
			'thirtyPercentRulingRate' => null,
			'thirtyPercentCappedAtWntNorm' => false,
		];

	}//end nlSafeEmployee()

	/**
	 * @return array<string, mixed>
	 */
	private static function deEmployee(): array {
		return array_merge(self::nlSafeEmployee(), [
			'employeeNumber' => 'EMP-DE-0001',
			'firstName' => 'Lukas',
			'lastName' => 'Schmidt',
			'dateOfBirth' => '1988-09-03',
			'startDate' => '2021-03-01',
			'endDate' => null,
			'grossMonthlySalary' => 4200.00,
			'elstamRetrieved' => true,
			'steuerklasse' => 'I',
			'w4OnFile' => true,
			'i9VerifiedWithinThreeDays' => true,
			'newHireReportedDate' => '2021-03-05',
		]);

	}//end deEmployee()

	/**
	 * @return array<string, mixed>
	 */
	private static function frEmployee(): array {
		return array_merge(self::nlSafeEmployee(), [
			'employeeNumber' => 'EMP-FR-0001',
			'firstName' => 'Camille',
			'lastName' => 'Dubois',
			'dateOfBirth' => '1992-11-21',
			'startDate' => '2023-06-01',
			'endDate' => null,
			'grossMonthlySalary' => 2600.00,
			'elstamRetrieved' => true,
			'steuerklasse' => 'I',
			'w4OnFile' => true,
			'i9VerifiedWithinThreeDays' => true,
			'newHireReportedDate' => '2023-06-04',
		]);

	}//end frEmployee()

	/**
	 * @return array<string, mixed>
	 */
	private static function usEmployee(): array {
		return array_merge(self::nlSafeEmployee(), [
			'employeeNumber' => 'EMP-US-0001',
			'firstName' => 'Taylor',
			'lastName' => 'Johnson',
			'dateOfBirth' => '1985-02-17',
			'startDate' => '2020-08-15',
			'endDate' => null,
			'grossMonthlySalary' => 6000.00,
			'elstamRetrieved' => true,
			'steuerklasse' => 'I',
			'w4OnFile' => true,
			'i9VerifiedWithinThreeDays' => true,
			'newHireReportedDate' => '2020-08-20',
		]);

	}//end usEmployee()

	/**
	 * Base EmploymentContract fields satisfying the NL minimum-wage + Awf rules.
	 *
	 * @return array<string, mixed>
	 */
	private static function nlSafeContract(): array {
		return [
			'type' => 'permanent',
			'writtenContract' => true,
			'endDate' => null,
			'hoursPerWeek' => 40.0,
			'cao' => null,
			'awfTariff' => 'low',
			'overtimeMultiplier' => 1.5,
			'workingTimeDocumented' => true,
			'ftePartOfYearMinijob' => false,
			'dpaeFiledBeforeStart' => true,
		];

	}//end nlSafeContract()

	/**
	 * @return array<string, mixed>
	 */
	private static function deContract(): array {
		return array_merge(self::nlSafeContract(), [
			'employeeId' => 'EMP-DE-0001',
			'startDate' => '2021-03-01',
			'hourlyWage' => 26.25,
		]);

	}//end deContract()

	/**
	 * @return array<string, mixed>
	 */
	private static function frContract(): array {
		return array_merge(self::nlSafeContract(), [
			'employeeId' => 'EMP-FR-0001',
			'startDate' => '2023-06-01',
			'hourlyWage' => 16.25,
		]);

	}//end frContract()

	/**
	 * @return array<string, mixed>
	 */
	private static function usContract(): array {
		return array_merge(self::nlSafeContract(), [
			'employeeId' => 'EMP-US-0001',
			'startDate' => '2020-08-15',
			'hourlyWage' => 37.50,
		]);

	}//end usContract()

	/**
	 * Base Payslip fields satisfying every NL + global Payslip rule that applies
	 * under the default NL audit.
	 *
	 * @return array<string, mixed>
	 */
	private static function nlSafePayslip(): array {
		return [
			'loonheffing' => 1000.00,
			'volksverzekeringen' => 600.00,
			'werknemersverzekeringen' => 350.00,
			'zvw' => 200.00,
			'zvwMode' => 'werkgeversheffing',
			'zvwRate' => 6.10,
			'anoniementariefApplied' => false,
			'appliedTaxRate' => 28.0,
			'vakantiegeldRate' => 8.0,
			'wkrUsed' => 0.00,
			'wkrVrijeRuimteRemaining' => 1000.00,
			'wkrExcess' => 0.00,
			'wkrEindheffingRate' => null,
			'statementProvided' => true,
			'showsGrossWage' => true,
			'showsDeductionBasis' => true,
			'showsMinimumWage' => true,
			'showsEmployerEmployeeIds' => true,
		];

	}//end nlSafePayslip()

	/**
	 * @return array<string, mixed>
	 */
	private static function dePayslip(): array {
		return array_merge(self::nlSafePayslip(), [
			'employeeId' => 'EMP-DE-0001',
			'period' => '2026-01',
			'jurisdiction' => 'DE',
			'currency' => 'EUR',
			'grossPay' => 4200.00,
			'vakantiegeldReserved' => 336.00,
			'hoursWorked' => 160.0,
			'nettoPay' => 2700.00,
			'pensionContribution' => 390.60,
			'lohnsteuer' => 620.00,
			'solzApplicable' => true,
			'solidaritaetszuschlag' => 34.10,
			'churchMember' => true,
			'kirchensteuer' => 55.80,
			'kirchensteuerRate' => 9.0,
			'rvEmployeeRate' => 9.3,
			'rvEmployerRate' => 9.3,
			'kvBaseRate' => 14.6,
			'avRate' => 2.6,
			'pvBaseRate' => 3.6,
			'svContributionBase' => 4200.00,
			'kvPvContributionBase' => 4200.00,
			// US/FR neutral fields so the sample is self-consistent if audited cross-context.
			'ficaSsRate' => 6.2,
			'ficaSsWageBaseApplied' => 4200.00,
			'medicareRate' => 1.45,
			'additionalMedicareApplied' => false,
			'federalIncomeTaxWithheld' => 0.00,
			'cotisations' => 0.00,
			'pasRate' => 0.0,
			'prelevementSource' => 0.00,
			'reductionGeneraleApplied' => false,
			'netImposable' => 4200.00,
			'netAPayer' => 2700.00,
			'montantNetSocial' => 3500.00,
			'conventionCollective' => 'n/a',
		]);

	}//end dePayslip()

	/**
	 * @return array<string, mixed>
	 */
	private static function frPayslip(): array {
		return array_merge(self::nlSafePayslip(), [
			'employeeId' => 'EMP-FR-0001',
			'period' => '2026-01',
			'jurisdiction' => 'FR',
			'currency' => 'EUR',
			'grossPay' => 2600.00,
			'vakantiegeldReserved' => 208.00,
			'hoursWorked' => 151.67,
			'nettoPay' => 2028.00,
			'pensionContribution' => 0.00,
			'cotisations' => 572.00,
			'pasRate' => 5.0,
			'netImposable' => 2080.00,
			'prelevementSource' => 104.00,
			'reductionGeneraleApplied' => true,
			'netAPayer' => 1924.00,
			'montantNetSocial' => 2080.00,
			'conventionCollective' => 'Syntec',
			'lohnsteuer' => 0.00,
			'solzApplicable' => false,
			'solidaritaetszuschlag' => 0.00,
			'churchMember' => false,
			'kirchensteuer' => 0.00,
			'kirchensteuerRate' => 9.0,
			'rvEmployeeRate' => 9.3,
			'rvEmployerRate' => 9.3,
			'kvBaseRate' => 14.6,
			'avRate' => 2.6,
			'pvBaseRate' => 3.6,
			'svContributionBase' => 2600.00,
			'kvPvContributionBase' => 2600.00,
			'ficaSsRate' => 6.2,
			'ficaSsWageBaseApplied' => 2600.00,
			'medicareRate' => 1.45,
			'additionalMedicareApplied' => false,
			'federalIncomeTaxWithheld' => 0.00,
		]);

	}//end frPayslip()

	/**
	 * @return array<string, mixed>
	 */
	private static function usPayslip(): array {
		return array_merge(self::nlSafePayslip(), [
			'employeeId' => 'EMP-US-0001',
			'period' => '2026-01',
			'jurisdiction' => 'US',
			'currency' => 'USD',
			'grossPay' => 6000.00,
			'vakantiegeldReserved' => 480.00,
			'hoursWorked' => 173.33,
			'nettoPay' => 4400.00,
			'pensionContribution' => 300.00,
			'ficaSsRate' => 6.2,
			'ficaSsWageBaseApplied' => 6000.00,
			'medicareRate' => 1.45,
			'additionalMedicareApplied' => false,
			'federalIncomeTaxWithheld' => 900.00,
			'lohnsteuer' => 0.00,
			'solzApplicable' => false,
			'solidaritaetszuschlag' => 0.00,
			'churchMember' => false,
			'kirchensteuer' => 0.00,
			'kirchensteuerRate' => 9.0,
			'rvEmployeeRate' => 9.3,
			'rvEmployerRate' => 9.3,
			'kvBaseRate' => 14.6,
			'avRate' => 2.6,
			'pvBaseRate' => 3.6,
			'svContributionBase' => 5000.00,
			'kvPvContributionBase' => 5000.00,
			'cotisations' => 0.00,
			'pasRate' => 0.0,
			'prelevementSource' => 0.00,
			'reductionGeneraleApplied' => false,
			'netImposable' => 6000.00,
			'netAPayer' => 4400.00,
			'montantNetSocial' => 5000.00,
			'conventionCollective' => 'n/a',
		]);

	}//end usPayslip()

	/**
	 * @return array<string, mixed>
	 */
	private static function deAnmeldung(): array {
		return [
			'period' => '2026-01',
			'jurisdiction' => 'DE',
			'filingType' => 'lohnsteuer-anmeldung',
			'tijdvak' => 'month',
			'deadline' => '2026-02-10',
			'submittedDate' => '2026-02-08',
			'paidDate' => '2026-02-08',
			'electronicallyFiled' => true,
			'priorYearLiability' => 60000.00,
			'retainedUntil' => '2032-12-31',
		];

	}//end deAnmeldung()

	/**
	 * @return array<string, mixed>
	 */
	private static function deBeitragsnachweis(): array {
		return [
			'period' => '2026-01',
			'jurisdiction' => 'DE',
			'filingType' => 'beitragsnachweis',
			'tijdvak' => 'month',
			'deadline' => '2026-01-29',
			'submittedDate' => '2026-01-27',
			'paidDate' => '2026-01-29',
			'electronicallyFiled' => true,
			'retainedUntil' => '2032-12-31',
		];

	}//end deBeitragsnachweis()

	/**
	 * @return array<string, mixed>
	 */
	private static function deDeuv(): array {
		return [
			'period' => '2026-01',
			'jurisdiction' => 'DE',
			'filingType' => 'deuv-meldung',
			'tijdvak' => 'month',
			'deadline' => '2026-02-15',
			'submittedDate' => '2026-02-10',
			'electronicallyFiled' => true,
			'retainedUntil' => '2032-12-31',
		];

	}//end deDeuv()

	/**
	 * @return array<string, mixed>
	 */
	private static function deBescheinigung(): array {
		return [
			'period' => '2026-12',
			'jurisdiction' => 'DE',
			'filingType' => 'lohnsteuerbescheinigung',
			'tijdvak' => 'year',
			'deadline' => '2027-02-28',
			'submittedDate' => '2027-02-20',
			'electronicallyFiled' => true,
			'furnishedToRecipientDate' => '2027-02-20',
			'retainedUntil' => '2033-12-31',
		];

	}//end deBescheinigung()

	/**
	 * @return array<string, mixed>
	 */
	private static function frDsn(): array {
		return [
			'period' => '2026-01',
			'jurisdiction' => 'FR',
			'filingType' => 'dsn',
			'tijdvak' => 'month',
			'deadline' => '2026-02-15',
			'submittedDate' => '2026-02-10',
			'paidDate' => '2026-02-15',
			'electronicallyFiled' => true,
			'eventSignalDays' => 3,
			'retainedUntil' => '2031-12-31',
		];

	}//end frDsn()

	/**
	 * @return array<string, mixed>
	 */
	private static function usForm941(): array {
		return [
			'period' => '2026-Q1',
			'jurisdiction' => 'US',
			'filingType' => 'form-941',
			'tijdvak' => 'quarter',
			'deadline' => '2026-04-30',
			'submittedDate' => '2026-04-25',
			'paidDate' => '2026-04-25',
			'electronicallyFiled' => true,
			'retainedUntil' => '2030-12-31',
		];

	}//end usForm941()

	/**
	 * @return array<string, mixed>
	 */
	private static function usDeposit(): array {
		return [
			'period' => '2026-01',
			'jurisdiction' => 'US',
			'filingType' => 'deposit',
			'tijdvak' => 'month',
			'deadline' => '2026-02-15',
			'submittedDate' => '2026-02-12',
			'paidDate' => '2026-02-12',
			'electronicallyFiled' => true,
			'lookbackLiability' => 40000.00,
			'depositSchedule' => 'monthly',
			'accumulatedLiability' => 9000.00,
			'nextDayDepositMade' => false,
			'retainedUntil' => '2030-12-31',
		];

	}//end usDeposit()

	/**
	 * @return array<string, mixed>
	 */
	private static function usForm940(): array {
		return [
			'period' => '2026-12',
			'jurisdiction' => 'US',
			'filingType' => 'form-940',
			'tijdvak' => 'year',
			'deadline' => '2027-01-31',
			'submittedDate' => '2027-01-20',
			'paidDate' => '2027-01-20',
			'electronicallyFiled' => true,
			'accumulatedLiability' => 420.00,
			'retainedUntil' => '2031-12-31',
		];

	}//end usForm940()

	/**
	 * @return array<string, mixed>
	 */
	private static function usFormW2(): array {
		return [
			'period' => '2026-12',
			'jurisdiction' => 'US',
			'filingType' => 'form-w2',
			'tijdvak' => 'year',
			'deadline' => '2027-01-31',
			'submittedDate' => '2027-01-20',
			'electronicallyFiled' => true,
			'furnishedToRecipientDate' => '2027-01-25',
			'filedWithAuthorityDate' => '2027-01-25',
			'returnCountInYear' => 12,
			'retainedUntil' => '2031-12-31',
		];

	}//end usFormW2()

	/**
	 * The expected DE Anmeldungszeitraum from the prior-year Lohnsteuer thresholds
	 * (EStG §41a Abs. 2): monthly >EUR 5.000, quarterly EUR 1.080-5.000, else annual.
	 *
	 * @param array<string, mixed> $o The filing.
	 *
	 * @return string 'month' | 'quarter' | 'year'.
	 */
	private static function expectedDeAnmeldungszeitraum(array $o): string {
		$liability = (float)($o['priorYearLiability'] ?? 0);
		if ($liability > 5000.0) {
			return 'month';
		}

		if ($liability > 1080.0) {
			return 'quarter';
		}

		return 'year';
	}//end expectedDeAnmeldungszeitraum()

	/**
	 * The expected US deposit schedule from the lookback liability
	 * (us-deposit-schedule-lookback): monthly when ≤USD 50.000, else semiweekly.
	 *
	 * @param array<string, mixed> $o The filing.
	 *
	 * @return string 'monthly' | 'semiweekly'.
	 */
	private static function expectedUsDepositSchedule(array $o): string {
		return ((float)($o['lookbackLiability'] ?? 0)) <= 50000.0 ? 'monthly' : 'semiweekly';
	}//end expectedUsDepositSchedule()

	/**
	 * True when an object field holds a non-empty value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return isset($o[$key]) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * True when an object field holds a present, numeric value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function numeric(array $o, string $key): bool {
		return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;
	}//end numeric()

	/**
	 * True when a field holds the expected rate percentage (basis-point precision).
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 * @param float $expected Expected rate.
	 *
	 * @return bool
	 */
	private static function rate(array $o, string $key, float $expected): bool {
		return self::numeric($o, $key) && ((int)round(((float)$o[$key]) * 100) === (int)round($expected * 100));
	}//end rate()

	/**
	 * Compare two amounts at cent precision.
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
	 * True when the date at $dateKey is present and on or before the date at
	 * $limitKey.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $dateKey Actual-date field.
	 * @param string $limitKey Limit/deadline field.
	 *
	 * @return bool
	 */
	private static function onOrBefore(array $o, string $dateKey, string $limitKey): bool {
		$date = strtotime((string)($o[$dateKey] ?? ''));
		$limit = strtotime((string)($o[$limitKey] ?? ''));
		if ($date === false || $limit === false) {
			return false;
		}

		return $date <= $limit;
	}//end onOrBefore()

	/**
	 * True when the date at $endKey is present and within $days days of the date
	 * at $startKey.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $startKey Start-date field.
	 * @param string $endKey Event-date field.
	 * @param int $days Maximum number of days.
	 *
	 * @return bool
	 */
	private static function withinDaysOf(array $o, string $startKey, string $endKey, int $days): bool {
		$start = strtotime((string)($o[$startKey] ?? ''));
		$end = strtotime((string)($o[$endKey] ?? ''));
		if ($start === false || $end === false || $end < $start) {
			return false;
		}

		return (($end - $start) / 86400) <= $days;
	}//end withinDaysOf()

	/**
	 * True when the retention date is at least $years after the end of the year
	 * of the filing period (period in YYYY or YYYY-... form).
	 *
	 * @param array<string, mixed> $o The filing.
	 * @param int $years Minimum retention years.
	 *
	 * @return bool
	 */
	private static function retainedYearsAfterPeriod(array $o, int $years): bool {
		$retain = strtotime((string)($o['retainedUntil'] ?? ''));
		$period = (string)($o['period'] ?? '');
		if ($retain === false || preg_match('/^(\d{4})/', $period, $m) !== 1) {
			return false;
		}

		$required = mktime(0, 0, 0, 12, 31, ((int)$m[1] + $years));
		return $retain >= $required;
	}//end retainedYearsAfterPeriod()

}//end class
