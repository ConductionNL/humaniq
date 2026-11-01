<?php

/**
 * Related Context Indexer
 *
 * Builds the cross-type `related` context of the rule audit: one index per
 * schema (PayrollRun, PensionFiling, Administration, Employee, contracts,
 * org units and placements, leave balances, assets and offboarding), so a
 * predicate can resolve a sibling object without re-querying the register.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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

namespace OCA\Humaniq\Service;

use Closure;

/**
 * Builds the rule audit's `related` indexes from the rows of each schema.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#requirement-the-engine-shall-evaluate-an-object-against-the-rules-that-apply-to-it-req-rule-002
 */
final class RelatedContextIndexer {

	/**
	 * @param Closure(string): array<int, array<string, mixed>> $loadAll Reads every row of one schema.
	 */
	public function __construct(
		private readonly Closure $loadAll,
	) {

	}//end __construct()

	/**
	 * The `related` context, one section per schema, read in a fixed order.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/offboarding-wizard/spec.md#REQ-OFB-004
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
	 * @spec openspec/specs/mss-team-scope/spec.md#REQ-MSS-005
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-007
	 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-003
	 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
	 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
	 */
	public function build(): array {
		return [
			'PayrollRun' => $this->payrollRuns(),
			'PensionFiling' => $this->pensionFilings(),
			'Administration' => $this->administrations(),
			'Employee' => $this->employees(),
			'EmploymentContract' => $this->contracts(),
			'OrgUnit' => $this->orgUnits(),
			'OrgAssignment' => $this->orgAssignments(),
			'LeaveBalance' => $this->leaveBalances(),
			'Asset' => $this->assets(),
			'AssetAssignment' => $this->assetAssignments(),
			'Offboarding' => $this->offboardings(),
		];
	}//end build()

	/**
	 * Every row of one schema.
	 *
	 * @param string $schema OpenRegister schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema): array {
		return ($this->loadAll)($schema);
	}//end rows()

	/**
	 * The PayrollRun index by id, and the periods of approved, posted or paid runs.
	 *
	 * @return array<string, mixed>
	 */
	private function payrollRuns(): array {
		$byId = [];
		$approvedPeriods = [];
		foreach ($this->rows('PayrollRun') as $run) {
			$id = (string)($run['id'] ?? $run['@self']['id'] ?? '');
			$period = (string)($run['period'] ?? '');
			$status = (string)($run['status'] ?? '');
			$administrationId = (string)($run['administrationId'] ?? '');

			if ($id !== '') {
				$byId[$id] = [
					'id' => $id,
					'period' => $period,
					'status' => $status,
					// abp-aansluiting (REQ-ABP-003): the run's own denormalized
					// administratie key, consumed by NlAbpChecks to resolve the
					// run's Administration and its own abpFiledPeriods entry.
					'administrationId' => $administrationId,
				];
			}

			if ($period !== '' && in_array($status, ['approved', 'posted', 'paid'], true) === true) {
				$approvedPeriods[$period] = true;
			}
		}

		return [
			'byId' => $byId,
			'approvedPeriods' => array_keys($approvedPeriods),
		];
	}//end payrollRuns()

	/**
	 * The filed pension periods, globally and per administration for ABP.
	 *
	 * @return array<string, mixed>
	 */
	private function pensionFilings(): array {
		$filedPeriods = [];
		$abpFiledPeriodsByAdministrationId = [];
		foreach ($this->rows('PensionFiling') as $filing) {
			$period = (string)($filing['period'] ?? '');
			if ($period !== '') {
				$filedPeriods[$period] = true;
			}

			// abp-aansluiting (REQ-ABP-003): a SECOND, narrower index alongside
			// the global filedPeriods set above -- fund AND tenant scoped,
			// unlike that set. Only `fund: "abp"` filings with both a period
			// and an administrationId contribute.
			$administrationId = (string)($filing['administrationId'] ?? '');
			if ($period !== '' && $administrationId !== '' && (string)($filing['fund'] ?? '') === 'abp') {
				$abpFiledPeriodsByAdministrationId[$administrationId][$period] = true;
			}
		}

		return [
			'filedPeriods' => array_keys($filedPeriods),
			// abp-aansluiting (REQ-ABP-003): administrationId -> [period => true].
			'abpFiledPeriodsByAdministrationId' => $abpFiledPeriodsByAdministrationId,
		];
	}//end pensionFilings()

	/**
	 * Whether each administration must join ABP.
	 *
	 * @return array<string, mixed>
	 */
	private function administrations(): array {
		// abp-aansluiting (REQ-ABP-003): an Administration index keyed on the
		// `administrationId` business key (not the object UUID -- the same
		// key every denormalized child field already uses), carrying only
		// the boolean NlAbpChecks needs to scope its predicate. Loaded once,
		// no per-object IO (the buildPayrollContext() precedent). Degrades to
		// an empty map when the Administration schema does not exist yet in
		// the register.
		$abpPlichtigByAdministrationId = [];
		foreach ($this->rows('hrAdministration') as $administration) {
			$administrationId = (string)($administration['administrationId'] ?? '');
			if ($administrationId === '') {
				continue;
			}

			$abpPlichtigByAdministrationId[$administrationId] = (bool)($administration['abpAansluitingsplichtig'] ?? false);
		}

		return [
			// abp-aansluiting (REQ-ABP-003): administrationId -> abpAansluitingsplichtig bool.
			'abpPlichtigByAdministrationId' => $abpPlichtigByAdministrationId,
		];
	}//end administrations()

	/**
	 * The Employee index by id.
	 *
	 * @return array<string, mixed>
	 */
	private function employees(): array {
		// onboarding-wizard-mvp: an Employee index (loonheffingenVerklaringOnFile +
		// startDate, keyed by id/slug — the Timesheet/Onboarding employeeId
		// reference mechanism) so NlOnboardingChecks::checks()['Onboarding']
		// ['nl-onboarding-loonheffingenverklaring'] can resolve the hire's Employee
		// without re-querying the register.
		$employeesById = [];
		foreach ($this->rows('Employee') as $employee) {
			$id = (string)($employee['id'] ?? $employee['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$employeesById[$id] = [
				'id' => $id,
				'loonheffingenVerklaringOnFile' => (bool)($employee['loonheffingenVerklaringOnFile'] ?? false),
				'startDate' => (string)($employee['startDate'] ?? ''),
				// offboarding-wizard-mvp: the case's endDate, consumed by
				// nl-offboarding-einddatum-consistentie (BW 7:667).
				'endDate' => (string)($employee['endDate'] ?? ''),
				// mss-team-scope: the employee's own Nextcloud account id
				// (when this Employee IS a manager), consumed by
				// NlOrgChecks::checks()['Timesheet'/'Expense'/'LeaveRequest']
				// ['nl-mss-manager-consistency'] to resolve a manager
				// Employee's account for comparison against a record's
				// stamped managerUserId.
				'nextcloudUserId' => (string)($employee['nextcloudUserId'] ?? ''),
				// multi-administratie: the employee's own denormalized
				// administratie key, consumed by
				// NlAdministratieChecks::checks() (every Employee-anchored
				// schema this change denormalizes onto)
				// ['nl-administratie-scope-consistency'] to resolve the
				// parent employee's expected administrationId.
				'administrationId' => (string)($employee['administrationId'] ?? ''),
				// wnt-disclosure: the topfunctionaris's recorded
				// transitional-exemption ground (overgangsrecht |
				// ontheffing-minister | null), consumed by
				// NlWntChecks::checks()['WntDisclosure']
				// ['nl-wnt-norm-overschrijding'] -- a non-null value clears the
				// norm-overschrijding flag (design.md D4/D5).
				'wntTopfunctionaris' => (bool)($employee['wntTopfunctionaris'] ?? false),
				'wntUitzonderingReden' => (($employee['wntUitzonderingReden'] ?? null) === null) ? null : (string)$employee['wntUitzonderingReden'],
			];
		}

		return ['byId' => $employeesById];
	}//end employees()

	/**
	 * The EmploymentContract index by employee id.
	 *
	 * @return array<string, mixed>
	 */
	private function contracts(): array {
		// onboarding-wizard-mvp: an EmploymentContract index keyed by employeeId
		// (the contract type/startDate/endDate that resolves for a given hire) so
		// nl-onboarding-proeftijd-bewaking can apply the BW 7:652 contract-type cap.
		// When more than one contract resolves for the same employeeId, the last
		// one loaded wins (MVP simplification — contracts are optional data today,
		// design.md D3); tightening to "most recent by startDate" is a check-only
		// change once contract history matters for this predicate.
		$contractsByEmployeeId = [];
		foreach ($this->rows('EmploymentContract') as $contract) {
			$employeeId = (string)($contract['employeeId'] ?? '');
			if ($employeeId === '') {
				continue;
			}

			$contractsByEmployeeId[$employeeId] = [
				'type' => (string)($contract['type'] ?? ''),
				'startDate' => (string)($contract['startDate'] ?? ''),
				'endDate' => (string)($contract['endDate'] ?? ''),
			];
		}

		return ['byEmployeeId' => $contractsByEmployeeId];
	}//end contracts()

	/**
	 * The OrgUnit index by id.
	 *
	 * @return array<string, mixed>
	 */
	private function orgUnits(): array {
		// org-chart-basic: an OrgUnit index (id, parentUnitId, active), keyed by
		// id, so NlOrgChecks::checks()['OrgAssignment']['nl-org-assignment-consistency']
		// can resolve an assignment's unit without re-querying the register, and
		// ['OrgUnit']['nl-org-unit-cycle'] can walk the parentUnitId chain through
		// the same index.
		$orgUnitsById = [];
		foreach ($this->rows('OrgUnit') as $unit) {
			$id = (string)($unit['id'] ?? $unit['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$orgUnitsById[$id] = [
				'id' => $id,
				'parentUnitId' => (string)($unit['parentUnitId'] ?? ''),
				'active' => (bool)($unit['active'] ?? true),
				// mss-team-scope: the unit's manager (Employee UUID),
				// consumed by NlOrgChecks::checks()['Timesheet'/'Expense'/
				// 'LeaveRequest']['nl-mss-manager-consistency'] to resolve
				// the manager for an active placement's unit.
				'managerId' => (string)($unit['managerId'] ?? ''),
			];
		}

		return ['byId' => $orgUnitsById];
	}//end orgUnits()

	/**
	 * The OrgAssignment placements by employee id.
	 *
	 * @return array<string, mixed>
	 */
	private function orgAssignments(): array {
		// mss-team-scope: an OrgAssignment index keyed by employeeId (a list
		// of {orgUnitId, endDate} placements, start dates irrelevant to
		// activeness-at-audit — the predicate applies the endDate rule
		// itself) so NlOrgChecks::checks()['Timesheet'/'Expense'/
		// 'LeaveRequest']['nl-mss-manager-consistency'] can resolve a
		// record's employee's active placements without re-querying the
		// register.
		$orgAssignmentsByEmployeeId = [];
		foreach ($this->rows('OrgAssignment') as $assignment) {
			$employeeId = trim((string)($assignment['employeeId'] ?? ''));
			if ($employeeId === '') {
				continue;
			}

			$orgAssignmentsByEmployeeId[$employeeId][] = [
				'orgUnitId' => (string)($assignment['orgUnitId'] ?? ''),
				'endDate' => (string)($assignment['endDate'] ?? ''),
			];
		}

		return ['byEmployeeId' => $orgAssignmentsByEmployeeId];
	}//end orgAssignments()

	/**
	 * The LeaveBalance rows by employee id.
	 *
	 * @return array<string, mixed>
	 */
	private function leaveBalances(): array {
		// offboarding-wizard-mvp: a LeaveBalance index keyed by employeeId (the
		// list of {leaveType, year, entitledHours, bovenwettelijkHours,
		// usedHours} rows) so nl-offboarding-verlofsaldo-uitbetaling can sum
		// each employee's open leave balance without re-querying the register.
		$leaveBalancesByEmployeeId = [];
		foreach ($this->rows('LeaveBalance') as $balance) {
			$employeeId = (string)($balance['employeeId'] ?? '');
			if ($employeeId === '') {
				continue;
			}

			$leaveBalancesByEmployeeId[$employeeId][] = [
				'leaveType' => (string)($balance['leaveType'] ?? ''),
				'year' => (int)($balance['year'] ?? 0),
				'entitledHours' => (float)($balance['entitledHours'] ?? 0),
				'bovenwettelijkHours' => (float)($balance['bovenwettelijkHours'] ?? 0),
				'usedHours' => (float)($balance['usedHours'] ?? 0),
			];
		}

		return ['byEmployeeId' => $leaveBalancesByEmployeeId];
	}//end leaveBalances()

	/**
	 * The Asset index by id.
	 *
	 * @return array<string, mixed>
	 */
	private function assets(): array {
		// asset-management-mvp: an Asset index (id, status, active), keyed by
		// id, so NlAssetChecks::checks()['AssetAssignment']
		// ['nl-asset-assignment-consistency'] can resolve an open assignment's
		// asset status without re-querying the register. humaniq-asset-fleet-merge
		// (design.md D4) extends it with category/listPrice/fuelType/
		// companyCarTaxCategory -- the fields NlFleetChecks::checks()['Payslip']
		// ['nl-bijtelling-auto-privegebruik'] and ['Asset']
		// ['nl-asset-voertuig-fiscale-velden-compleet'] need -- so the
		// dedicated `context['fleet']['vehiclesById']` index this ONE index
		// used to duplicate (`buildFleetContext()`) is no longer needed.
		$assetsById = [];
		foreach ($this->rows('Asset') as $asset) {
			$id = (string)($asset['id'] ?? $asset['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$assetsById[$id] = [
				'id' => $id,
				'status' => (string)($asset['status'] ?? ''),
				'active' => (bool)($asset['active'] ?? true),
				'category' => (string)($asset['category'] ?? ''),
				'listPrice' => ($asset['listPrice'] ?? null),
				'fuelType' => ($asset['fuelType'] ?? null),
				'companyCarTaxCategory' => ($asset['companyCarTaxCategory'] ?? null),
			];
		}

		return ['byId' => $assetsById];
	}//end assets()

	/**
	 * The AssetAssignment index by id.
	 *
	 * @return array<string, mixed>
	 */
	private function assetAssignments(): array {
		// humaniq-asset-fleet-merge (design.md D4): an AssetAssignment index
		// (id, assetId, employeeId, issuedOn, returnedOn, employeeContribution),
		// keyed by id -- the counterpart to `$assetsById` above, consumed by
		// NlFleetChecks::checks()['Payslip']['nl-bijtelling-auto-privegebruik']
		// to resolve a Payslip's `assetAssignmentId` -> AssetAssignment ->
		// `assetId` -> Asset chain without re-querying the register. Replaces
		// the dedicated `context['fleet']['carAssignmentsById']` index
		// `buildFleetContext()` used to build.
		$assetAssignmentsById = [];
		foreach ($this->rows('AssetAssignment') as $assignment) {
			$id = (string)($assignment['id'] ?? $assignment['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$assetAssignmentsById[$id] = [
				'id' => $id,
				'assetId' => (string)($assignment['assetId'] ?? ''),
				'employeeId' => (string)($assignment['employeeId'] ?? ''),
				'issuedOn' => (string)($assignment['issuedOn'] ?? ''),
				'returnedOn' => ($assignment['returnedOn'] ?? null),
				'employeeContribution' => ($assignment['employeeContribution'] ?? 0),
			];
		}

		return ['byId' => $assetAssignmentsById];
	}//end assetAssignments()

	/**
	 * The latest planned last working day per employee, over non-cancelled Offboarding cases.
	 *
	 * @return array<string, mixed>
	 */
	private function offboardings(): array {
		// asset-management-mvp (design.md D3): a defensive Offboarding
		// plannedCompletionByEmployeeId index -- the latest `lastWorkingDay`
		// among an employee's non-cancelled (status !== geannuleerd)
		// Offboarding cases, keyed by employeeId -- consumed by
		// NlAssetChecks::checks()['AssetAssignment']
		// ['nl-asset-inname-bij-offboarding']. Entries with a missing
		// employeeId/lastWorkingDay, or an unparseable date, are skipped
		// (skipping degrades to vacuous pass, never to a false violation).
		// Degrades to an empty map when the Offboarding schema does not exist
		// yet in the register -- the two changes land in either order.
		$plannedCompletionByEmployeeId = [];
		foreach ($this->rows('Offboarding') as $offboarding) {
			if ((string)($offboarding['status'] ?? '') === 'geannuleerd') {
				continue;
			}

			$employeeId = trim((string)($offboarding['employeeId'] ?? ''));
			$lastWorkingDay = trim((string)($offboarding['lastWorkingDay'] ?? ''));
			if ($employeeId === '' || $lastWorkingDay === '') {
				continue;
			}

			$candidate = strtotime($lastWorkingDay);
			if ($candidate === false) {
				continue;
			}

			$current = ($plannedCompletionByEmployeeId[$employeeId] ?? null);
			if ($current === null || strtotime($current) === false || $candidate > strtotime($current)) {
				$plannedCompletionByEmployeeId[$employeeId] = $lastWorkingDay;
			}
		}

		return ['plannedCompletionByEmployeeId' => $plannedCompletionByEmployeeId];
	}//end offboardings()

}//end class
