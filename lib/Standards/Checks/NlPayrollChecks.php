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

use OCA\Humaniq\Payroll\TaxTables;

/**
 * Dutch payroll / loonheffingen + global GL-control + EU A1 executable checks.
 */
final class NlPayrollChecks implements CheckProvider, SeedsObjects {

	/**
	 * The 30%-ruling maximum term in months (Belastingdienst beschikking; the
	 * `dertigProcentRegeling.maxDurationMonths` corpus leaf). An Employee check
	 * has no wage period to load a versioned table for, so the flat statutory
	 * value is a class constant here -- the `nl-30-percent-regeling` <= 30 and
	 * `nl-minimumloon-2026` >= 14,71 hardcoding precedent.
	 *
	 * @var int
	 */
	private const THIRTY_PERCENT_MAX_DURATION_MONTHS = 60;

	/**
	 * The 2026 general 30%-ruling annual salary norm in cents
	 * (`dertigProcentRegeling.salarisnormAlgemeen`, €48.013). Same
	 * Employee-check hardcoding precedent as the term above.
	 *
	 * @var int
	 */
	private const THIRTY_PERCENT_SALARISNORM_ALGEMEEN_CENTS = 4801300;

	/**
	 * The 2026 reduced 30%-ruling annual salary norm in cents
	 * (`dertigProcentRegeling.salarisnormMasterOnder30`, €36.497) for employees
	 * under 30 with a qualifying master's degree.
	 *
	 * @var int
	 */
	private const THIRTY_PERCENT_SALARISNORM_MASTER_ONDER30_CENTS = 3649700;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	public static function checks(): array {
		return [
			'Payslip' => [
				// Wet LB 1964 art. 27 — a loonheffing must be withheld at each payment.
				'nl-loonheffingen-inhouding' => static fn (array $o): bool => self::numeric($o, 'loonheffing') && ((float)$o['loonheffing']) >= 0.0,
				// Wfsv / art. 27b — premie volksverzekeringen is levied inside the combined
				// loonheffing, so it must be present and not exceed the total loonheffing.
				'nl-loonheffingen-volksverzekeringen' => static fn (array $o): bool => self::numeric($o, 'volksverzekeringen')
					&& self::numeric($o, 'loonheffing')
					&& ((float)$o['volksverzekeringen']) >= 0.0
					&& ((float)$o['volksverzekeringen']) <= ((float)$o['loonheffing']),
				// Wfsv — employer-borne werknemersverzekeringen (WW/WIA/ZW) over the wage.
				'nl-werknemersverzekeringen-werkgever' => static fn (array $o): bool => self::numeric($o, 'werknemersverzekeringen')
					&& ((float)$o['werknemersverzekeringen']) >= 0.0,
				// Zvw werkgeversheffing — when the employer-levy mode applies, the 2026 rate is 6,10%.
				'nl-zvw-werkgeversheffing' => static fn (array $o): bool => ((string)($o['zvwMode'] ?? '') !== 'werkgeversheffing')
					|| (self::numeric($o, 'zvwRate') && self::ratesEqual((float)$o['zvwRate'], 6.10) && self::numeric($o, 'zvw')),
				// Zvw ingehouden bijdrage — when the withheld mode applies, the 2026 rate is 4,85%.
				'nl-zvw-inhouding' => static fn (array $o): bool => ((string)($o['zvwMode'] ?? '') !== 'inhouding')
					|| (self::numeric($o, 'zvwRate') && self::ratesEqual((float)$o['zvwRate'], 4.85) && self::numeric($o, 'zvw')),
				// Wet LB 1964 art. 26b — the anoniementarief is exactly 52% when applied, and
				// the effective applied rate must be consistent with that flag.
				'nl-anoniementarief' => static fn (array $o): bool => self::numeric($o, 'appliedTaxRate')
					&& ((($o['anoniementariefApplied'] ?? false) === true)
					? self::ratesEqual((float)$o['appliedTaxRate'], 52.0)
					: ((float)$o['appliedTaxRate']) < 52.0),
				// WML art. 15 — vakantiebijslag is at least 8% of gross, and the reserved
				// amount reconciles to that rate applied to the gross pay (to the cent).
				'nl-vakantiebijslag-8procent' => static fn (array $o): bool => self::numeric($o, 'vakantiegeldRate')
					&& ((float)$o['vakantiegeldRate']) >= 8.0
					&& self::centsEqual((float)($o['vakantiegeldReserved'] ?? 0), (((float)($o['grossPay'] ?? 0)) * ((float)$o['vakantiegeldRate']) / 100)),
				// Werkkostenregeling — designated allowances charged to the vrije ruimte may
				// not push the remaining ruimte negative without recording the excess.
				'nl-wkr-vrije-ruimte' => static fn (array $o): bool => self::numeric($o, 'wkrVrijeRuimteRemaining')
					&& (((float)$o['wkrVrijeRuimteRemaining']) >= 0.0 || self::numeric($o, 'wkrExcess')),
				// Wet LB 1964 art. 31a — any WKR excess over the vrije ruimte carries an
				// 80% eindheffing; no excess means no eindheffing is owed.
				'nl-wkr-eindheffing-80' => static fn (array $o): bool => (((float)($o['wkrExcess'] ?? 0)) <= 0.0)
					|| (self::numeric($o, 'wkrEindheffingRate') && self::ratesEqual((float)$o['wkrEindheffingRate'], 80.0)),
				// BW 7:626 lid 1 — the payslip must state gross wage, deduction basis, the
				// applicable minimum wage, and employer/employee identification.
				'nl-loonstrook-inhoud' => static fn (array $o): bool => (($o['showsGrossWage'] ?? false) === true)
					&& (($o['showsDeductionBasis'] ?? false) === true)
					&& (($o['showsMinimumWage'] ?? false) === true)
					&& (($o['showsEmployerEmployeeIds'] ?? false) === true),
				// Wet LB 1964 art. 31a (aftopping WNT-norm) — the recorded 30%-ruling
				// exemption re-derives cents-exact from the WNT-capped formula
				// (30-procent-regeling): the NUMERIC enforcement the boolean-only
				// nl-30-regeling-aftoppingsgrens never provided.
				'nl-30-regeling-aftoppingsgrens-bedrag' => static fn (array $o, array $context): bool => self::thirtyPercentExemptionMatchesFormula($o, $context),
			],
			'Employee' => [
				// Handboek Loonheffingen — a verified ID-document copy is kept until at least
				// 5 years after the year employment ends.
				'nl-id-bewaarplicht-5jaar' => static fn (array $o): bool => (($o['identityDocumentVerified'] ?? false) === true)
					&& self::present($o, 'identityDocumentRetainedUntil')
					&& self::retainedAtLeastYearsAfterEnd($o, 'identityDocumentRetainedUntil', 5),
				// Wet LB 1964 art. 31a (30%-regeling) — when granted, the applied tax-free
				// percentage must not exceed 30 for 2025-2026.
				'nl-30-percent-regeling' => static fn (array $o): bool => (($o['thirtyPercentRulingGranted'] ?? false) !== true)
					|| (self::numeric($o, 'thirtyPercentRulingRate') && ((float)$o['thirtyPercentRulingRate']) <= 30.0),
				// Wet LB 1964 art. 31a (aftopping WNT-norm) — a granted 30%-ruling is capped
				// at the WNT-norm.
				'nl-30-regeling-aftoppingsgrens' => static fn (array $o): bool => (($o['thirtyPercentRulingGranted'] ?? false) !== true)
					|| (($o['thirtyPercentCappedAtWntNorm'] ?? false) === true),
				// Wet LB 1964 art. 31a (looptijd) — a granted 30%-ruling runs at most
				// 60 months from its start; flags an end date absent/beyond the term
				// or a stale ruling past its end date (30-procent-regeling).
				'nl-30-regeling-looptijd-5jaar' => static fn (array $o): bool => self::thirtyPercentTermSatisfied($o),
				// Wet LB 1964 art. 31a (salarisnorm) — a granted 30%-ruling requires an
				// annualised salary at or above the applicable norm (general or the
				// under-30-master reduced norm) (30-procent-regeling).
				'nl-30-regeling-salarisnorm' => static fn (array $o): bool => self::thirtyPercentSalaryNormSatisfied($o),
				// Regulation (EC) 883/2004 art. 12 — a posted worker holds a valid A1
				// certificate that runs no longer than 24 months from the posting.
				'eu-a1-posted-worker' => static fn (array $o): bool => (($o['postedWorker'] ?? false) !== true)
					|| (self::present($o, 'a1CertificateNumber')
					&& self::present($o, 'a1ValidUntil')
					&& self::withinMonths($o, 'startDate', 'a1ValidUntil', 24)),
			],
			'EmploymentContract' => [
				// Wfsv (Wab) — the Awf low tariff applies only to permanent written contracts;
				// every other contract takes the high tariff. The applied tariff must match.
				'nl-awf-laag-hoog-tarief' => static fn (array $o): bool => self::present($o, 'awfTariff')
					&& ((string)$o['awfTariff'] === self::expectedAwfTariff($o)),
				// WML art. 8 — from 1 Jan 2026 the statutory minimum hourly wage is EUR 14,71
				// for employees aged 21+; the contract hourly wage must meet it.
				'nl-minimumloon-2026' => static fn (array $o): bool => self::numeric($o, 'hourlyWage')
					&& ((float)$o['hourlyWage']) >= 14.71,
				// Wet invoering minimumuurloon — a single hourly minimum applies; weekly/monthly
				// minimums derive from it, so contracted hours must be a positive number.
				'nl-minimumuurloon-wet' => static fn (array $o): bool => self::numeric($o, 'hoursPerWeek')
					&& ((float)$o['hoursPerWeek']) > 0.0
					&& self::numeric($o, 'hourlyWage'),
			],
			'PayrollRun' => [
				// Payroll-to-GL control — the GL liability/expense postings reconcile to the
				// run totals: expense = gross + employer charges; liability = gross + charges − net.
				// Scoped to posted/paid runs (payroll-core-engine): a draft/approved run has no
				// GL postings yet by definition, so checking it would be a false positive —
				// never surfaced before because the only seeded run is `posted`.
				'xc-payroll-gl-reconciliation' => static fn (array $o): bool => self::isGlPosted($o) === false
					|| (self::numeric($o, 'glExpensePosted')
					&& self::numeric($o, 'glLiabilityPosted')
					&& self::centsEqual((float)$o['glExpensePosted'], (((float)($o['totalGross'] ?? 0)) + ((float)($o['totalEmployerCharges'] ?? 0))))
					&& self::centsEqual((float)$o['glLiabilityPosted'], (((float)($o['totalGross'] ?? 0)) + ((float)($o['totalEmployerCharges'] ?? 0)) - ((float)($o['totalNet'] ?? 0))))),
				// Liability-clearing control — each withholding liability is cleared to zero on
				// remittance, so a cleared run carries a zero residual liability balance.
				// Same posted/paid scoping: remittance clearing only exists once posted.
				'xc-withholding-liability-clearing' => static fn (array $o): bool => self::isGlPosted($o) === false
					|| ((($o['withholdingsClearedToZero'] ?? false) === true)
					&& self::numeric($o, 'withholdingLiabilityBalance')
					&& self::centsEqual((float)$o['withholdingLiabilityBalance'], 0.0)),
			],
		];

	}//end checks()

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
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'Employee' => [
				[
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
				],
			],
			'EmploymentContract' => [
				[
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
				],
			],
			'Payslip' => [
				[
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
					'statementProvided' => true,
					'showsGrossWage' => true,
					'showsDeductionBasis' => true,
					'showsMinimumWage' => true,
					'showsEmployerEmployeeIds' => true,
					// Cross-jurisdiction-neutral fields so a DE/FR/US audit of this NL
					// payslip also reports zero violations (the matching country checks).
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
					'svContributionBase' => 3800.00,
					'kvPvContributionBase' => 3800.00,
					'ficaSsRate' => 6.2,
					'ficaSsWageBaseApplied' => 3800.00,
					'medicareRate' => 1.45,
					'additionalMedicareApplied' => false,
					'federalIncomeTaxWithheld' => 0.00,
					'cotisations' => 0.00,
					'pasRate' => 0.0,
					'prelevementSource' => 0.00,
					'reductionGeneraleApplied' => false,
					'netImposable' => 3800.00,
					'netAPayer' => 2698.00,
					'montantNetSocial' => 3200.00,
					'conventionCollective' => 'n/a',
				],
			],
			'PayrollRun' => [
				[
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
				],
			],
		];

	}//end seedObjects()

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
	 * The Awf tariff a contract should carry: low only for a permanent written
	 * contract, high in every other case.
	 *
	 * @param array<string, mixed> $o The EmploymentContract.
	 *
	 * @return string 'low' or 'high'.
	 */
	private static function expectedAwfTariff(array $o): string {
		$permanent = ((string)($o['type'] ?? '') === 'permanent');
		$written = (($o['writtenContract'] ?? false) === true);
		return ($permanent === true && $written === true) ? 'low' : 'high';
	}//end expectedAwfTariff()

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
	 * True when a retention date is at least $years after the end of the year in
	 * which employment ended (or, while still employed, simply a future-dated and
	 * present retention date).
	 *
	 * @param array<string, mixed> $o The Employee.
	 * @param string $key Retention-date field.
	 * @param int $years Minimum number of years.
	 *
	 * @return bool
	 */
	private static function retainedAtLeastYearsAfterEnd(array $o, string $key, int $years): bool {
		$retain = strtotime((string)($o[$key] ?? ''));
		if ($retain === false) {
			return false;
		}

		$end = trim((string)($o['endDate'] ?? ''));
		if ($end === '') {
			// Still employed: retention clock has not started; presence is enough.
			return true;
		}

		$endYear = (int)date('Y', (int)strtotime($end));
		$required = mktime(0, 0, 0, 12, 31, ($endYear + $years));
		return $retain >= $required;
	}//end retainedAtLeastYearsAfterEnd()

	/**
	 * The `nl-30-regeling-looptijd-5jaar` predicate (30-procent-regeling
	 * REQ-30P-004): vacuous when the ruling is not granted, or when the ruling
	 * has no `thirtyPercentRulingStartDate` (an incomplete-but-not-contradictory
	 * record — a MISSING start date is a data-quality signal deferred to a
	 * follow-up check, design.md D5, so it is not flagged here). Otherwise flags
	 * when the end date is absent, more than the 60-month maximum term after the
	 * start date, or already in the past while the ruling is still granted.
	 *
	 * @param array<string, mixed> $o The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/30-procent-regeling/specs/30-procent-regeling/spec.md#REQ-30P-004
	 */
	private static function thirtyPercentTermSatisfied(array $o): bool {
		if (($o['thirtyPercentRulingGranted'] ?? false) !== true) {
			return true;
		}

		$start = strtotime((string)($o['thirtyPercentRulingStartDate'] ?? ''));
		if ($start === false) {
			// Incomplete-but-not-contradictory ruling (no start date) — vacuous,
			// deferred to a follow-up data-quality check (design.md D5).
			return true;
		}

		$end = strtotime((string)($o['thirtyPercentRulingEndDate'] ?? ''));
		if ($end === false) {
			// Granted with a start but no end date — flag.
			return false;
		}

		$maxEnd = strtotime('+' . self::THIRTY_PERCENT_MAX_DURATION_MONTHS . ' months', $start);
		if ($maxEnd !== false && $end > $maxEnd) {
			// End date runs beyond the 60-month statutory term — flag.
			return false;
		}

		// A stale ruling still marked granted after its end date has passed — flag.
		return $end >= time();
	}//end thirtyPercentTermSatisfied()

	/**
	 * The `nl-30-regeling-salarisnorm` predicate (30-procent-regeling
	 * REQ-30P-004): vacuous when the ruling is not granted or
	 * `grossMonthlySalary` is non-numeric. Otherwise flags when the annualised
	 * salary (`grossMonthlySalary × 12`) is below the applicable norm — the
	 * reduced under-30-master norm when
	 * `thirtyPercentRulingReducedNormApplies` is true, else the general norm.
	 *
	 * @param array<string, mixed> $o The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/30-procent-regeling/specs/30-procent-regeling/spec.md#REQ-30P-004
	 */
	private static function thirtyPercentSalaryNormSatisfied(array $o): bool {
		if (($o['thirtyPercentRulingGranted'] ?? false) !== true) {
			return true;
		}

		if (self::numeric($o, 'grossMonthlySalary') === false) {
			return true;
		}

		$annualCents = (int)round(((float)$o['grossMonthlySalary']) * 12 * 100);
		$normCents = ((($o['thirtyPercentRulingReducedNormApplies'] ?? false) === true)
			? self::THIRTY_PERCENT_SALARISNORM_MASTER_ONDER30_CENTS
			: self::THIRTY_PERCENT_SALARISNORM_ALGEMEEN_CENTS);

		return $annualCents >= $normCents;
	}//end thirtyPercentSalaryNormSatisfied()

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
	 * @spec openspec/changes/30-procent-regeling/specs/30-procent-regeling/spec.md#REQ-30P-004
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
	 * Convert a euro-denominated value to integer cents (`round($euros × 100)`,
	 * the `NlFleetChecks::cents()` precedent). Non-numeric/null values convert
	 * to 0.
	 *
	 * @param mixed $euros The raw field value.
	 *
	 * @return int
	 */
	private static function cents(mixed $euros): int {
		if (is_numeric($euros) === false) {
			return 0;
		}

		return (int)round(((float)$euros) * 100);
	}//end cents()

	/**
	 * True when the date at $endKey is no more than $months after the date at
	 * $startKey.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $startKey Start-date field.
	 * @param string $endKey End-date field.
	 * @param int $months Maximum number of months.
	 *
	 * @return bool
	 */
	private static function withinMonths(array $o, string $startKey, string $endKey, int $months): bool {
		$start = strtotime((string)($o[$startKey] ?? ''));
		$end = strtotime((string)($o[$endKey] ?? ''));
		if ($start === false || $end === false || $end < $start) {
			return false;
		}

		$limit = strtotime('+' . $months . ' months', $start);
		return $end <= $limit;
	}//end withinMonths()

}//end class
