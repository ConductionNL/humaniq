<?php

/**
 * Characterisation test for the EuUsPayrollChecks predicates.
 *
 * Pins every predicate's allowed and refused outcomes over a generated grid of
 * objects (the seed samples, an empty object and each single-field variation)
 * as a per-rule hash, measured on the code before the predicates were split
 * into named methods.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\EuUsPayrollChecks;
use PHPUnit\Framework\TestCase;

/**
 * Every EuUsPayrollChecks predicate keeps its outcomes.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
class EuUsPayrollChecksFingerprintTest extends TestCase {

	/**
	 * The fields the predicates read.
	 *
	 * @var list<string>
	 */
	private const FIELDS = [
		'accumulatedLiability',
		'additionalMedicareApplied',
		'avRate',
		'churchMember',
		'conventionCollective',
		'cotisations',
		'deadline',
		'depositSchedule',
		'dpaeFiledBeforeStart',
		'electronicallyFiled',
		'elstamRetrieved',
		'eventSignalDays',
		'federalIncomeTaxWithheld',
		'ficaSsRate',
		'ficaSsWageBaseApplied',
		'filedWithAuthorityDate',
		'filingType',
		'ftePartOfYearMinijob',
		'furnishedToRecipientDate',
		'hourlyWage',
		'i9VerifiedWithinThreeDays',
		'kirchensteuer',
		'kirchensteuerRate',
		'kvBaseRate',
		'kvPvContributionBase',
		'lohnsteuer',
		'lookbackLiability',
		'medicareRate',
		'montantNetSocial',
		'netAPayer',
		'netImposable',
		'newHireReportedDate',
		'nextDayDepositMade',
		'overtimeMultiplier',
		'paidDate',
		'pasRate',
		'period',
		'prelevementSource',
		'priorYearLiability',
		'pvBaseRate',
		'reductionGeneraleApplied',
		'retainedUntil',
		'returnCountInYear',
		'rvEmployeeRate',
		'rvEmployerRate',
		'showsGrossWage',
		'solidaritaetszuschlag',
		'solzApplicable',
		'startDate',
		'steuerklasse',
		'submittedDate',
		'svContributionBase',
		'tijdvak',
		'type',
		'w4OnFile',
		'workingTimeDocumented',
	];

	/**
	 * Field values that select a branch, applied to each seed before the grid.
	 *
	 * @var array<string, list<mixed>>
	 */
	private const SELECTORS = [
		'depositSchedule' => ['monthly', 'semiweekly'],
		'churchMember' => [true],
		'solzApplicable' => [true],
		'type' => ['minijob'],
	];

	/**
	 * Rules left out because their outcome depends on the current time.
	 *
	 * @var list<string>
	 */
	private const TIME_DEPENDENT = [];

	/**
	 * Per object type and rule: the count of passing objects and the outcome hash.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const EXPECTED = [
		'Payslip' => [
			'de-lohnsteuer-einbehalt' => '61950:5a658b9f43f636878cf1fefaaadfab20',
			'de-solidaritaetszuschlag' => '82440:8f313bf3cbe83c2572ce1a658cfa9d29',
			'de-kirchensteuer' => '78595:56cbb17c342351d9343d1d4671b89c9a',
			'de-rv-beitragssatz-2026' => '60282:0835580b978817f13c9e6715a13298ab',
			'de-kv-beitragssatz-2026' => '61398:400d5834fac8b448488bbffc6f0641ae',
			'de-av-beitragssatz-2026' => '61398:9cdde3fba6a69bf89e515fa5c2c58822',
			'de-pv-beitragssatz-2026' => '61398:716239dc2a013844963e494444c83ea1',
			'de-bbg-rv-av-2026' => '61902:1fb9b88c77e9e6814c97e16cee41531f',
			'de-bbg-kv-pv-2026' => '61902:33546a9d11f8ede84b6285e62e7c549c',
			'us-fica-social-security' => '61398:78a55bed41b61d29a01255bdd8ff47b4',
			'us-social-security-wage-base-2026' => '61950:92a8649aba21cb27d405d0fcd9eba289',
			'us-fica-medicare' => '61398:4a4bc0b2101af4a05f366ec065df6ba4',
			'us-additional-medicare-tax' => '61446:d027dbe7ec5fdab3d05d8b656069d7b6',
			'us-federal-income-tax-withholding' => '61950:7fdacc1c7159ac6acc0ba31d717b54a1',
			'fr-urssaf-cotisations' => '61950:1535ce88a28fff9c35da9626610ea494',
			'fr-prelevement-a-la-source' => '60480:b7a31f8fcfe016237e746f713383a04e',
			'fr-reduction-generale' => '61446:c26aeb30e567a73bf2e228f0300bba67',
			'fr-bulletin-de-paie-mentions' => '58608:f215704c44c715287e1d55d7b5d15b46',
			'fr-montant-net-social' => '61974:2d973acc8071e469f8bff342359b091e',
		],
		'Employee' => [
			'de-elstam-abruf' => '61344:98a185188e454bf6d638439e81da2412',
			'us-w4-on-file' => '61422:5741b07d65ae72b7b7f2d2cf981ac10c',
			'us-i9-verification' => '61422:6d725e68f1c1996fbac41c64b00101ec',
			'us-new-hire-reporting' => '60282:feeec27cd831658b8e7cb67ab9e2da3a',
		],
		'EmploymentContract' => [
			'de-mindestlohn-2026' => '61734:a70187630a24752e1d7e497d92ac265b',
			'de-mindestlohn-arbeitszeit-doku' => '79747:166890213b26440ac2809f09dca2db32',
			'de-minijob-pauschale' => '69688:3329d1792fc8b8ebff8e51b4c46215e1',
			'fr-smic-2026' => '61758:961761bc4f5f63a4ac6886f24e516f4e',
			'us-flsa-minimum-wage' => '61830:75d1cfce841c88354446a0b5174cbc6a',
			'us-flsa-overtime' => '61902:71356739c85bd1a82a68d11ece8033a3',
			'fr-dpae' => '61422:59dc179a640ae97a827ca1454a87d4a0',
		],
		'LoonaangifteFiling' => [
			'de-lohnsteuer-anmeldung' => '207348:ff93389110b454cf56e5adf159fbf137',
			'de-lohnsteuer-anmeldungszeitraum' => '207624:42efbeb26c4326716c7937ad124861c7',
			'de-lohnkonto-aufbewahrung' => '207648:57487924bca5ff4ffcd8ee3897f5c14b',
			'de-lohnsteuerbescheinigung' => '208308:ce4d6a96ce7577726661377424722a4b',
			'de-sozialversicherung-meldepflicht' => '208008:a8879cdf72e873d2b31ba57bd5e92128',
			'de-sv-gesamtbeitrag-abfuehren' => '208332:413c6648db02b6a2bc88d485874b5e34',
			'de-sv-faelligkeit' => '207696:7b94894c42d5265fe1fab0f33810ead1',
			'de-beitragsnachweis' => '207348:b43f9605959b015bf5338cb3571cd804',
			'fr-dsn-mensuelle' => '207618:62925284cc8f0baecc9efbbffdd3caed',
			'fr-dsn-echeance' => '207720:87596667072ff3491419721136681b5c',
			'fr-dsn-evenementielle' => '208050:d1b75139944b4cb29f7746da5fc3d374',
			'fr-bulletin-conservation' => '207666:37d634d564a94b327b8b50a90c25184f',
			'us-form-941-quarterly' => '207300:5eb43bd6e234776fc965d993182ed03f',
			'us-deposit-schedule-lookback' => '204611:7f5383f351454b9617309b54dcd78311',
			'us-deposit-monthly' => '207831:4da7606e120fe173c38696ce56442afb',
			'us-deposit-semiweekly' => '205016:2f8533e79e393434c282aaec3866c5fd',
			'us-deposit-100k-next-day' => '208368:9fdc62844d25d56cf346663f485b7c46',
			'us-eftps-deposit' => '208008:04c21fd11090d8d88a771b620317b094',
			'us-futa-form-940' => '207312:6c12b50b5196b9066879050a81d27c98',
			'us-futa-state-credit' => '208104:e74b434917bd3b66c920ca36a8d8ddf0',
			'us-futa-deposit-500' => '208380:ccd5d67de6d49f80f99a5f0a2104dab4',
			'us-form-w2-to-employee' => '207672:626f6093a8b31c3130c83f4ffd27b2d5',
			'us-form-w2-w3-to-ssa' => '207666:d29afe72ca18268933220022a9cb96ab',
			'us-w2-efile-threshold' => '208014:3a6a6a41ed42816eef59ca1c40833b8a',
			'us-payroll-record-retention-irc' => '207678:59f2d21a573fdb766ef6f34764bec931',
			'us-flsa-record-retention' => '207678:59f2d21a573fdb766ef6f34764bec931',
		],
		'PayrollRun' => [
		],
	];

	/**
	 * @return void
	 */
	public function testEveryPredicateKeepsItsOutcomes(): void {
		$seeds  = EuUsPayrollChecks::seedObjects();
		$actual = [];
		foreach (EuUsPayrollChecks::checks() as $type => $rules) {
			$grid = PredicateFingerprint::grid(PredicateFingerprint::rebase($seeds[$type] ?? [], self::SELECTORS), self::FIELDS);
			$actual[$type] = [];
			foreach ($rules as $ruleId => $predicate) {
				if (in_array($ruleId, self::TIME_DEPENDENT, true) === false) {
					$actual[$type][$ruleId] = PredicateFingerprint::of($predicate, $grid);
				}
			}
		}

		$this->assertSame(self::EXPECTED, $actual);

	}//end testEveryPredicateKeepsItsOutcomes()
}//end class
