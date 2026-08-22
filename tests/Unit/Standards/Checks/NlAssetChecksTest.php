<?php

/**
 * Unit tests for the NL asset-management checks.
 *
 * Pins the two asset-management-mvp predicates: assignment consistency
 * (nl-asset-assignment-consistency, cross-object via the context's Asset and
 * Employee indexes) and offboarding asset return
 * (nl-asset-inname-bij-offboarding, cross-object via the context's Offboarding
 * plannedCompletionByEmployeeId index, including the vacuous pass when the
 * parallel offboarding-wizard-mvp change has not landed).
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
 * @spec openspec/changes/asset-management-mvp/specs/asset-management/spec.md#REQ-AST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlAssetChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlAssetChecks.
 *
 * @spec openspec/changes/asset-management-mvp/specs/asset-management/spec.md#REQ-AST-005
 */
class NlAssetChecksTest extends TestCase {

	/**
	 * The registered AssetAssignment predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlAssetChecks::checks()['AssetAssignment'];

	}//end setUp()

	/**
	 * A minimal AssetAssignment fixture; each test overrides the fields it
	 * exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function assignment(array $overrides = []): array {
		return array_merge(
			[
				'assetId' => 'asset-laptop',
				'employeeId' => 'employee-jansen',
				'issuedOn' => '2026-06-15',
				'returnedOn' => null,
				'issueReceiptSigned' => false,
				'notes' => null,
			],
			$overrides
		);

	}//end assignment()

	/**
	 * A minimal `context['related']` fixture matching RuleAuditService's
	 * pre-pass shape.
	 *
	 * @param array<string, array<string, mixed>> $assetsById Asset index by id.
	 * @param array<string, array<string, mixed>> $employeesById Employee index by id.
	 * @param array<string, string> $plannedCompletionByEmployeeId Offboarding plannedCompletionByEmployeeId index.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $assetsById = [], array $employeesById = [], array $plannedCompletionByEmployeeId = []): array {
		return [
			'related' => [
				'Asset' => ['byId' => $assetsById],
				'Employee' => ['byId' => $employeesById],
				'Offboarding' => ['plannedCompletionByEmployeeId' => $plannedCompletionByEmployeeId],
			],
		];

	}//end context()

	// -- nl-asset-assignment-consistency --------------------------------------

	/**
	 * @return void
	 */
	public function testOpenAssignmentOnIssuedAssetWithKnownEmployeeSatisfied(): void {
		$assignment = $this->assignment(['assetId' => 'asset-laptop', 'returnedOn' => null]);
		$context = $this->context(
			['asset-laptop' => ['id' => 'asset-laptop', 'status' => 'issued', 'active' => true]],
			['employee-jansen' => ['id' => 'employee-jansen']]
		);

		$this->assertTrue(($this->checks['nl-asset-assignment-consistency'])($assignment, $context));

	}//end testOpenAssignmentOnIssuedAssetWithKnownEmployeeSatisfied()

	/**
	 * @return void
	 */
	public function testIncoherentDatesViolate(): void {
		$assignment = $this->assignment(['issuedOn' => '2026-06-01', 'returnedOn' => '2026-05-01']);

		$this->assertFalse(($this->checks['nl-asset-assignment-consistency'])($assignment, $this->context()));

	}//end testIncoherentDatesViolate()

	/**
	 * @return void
	 */
	public function testSameDayUitgifteAndInnameIsCoherentAndSatisfied(): void {
		$assignment = $this->assignment(['issuedOn' => '2026-06-15', 'returnedOn' => '2026-06-15']);

		$this->assertTrue(($this->checks['nl-asset-assignment-consistency'])($assignment, $this->context()));

	}//end testSameDayUitgifteAndInnameIsCoherentAndSatisfied()

	/**
	 * @return void
	 */
	public function testOpenAssignmentOnBeschikbaarAssetViolates(): void {
		$assignment = $this->assignment(['assetId' => 'asset-telefoon', 'returnedOn' => null]);
		$context = $this->context(
			['asset-telefoon' => ['id' => 'asset-telefoon', 'status' => 'available', 'active' => true]],
			['employee-jansen' => ['id' => 'employee-jansen']]
		);

		$this->assertFalse(($this->checks['nl-asset-assignment-consistency'])($assignment, $context));

	}//end testOpenAssignmentOnBeschikbaarAssetViolates()

	/**
	 * @return void
	 */
	public function testOpenAssignmentOnIngenomenAssetViolates(): void {
		$assignment = $this->assignment(['assetId' => 'asset-bus', 'returnedOn' => null]);
		$context = $this->context(
			['asset-bus' => ['id' => 'asset-bus', 'status' => 'checkedIn', 'active' => true]],
			['employee-jansen' => ['id' => 'employee-jansen']]
		);

		$this->assertFalse(($this->checks['nl-asset-assignment-consistency'])($assignment, $context));

	}//end testOpenAssignmentOnIngenomenAssetViolates()

	/**
	 * @return void
	 */
	public function testClosedAssignmentOnNonIssuedAssetIsSatisfied(): void {
		// Historical uitgiftes may reference a re-stocked or written-off asset
		// -- the consistency rule never re-checks a closed record's asset
		// status.
		$assignment = $this->assignment(['assetId' => 'asset-bus', 'issuedOn' => '2025-01-06', 'returnedOn' => '2025-12-19']);
		$context = $this->context(
			['asset-bus' => ['id' => 'asset-bus', 'status' => 'available', 'active' => true]],
			['employee-jansen' => ['id' => 'employee-jansen']]
		);

		$this->assertTrue(($this->checks['nl-asset-assignment-consistency'])($assignment, $context));

	}//end testClosedAssignmentOnNonIssuedAssetIsSatisfied()

	/**
	 * @return void
	 */
	public function testDanglingAssetReferenceFailsClosed(): void {
		$assignment = $this->assignment(['assetId' => 'no-such-asset', 'returnedOn' => null]);
		$context = $this->context([], ['employee-jansen' => ['id' => 'employee-jansen']]);

		$this->assertFalse(($this->checks['nl-asset-assignment-consistency'])($assignment, $context));

	}//end testDanglingAssetReferenceFailsClosed()

	/**
	 * @return void
	 */
	public function testEmptyAssetReferenceFailsClosed(): void {
		$assignment = $this->assignment(['assetId' => '', 'returnedOn' => null]);

		$this->assertFalse(($this->checks['nl-asset-assignment-consistency'])($assignment, $this->context()));

	}//end testEmptyAssetReferenceFailsClosed()

	/**
	 * @return void
	 */
	public function testDanglingEmployeeReferenceFailsClosed(): void {
		$assignment = $this->assignment(['assetId' => 'asset-laptop', 'employeeId' => 'no-such-employee', 'returnedOn' => null]);
		$context = $this->context(['asset-laptop' => ['id' => 'asset-laptop', 'status' => 'issued', 'active' => true]]);

		$this->assertFalse(($this->checks['nl-asset-assignment-consistency'])($assignment, $context));

	}//end testDanglingEmployeeReferenceFailsClosed()

	/**
	 * @return void
	 */
	public function testOpenAssignmentWithEmptyContextIndexFailsClosed(): void {
		$assignment = $this->assignment(['assetId' => 'asset-laptop', 'returnedOn' => null]);

		// No `related.Asset` index at all (schema not yet imported) -- the
		// asset-status lookup degrades to empty, and an open assignment that
		// cannot resolve its asset fails closed.
		$this->assertFalse(($this->checks['nl-asset-assignment-consistency'])($assignment, []));

	}//end testOpenAssignmentWithEmptyContextIndexFailsClosed()

	// -- nl-asset-inname-bij-offboarding ---------------------------------------

	/**
	 * @return void
	 */
	public function testOpenAssignmentPassesVacuouslyWithoutOffboardingObjects(): void {
		$assignment = $this->assignment(['returnedOn' => null]);

		// Empty related.Offboarding index (the parallel offboarding-wizard-mvp
		// change not yet landed) -- vacuous pass.
		$this->assertTrue(($this->checks['nl-asset-inname-bij-offboarding'])($assignment, $this->context()));

	}//end testOpenAssignmentPassesVacuouslyWithoutOffboardingObjects()

	/**
	 * @return void
	 */
	public function testOpenAssignmentPassesVacuouslyWithEmptyContextAltogether(): void {
		$assignment = $this->assignment(['returnedOn' => null]);

		$this->assertTrue(($this->checks['nl-asset-inname-bij-offboarding'])($assignment, []));

	}//end testOpenAssignmentPassesVacuouslyWithEmptyContextAltogether()

	/**
	 * @return void
	 */
	public function testOpenAssignmentPastOverdueOffboardingCompletionViolates(): void {
		$assignment = $this->assignment(['employeeId' => 'employee-jansen', 'returnedOn' => null]);
		$context = $this->context([], [], ['employee-jansen' => date('Y-m-d', strtotime('-1 day'))]);

		$this->assertFalse(($this->checks['nl-asset-inname-bij-offboarding'])($assignment, $context));

	}//end testOpenAssignmentPastOverdueOffboardingCompletionViolates()

	/**
	 * @return void
	 */
	public function testOpenAssignmentWithFutureOffboardingCompletionSatisfied(): void {
		$assignment = $this->assignment(['employeeId' => 'employee-jansen', 'returnedOn' => null]);
		$context = $this->context([], [], ['employee-jansen' => date('Y-m-d', strtotime('+30 days'))]);

		$this->assertTrue(($this->checks['nl-asset-inname-bij-offboarding'])($assignment, $context));

	}//end testOpenAssignmentWithFutureOffboardingCompletionSatisfied()

	/**
	 * @return void
	 */
	public function testOpenAssignmentWithNoMatchingEmployeeEntrySatisfied(): void {
		$assignment = $this->assignment(['employeeId' => 'employee-visser', 'returnedOn' => null]);
		$context = $this->context([], [], ['employee-jansen' => date('Y-m-d', strtotime('-1 day'))]);

		$this->assertTrue(($this->checks['nl-asset-inname-bij-offboarding'])($assignment, $context));

	}//end testOpenAssignmentWithNoMatchingEmployeeEntrySatisfied()

	/**
	 * @return void
	 */
	public function testClosedAssignmentNeverChecksOffboardingCompletion(): void {
		$assignment = $this->assignment(['employeeId' => 'employee-jansen', 'returnedOn' => '2026-06-20']);
		$context = $this->context([], [], ['employee-jansen' => date('Y-m-d', strtotime('-1 day'))]);

		$this->assertTrue(($this->checks['nl-asset-inname-bij-offboarding'])($assignment, $context));

	}//end testClosedAssignmentNeverChecksOffboardingCompletion()

}//end class
