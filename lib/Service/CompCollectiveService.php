<?php

/**
 * Comp Collective Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Proposes and approves many compensation adjustments at once from one
 * CompReviewCycle (comp-collective-raise-and-step-increase design.md D2, D3,
 * D5). A collective cycle carries one raise, a percentage or an amount, and a
 * scope; a step-increase cycle moves every due contract one step up its
 * SalaryBand. Either way the result is ordinary `proposed` CompAdjustments,
 * so everything after that is the per-adjustment lifecycle comp-cycles built:
 * approval stays four eyes per adjustment and effectuation stays
 * `CompAdjustmentService`.
 *
 * Proposals are idempotent per (cycle, employee): a second run creates
 * nothing. A dry run reports what it would create and writes nothing.
 * Approval walks the cycle's proposed adjustments and drives each through
 * OpenRegister's own `approve` transition (CompCycleApprover), so
 * NoSelfApprovalGuard runs per adjustment and the declared approval
 * notification fires.
 *
 * Salaries on CompAdjustment are integer cents; `Employee.grossMonthlySalary`
 * and `EmploymentContract.hourlyWage` are euros (see CompAdjustmentService).
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Log\LoggerInterface;

/**
 * Bulk proposal and bulk approval for a compensation cycle.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */
class CompCollectiveService {

	/**
	 * How many proposal rows a response lists; the counts always cover all.
	 *
	 * @var int
	 */
	public const PREVIEW_ROWS = 50;

	/**
	 * @param RecordStoreInterface $gateway Register reads and writes, unscoped (the caller is authorised by the controller).
	 * @param LoggerInterface $logger Logger.
	 * @param CompProposalBuilder $builder The pure proposal arithmetic.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly LoggerInterface $logger,
		private readonly CompProposalBuilder $builder = new CompProposalBuilder(),
	) {

	}//end __construct()

	/**
	 * Propose one adjustment per employee the cycle covers.
	 *
	 * @param string $cycleId The CompReviewCycle id.
	 * @param string $callerUid Who proposes; stamped as proposedBy.
	 * @param bool $dryRun Count and list, write nothing.
	 * @param array<int, string> $employeeIds A hand-picked selection that replaces the cycle's scope; empty uses the scope.
	 *
	 * @return array<string, mixed> The outcome: counts, skipped employees with a reason, and the first proposal rows.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	public function proposeForCycle(string $cycleId, string $callerUid, bool $dryRun = false, array $employeeIds = []): array {
		$cycle = $this->gateway->findObjectData($cycleId, 'CompReviewCycle');
		if ($cycle === null) {
			return $this->refusal('refused-cycle-not-found', 'Beloningsronde niet gevonden.');
		}

		$refusal = $this->cycleRefusal($cycle);
		if ($refusal !== null) {
			return $refusal;
		}

		$run = new CompProposalRun();
		$run->cycleId = $cycleId;
		$run->cycle = $cycle;
		$run->callerUid = $callerUid;
		$run->dryRun = $dryRun;
		$run->existing = $this->existingEmployeeIds(cycleId: $cycleId);
		$run->selection = array_values(array_filter(array_map('strval', $employeeIds), static fn (string $id): bool => trim($id) !== ''));
		$run->outcome = self::emptyOutcome(run: $run);
		$run->contracts = $this->contractsByEmployee();
		foreach ($this->candidates(run: $run) as $employee) {
			$this->proposeFor(run: $run, employee: $employee);
		}

		return $run->outcome;
	}//end proposeForCycle()

	/**
	 * The employees that already have an adjustment in the cycle, as keys.
	 *
	 * @param string $cycleId The CompReviewCycle id.
	 *
	 * @return array<string, bool>
	 */
	private function existingEmployeeIds(string $cycleId): array {
		$existing = [];
		foreach ($this->gateway->findFiltered('CompAdjustment', ['cycleId' => $cycleId]) as $adjustment) {
			$existing[(string)($adjustment['employeeId'] ?? '')] = true;
		}

		return $existing;
	}//end existingEmployeeIds()

	/**
	 * The outcome before any employee is proposed for.
	 *
	 * @param CompProposalRun $run The run.
	 *
	 * @return array<string, mixed>
	 */
	private static function emptyOutcome(CompProposalRun $run): array {
		return [
			'status' => 'ok',
			'cycleId' => $run->cycleId,
			'kind' => (string)$run->cycle['kind'],
			'dryRun' => $run->dryRun,
			'created' => 0,
			'wouldCreate' => 0,
			'alreadyPresent' => 0,
			'failed' => 0,
			'skipped' => [],
			'rows' => [],
		];
	}//end emptyOutcome()

	/**
	 * Propose for one candidate: counted when already present, skipped with
	 * a reason, or recorded.
	 *
	 * @param CompProposalRun      $run      The run.
	 * @param array<string, mixed> $employee The candidate.
	 *
	 * @return void
	 */
	private function proposeFor(CompProposalRun $run, array $employee): void {
		$employeeId = (string)$employee['id'];
		if (isset($run->existing[$employeeId]) === true) {
			$run->outcome['alreadyPresent']++;
			return;
		}

		$proposal = $this->buildProposal(run: $run, employee: $employee);
		if (isset($proposal['skip']) === true) {
			$run->outcome['skipped'][] = ['employeeId' => $employeeId, 'name' => $this->nameOf($employee), 'reason' => $proposal['skip']];
			return;
		}

		$this->record(run: $run, proposal: new CompProposal(employee: $employee, payload: $proposal));
	}//end proposeFor()

	/**
	 * Why a cycle cannot take bulk proposals, or null when it can.
	 *
	 * @param array<string, mixed> $cycle The CompReviewCycle.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cycleRefusal(array $cycle): ?array {
		if ((string)($cycle['status'] ?? '') !== 'open') {
			return $this->refusal('refused-cycle-closed', 'Deze beloningsronde is gesloten.');
		}

		$kind = (string)($cycle['kind'] ?? 'individual');
		if (in_array($kind, ['collective', 'step-increase'], true) === false) {
			return $this->refusal('refused-not-collective', 'Deze ronde is voor losse voorstellen. Kies een collectieve ronde of een ronde voor periodieken.');
		}

		if ($kind === 'collective' && $this->builder->raiseOf($cycle) === null) {
			return $this->refusal('refused-no-raise', 'Deze collectieve ronde heeft geen percentage of bedrag.');
		}

		if (trim((string)($cycle['effectiveDate'] ?? '')) === '') {
			return $this->refusal('refused-no-effective-date', 'Deze ronde heeft geen ingangsdatum.');
		}

		return null;
	}//end cycleRefusal()

	/**
	 * The employees a run covers: the hand-picked selection, or everyone the
	 * cycle's scope matches.
	 *
	 * @param CompProposalRun $run The cycle, the selection and the contracts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function candidates(CompProposalRun $run): array {
		$employees = $this->gateway->loadAll('Employee');
		if ($run->selection !== []) {
			return array_values(array_filter($employees, static fn (array $e): bool => in_array((string)($e['id'] ?? ''), $run->selection, true)));
		}

		$scope = $this->scopeOf(run: $run);

		return array_values(array_filter($employees, fn (array $employee): bool => $this->inScope(employee: $employee, scope: $scope)));
	}//end candidates()

	/**
	 * The cycle's scope as the candidates are matched on it.
	 *
	 * @param CompProposalRun $run The cycle and the contracts.
	 *
	 * @return CompScope
	 */
	private function scopeOf(CompProposalRun $run): CompScope {
		$keys = (is_array($run->cycle['scope'] ?? null) === true ? $run->cycle['scope'] : []);
		$scope = new CompScope();
		$scope->keys = array_map(static fn ($value): string => trim((string)$value), $keys);
		$scope->onDate = (string)$run->cycle['effectiveDate'];
		$orgUnitId = ($scope->keys['orgUnitId'] ?? '');
		$scope->placed = ($orgUnitId === '' ? null : $this->placedIn($orgUnitId, $scope->onDate));
		$scope->contracts = $run->contracts;

		return $scope;
	}//end scopeOf()

	/**
	 * Whether one employee matches every scope key that is set.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param CompScope            $scope    The cycle's scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 */
	private function inScope(array $employee, CompScope $scope): bool {
		$administrationId = ($scope->keys['administrationId'] ?? '');
		if ($administrationId !== '' && trim((string)($employee['administrationId'] ?? '')) !== $administrationId) {
			return false;
		}

		if ($scope->placed !== null && isset($scope->placed[(string)($employee['id'] ?? '')]) === false) {
			return false;
		}

		return $this->coveredByCao(employee: $employee, scope: $scope);
	}//end inScope()

	/**
	 * Whether the employee's contract on the effective date has the scope's
	 * CAO; true when the scope names none.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param CompScope            $scope    The cycle's scope.
	 *
	 * @return bool
	 */
	private function coveredByCao(array $employee, CompScope $scope): bool {
		$cao = ($scope->keys['cao'] ?? '');
		if ($cao === '') {
			return true;
		}

		$covering = $this->builder->covering(($scope->contracts[(string)($employee['id'] ?? '')] ?? []), $scope->onDate);
		return $covering !== null && trim((string)($covering['cao'] ?? '')) === $cao;
	}//end coveredByCao()

	/**
	 * The proposal for one employee, or ['skip' => reason].
	 *
	 * @param CompProposalRun      $run      The cycle, who proposes and the contracts.
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return array<string, mixed>
	 */
	private function buildProposal(CompProposalRun $run, array $employee): array {
		$cycle = $run->cycle;
		$contracts = ($run->contracts[(string)$employee['id']] ?? []);
		$gross = ($employee['grossMonthlySalary'] ?? null);
		$current = (is_numeric($gross) === true ? (int)round(((float)$gross) * 100) : null);

		$isStep = ((string)$cycle['kind'] === 'step-increase');
		$proposal = ($isStep === true ? $this->stepFor($cycle, $contracts) : $this->builder->raiseProposal($cycle, $contracts, $current));

		if (isset($proposal['skip']) === true) {
			return $proposal;
		}

		$userId = trim((string)($employee['nextcloudUserId'] ?? ''));
		return array_merge(
			[
				'cycleId' => (string)$cycle['id'],
				'employeeId' => (string)$employee['id'],
				'currentSalary' => $current,
				'status' => 'proposed',
				'proposedBy' => $run->callerUid,
				'employeeUserId' => ($userId === '' ? null : $userId),
			],
			$proposal
		);
	}//end buildProposal()

	/**
	 * The step proposal for an employee's contracts, or ['skip' => reason].
	 *
	 * @param array<string, mixed> $cycle The step-increase cycle.
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	private function stepFor(array $cycle, array $contracts): array {
		$contract = $this->builder->steppedContract($contracts);
		if ($contract === null) {
			return ['skip' => 'no-band-step'];
		}

		if ($this->builder->stepDue($cycle, $contract) === false) {
			return ['skip' => 'step-not-due'];
		}

		return $this->builder->stepProposal($contract, $this->gateway->findObjectData((string)$contract['salaryBandId'], 'SalaryBand'));
	}//end stepFor()

	/**
	 * Save (or, on a dry run, count) one proposal and add it to the outcome;
	 * a save that fails is counted and named.
	 *
	 * @param CompProposalRun $run      The run.
	 * @param CompProposal    $proposal The employee and the CompAdjustment payload.
	 *
	 * @return void
	 */
	private function record(CompProposalRun $run, CompProposal $proposal): void {
		if ($run->dryRun === false) {
			try {
				$this->gateway->save($proposal->payload, 'CompAdjustment');
			} catch (\Throwable $e) {
				$this->logger->warning('CompCollectiveService: voorstel voor ' . (string)$proposal->employee['id'] . ' niet opgeslagen: ' . $e->getMessage());
				$run->outcome['failed']++;
				$run->outcome['skipped'][] = ['employeeId' => (string)$proposal->employee['id'], 'name' => $this->nameOf($proposal->employee), 'reason' => 'failed'];
				return;
			}

			$run->outcome['created']++;
		}

		$run->outcome['wouldCreate']++;
		if (count($run->outcome['rows']) < self::PREVIEW_ROWS) {
			$run->outcome['rows'][] = $this->previewRow(proposal: $proposal);
		}
	}//end record()

	/**
	 * The preview row of one proposal.
	 *
	 * @param CompProposal $proposal The employee and the CompAdjustment payload.
	 *
	 * @return array<string, mixed>
	 */
	private function previewRow(CompProposal $proposal): array {
		return [
			'employeeId' => (string)$proposal->employee['id'],
			'name' => $this->nameOf($proposal->employee),
			'currentSalary' => $proposal->payload['currentSalary'],
			'proposedSalary' => $proposal->payload['proposedSalary'],
			'fromStep' => ($proposal->payload['fromStep'] ?? null),
			'toStep' => ($proposal->payload['toStep'] ?? null),
			'effectiveDate' => $proposal->payload['effectiveDate'],
		];
	}//end previewRow()

	/**
	 * Contracts grouped by employee id.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function contractsByEmployee(): array {
		$out = [];
		foreach ($this->gateway->loadAll('EmploymentContract') as $contract) {
			$out[(string)($contract['employeeId'] ?? '')][] = $contract;
		}

		return $out;
	}//end contractsByEmployee()

	/**
	 * Employee ids with an OrgAssignment to a unit covering a date.
	 *
	 * @param string $orgUnitId The unit.
	 * @param string $onDate ISO date.
	 *
	 * @return array<string, true>
	 */
	private function placedIn(string $orgUnitId, string $onDate): array {
		$placed = [];
		foreach ($this->gateway->findFiltered('OrgAssignment', ['orgUnitId' => $orgUnitId]) as $assignment) {
			if ($this->builder->covering([$assignment], $onDate) !== null) {
				$placed[(string)($assignment['employeeId'] ?? '')] = true;
			}
		}

		return $placed;
	}//end placedIn()

	/**
	 * A readable name for a preview row.
	 *
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return string
	 */
	private function nameOf(array $employee): string {
		return trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
	}//end nameOf()

	/**
	 * A refusal outcome.
	 *
	 * @param string $status The refusal status.
	 * @param string $message Why.
	 *
	 * @return array<string, mixed>
	 */
	private function refusal(string $status, string $message): array {
		return ['status' => $status, 'message' => $message];
	}//end refusal()

}//end class
