<?php

/**
 * Characterisation test for the NlPayrollChecks predicates.
 *
 * Pins every predicate's allowed and refused outcomes over a generated grid of
 * objects (the seed samples, an empty object and each single-field variation)
 * as a per-rule hash, measured on the code before the predicates were split
 * into named methods. The time-dependent rules (nl-30-regeling-looptijd-5jaar) are left out: their outcome moves with the clock.
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

use OCA\Humaniq\Standards\Checks\NlPayrollChecks;
use PHPUnit\Framework\TestCase;

/**
 * Every NlPayrollChecks predicate keeps its outcomes.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
class NlPayrollChecksFingerprintTest extends TestCase {

	/**
	 * The fields the predicates read.
	 *
	 * @var list<string>
	 */
	private const FIELDS = [
		'a1CertificateNumber',
		'a1ValidUntil',
		'anoniementariefApplied',
		'appliedTaxRate',
		'awfAverageContractHoursPerWeek',
		'awfContractHoursYearToDate',
		'awfOverrunPercentYearToDate',
		'awfTariff',
		'awfTariffBasis',
		'employeeId',
		'endDate',
		'glExpensePosted',
		'glLiabilityPosted',
		'grossMonthlySalary',
		'grossPay',
		'hourlyWage',
		'hoursPerWeek',
		'identityDocumentRetainedUntil',
		'identityDocumentVerified',
		'loonheffing',
		'loonheffingenVerklaringOnFile',
		'loonheffingenVerklaringRetainedUntil',
		'period',
		'postedWorker',
		'showsDeductionBasis',
		'showsEmployerEmployeeIds',
		'showsGrossWage',
		'showsMinimumWage',
		'startDate',
		'status',
		'thirtyPercentCappedAtWntNorm',
		'thirtyPercentRulingEndDate',
		'thirtyPercentRulingExemption',
		'thirtyPercentRulingGranted',
		'thirtyPercentRulingRate',
		'thirtyPercentRulingReducedNormApplies',
		'thirtyPercentRulingStartDate',
		'totalEmployerCharges',
		'totalGross',
		'totalNet',
		'vakantiegeldRate',
		'vakantiegeldReserved',
		'volksverzekeringen',
		'werknemersverzekeringen',
		'withholdingLiabilityBalance',
		'withholdingsClearedToZero',
		'wkrEindheffingRate',
		'wkrExcess',
		'wkrVrijeRuimteRemaining',
		'zvw',
		'zvwMode',
		'zvwRate',
		'type',
		'writtenContract',
	];

	/**
	 * Field values that select a branch, applied to each seed before the grid.
	 *
	 * @var array<string, list<mixed>>
	 */
	private const SELECTORS = [
		'zvwMode' => ['werkgeversheffing', 'inhouding'],
		'status' => ['draft', 'paid'],
		'anoniementariefApplied' => [true],
		'thirtyPercentRulingGranted' => [true, false],
		'postedWorker' => [true],
		'loonheffingenVerklaringOnFile' => [false],
		'wkrExcess' => [10.0],
	];

	/**
	 * Rules left out because their outcome depends on the current time.
	 *
	 * @var list<string>
	 */
	private const TIME_DEPENDENT = ['nl-30-regeling-looptijd-5jaar'];

	/**
	 * Per object type and rule: the count of passing objects and the outcome hash.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const EXPECTED = [
		'Payslip' => [
			'nl-awf-herziening-uren-signaal' => '73678:f4f229552b04d3bc5ae13172538d41ca',
			'nl-loonheffingen-inhouding' => '36663:f982dde86c6fa24e1755b19076546088',
			'nl-loonheffingen-volksverzekeringen' => '35728:f63c732bf81597809e91b0921165956c',
			'nl-werknemersverzekeringen-werkgever' => '36663:8e0856a83f0f5549eea403fc7cfa194e',
			'nl-zvw-werkgeversheffing' => '69390:949ff604418688f76e0d0252d0d6e580',
			'nl-zvw-inhouding' => '67083:2ee9ee89fd7f5ae1aa5f37c106b6a5fd',
			'nl-anoniementarief' => '33223:555862ac77bcc915856e38f3e3d6e766',
			'nl-vakantiebijslag-8procent' => '35002:7b886ffb6e72ab162da4b54e1f4f204c',
			'nl-wkr-vrije-ruimte' => '36675:7ad5cea7a11e4cd2cc0e7e673c2e3863',
			'nl-wkr-eindheffing-80' => '66512:415380e29da817bf8616d8dd97d1f129',
			'nl-loonstrook-inhoud' => '34155:6dc2bf545ab0c83b33a9b18c763d0085',
			'nl-30-regeling-aftoppingsgrens-bedrag' => '73678:f4f229552b04d3bc5ae13172538d41ca',
		],
		'Employee' => [
			'nl-id-bewaarplicht-5jaar' => '35607:25f40f1387b25403a403d6853acee7d0',
			'nl-loonbelastingverklaring-bewaarplicht-5jaar' => '40926:9eac731f112f1dc4ccf894edb59f2b0a',
			'nl-30-percent-regeling' => '69914:e7a1123c99dae73539e0ac136025376a',
			'nl-30-regeling-aftoppingsgrens' => '69771:b4a309460a896e0e79048dd620ba31c4',
			'nl-30-regeling-salarisnorm' => '72848:e057cc25a47277e59db164266478ba3f',
			'eu-a1-posted-worker' => '67082:7e4ae3869c1f8bcde1861f40ad30c1ee',
		],
		'EmploymentContract' => [
			'nl-awf-laag-hoog-tarief' => '34837:3b349e788977105ad8539c325293ae56',
			'nl-minimumloon-2026' => '36465:405f66823184bbff186d5cd7e9fa1505',
			'nl-minimumuurloon-wet' => '35981:733d71eb68e86e8a50c482dc47365095',
		],
		'PayrollRun' => [
			'xc-payroll-gl-reconciliation' => '67269:a4609a86e5fc78e6a36af7dbe92ecaab',
			'xc-withholding-liability-clearing' => '69149:d75664ce182c30cd9cfb49a716e53bbe',
		],
	];

	/**
	 * @return void
	 */
	public function testEveryPredicateKeepsItsOutcomes(): void {
		$seeds  = NlPayrollChecks::seedObjects();
		$actual = [];
		foreach (NlPayrollChecks::checks() as $type => $rules) {
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
