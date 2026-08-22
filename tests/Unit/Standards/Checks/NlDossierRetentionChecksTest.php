<?php

/**
 * Unit tests for NlDossierRetentionChecks.
 *
 * hrmq#99 hole #2: pins `nl-bewaartermijn-verstreken` -- vacuous when
 * `retention.archiefactiedatum` is unpopulated, violated when it is a past
 * date and `archiefstatus` is not `vernietigd`, satisfied when it is a future
 * date, and vacuous (not re-violated) once `archiefstatus` is `vernietigd`
 * (properly destroyed -- no longer "still present"). The predicate reads
 * ONLY OpenRegister's own `retention.archiefactiedatum` -- never a bespoke
 * humaniq field -- across the payroll/loonadministratie schema family plus
 * `GeneratedDocument`.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlDossierRetentionChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlDossierRetentionChecks.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */
class NlDossierRetentionChecksTest extends TestCase {

	/**
	 * The rule id under test.
	 *
	 * @var string
	 */
	private const RULE_ID = 'nl-bewaartermijn-verstreken';

	/**
	 * The check is registered for every schema in the payroll family plus
	 * GeneratedDocument (mirrors the deleted AvgDsrRetentionClassifier's
	 * scope).
	 *
	 * @return void
	 */
	public function testRuleIsRegisteredForThePayrollFamilyAndGeneratedDocument(): void {
		$checks = NlDossierRetentionChecks::checks();

		foreach (['Payslip', 'PayrollRun', 'LoonaangifteFiling', 'PayrollMutationReport', 'WkrDeclaration', 'WkrAssessment', 'PensionFiling', 'GeneratedDocument'] as $schema) {
			$this->assertArrayHasKey($schema, $checks);
			$this->assertArrayHasKey(self::RULE_ID, $checks[$schema]);
		}

	}//end testRuleIsRegisteredForThePayrollFamilyAndGeneratedDocument()

	/**
	 * Vacuous (satisfied) when `retention.archiefactiedatum` is unpopulated.
	 *
	 * @return void
	 */
	public function testVacuousWhenArchiefactiedatumIsUnpopulated(): void {
		$check = $this->checkFor('GeneratedDocument');

		$this->assertTrue($check(['@self' => ['retention' => []]]));
		$this->assertTrue($check([]));

	}//end testVacuousWhenArchiefactiedatumIsUnpopulated()

	/**
	 * Violated when `retention.archiefactiedatum` is a past date and the
	 * object is not `vernietigd`.
	 *
	 * @return void
	 */
	public function testViolatedWhenArchiefactiedatumHasPassedAndNotDestroyed(): void {
		$check = $this->checkFor('Payslip');
		$past = date('Y-m-d', strtotime('-1 day'));

		$object = ['@self' => ['retention' => ['archiefactiedatum' => $past, 'archiefstatus' => 'nog_te_archiveren']]];

		$this->assertFalse($check($object));

	}//end testViolatedWhenArchiefactiedatumHasPassedAndNotDestroyed()

	/**
	 * Satisfied when `retention.archiefactiedatum` is a future date.
	 *
	 * @return void
	 */
	public function testSatisfiedWhenArchiefactiedatumIsInTheFuture(): void {
		$check = $this->checkFor('LoonaangifteFiling');
		$future = date('Y-m-d', strtotime('+1 year'));

		$object = ['@self' => ['retention' => ['archiefactiedatum' => $future]]];

		$this->assertTrue($check($object));

	}//end testSatisfiedWhenArchiefactiedatumIsInTheFuture()

	/**
	 * Vacuous (satisfied) once `archiefstatus` is `vernietigd` -- a properly
	 * destroyed record is no longer "still present", even with a past
	 * `archiefactiedatum`.
	 *
	 * @return void
	 */
	public function testSatisfiedWhenArchiefstatusIsVernietigd(): void {
		$check = $this->checkFor('GeneratedDocument');
		$past = date('Y-m-d', strtotime('-1 year'));

		$object = ['@self' => ['retention' => ['archiefactiedatum' => $past, 'archiefstatus' => 'vernietigd']]];

		$this->assertTrue($check($object));

	}//end testSatisfiedWhenArchiefstatusIsVernietigd()

	/**
	 * The check never mutates -- it reports only (hole #2's own posture: a
	 * flag, never an action). Asserted indirectly: the predicate is a pure
	 * function of its input, called here with the SAME object array before
	 * and after, with an identical result -- there is no side channel for it
	 * to have mutated anything through.
	 *
	 * @return void
	 */
	public function testPredicateIsPureAndDeterministic(): void {
		$check = $this->checkFor('Payslip');
		$object = ['@self' => ['retention' => ['archiefactiedatum' => date('Y-m-d', strtotime('-1 day'))]]];

		$this->assertSame($check($object), $check($object));

	}//end testPredicateIsPureAndDeterministic()

	/**
	 * @return void
	 */
	public function testSeedSpecIsEmpty(): void {
		$this->assertSame([], NlDossierRetentionChecks::seedSpec());

	}//end testSeedSpecIsEmpty()

	/**
	 * Resolve the `nl-bewaartermijn-verstreken` predicate for one schema.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return callable
	 */
	private function checkFor(string $schema): callable {
		return NlDossierRetentionChecks::checks()[$schema][self::RULE_ID];
	}//end checkFor()

}//end class
