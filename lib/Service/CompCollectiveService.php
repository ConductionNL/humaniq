<?php

/**
 * Comp Collective Service
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
 * OpenRegister's own `approve` transition, so NoSelfApprovalGuard runs per
 * adjustment and the declared approval notification fires; the caller's own
 * proposals, and their own raise, are skipped and reported.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
	 * OpenRegister's transition engine, resolved lazily.
	 *
	 * @var string
	 */
	private const TRANSITION_ENGINE = 'OCA\OpenRegister\Service\Lifecycle\TransitionEngine';

	/**
	 * @param HoursRegisterGateway $gateway Register reads and writes, unscoped (the caller is authorised by the controller).
	 * @param ContainerInterface $container Resolves OpenRegister's TransitionEngine.
	 * @param SettingsService $settingsService OpenRegister availability.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
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

		$kind = (string)$cycle['kind'];
		$existing = [];
		foreach ($this->gateway->findFiltered('CompAdjustment', ['cycleId' => $cycleId]) as $adjustment) {
			$existing[(string)($adjustment['employeeId'] ?? '')] = true;
		}

		$selection = array_values(array_filter(array_map('strval', $employeeIds), static fn (string $id): bool => trim($id) !== ''));
		$outcome = [
			'status' => 'ok',
			'cycleId' => $cycleId,
			'kind' => $kind,
			'dryRun' => $dryRun,
			'created' => 0,
			'wouldCreate' => 0,
			'alreadyPresent' => 0,
			'failed' => 0,
			'skipped' => [],
			'rows' => [],
		];

		$contracts = $this->contractsByEmployee();
		foreach ($this->candidates($cycle, $selection, $contracts) as $employee) {
			$employeeId = (string)$employee['id'];
			if (isset($existing[$employeeId]) === true) {
				$outcome['alreadyPresent']++;
				continue;
			}

			$proposal = $this->buildProposal($cycle, $employee, ($contracts[$employeeId] ?? []), $callerUid);
			if (isset($proposal['skip']) === true) {
				$outcome['skipped'][] = ['employeeId' => $employeeId, 'name' => $this->nameOf($employee), 'reason' => $proposal['skip']];
				continue;
			}

			$outcome = $this->record($outcome, $proposal, $employee, $dryRun);
		}//end foreach

		return $outcome;
	}//end proposeForCycle()

	/**
	 * Approve every proposed adjustment in the cycle the caller may approve.
	 *
	 * @param string $cycleId The CompReviewCycle id.
	 * @param string $callerUid Who approves.
	 * @param string|null $decisionReason An optional reason sent to every employee approved.
	 *
	 * @return array<string, mixed> Counts and one row per proposed adjustment.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
	 */
	public function approveCycle(string $cycleId, string $callerUid, ?string $decisionReason = null): array {
		$outcome = ['status' => 'ok', 'cycleId' => $cycleId, 'approved' => 0, 'refusedSelfApproval' => 0, 'failed' => 0, 'rows' => []];
		$data = ['approvedBy' => $callerUid];
		if ($decisionReason !== null && trim($decisionReason) !== '') {
			$data['decisionReason'] = trim($decisionReason);
		}

		foreach ($this->gateway->findFiltered('CompAdjustment', ['cycleId' => $cycleId]) as $adjustment) {
			if ((string)($adjustment['status'] ?? '') !== 'proposed') {
				continue;
			}

			$adjustmentId = (string)($adjustment['id'] ?? '');
			$ownProposal = trim((string)($adjustment['proposedBy'] ?? '')) === $callerUid;
			$ownRaise = trim((string)($adjustment['employeeUserId'] ?? '')) === $callerUid;
			if ($ownProposal === true || $ownRaise === true) {
				$outcome['refusedSelfApproval']++;
				$outcome['rows'][] = ['adjustmentId' => $adjustmentId, 'employeeId' => $adjustment['employeeId'] ?? null, 'status' => 'refused-self-approval'];
				continue;
			}

			try {
				$this->transitionEngine()->transition(objectId: $adjustmentId, action: 'approve', data: $data);
			} catch (\Throwable $e) {
				$this->logger->warning('CompCollectiveService: goedkeuren van ' . $adjustmentId . ' geweigerd: ' . $e->getMessage());
				$outcome['failed']++;
				$outcome['rows'][] = ['adjustmentId' => $adjustmentId, 'employeeId' => $adjustment['employeeId'] ?? null, 'status' => 'failed', 'message' => $e->getMessage()];
				continue;
			}

			$outcome['approved']++;
			$outcome['rows'][] = ['adjustmentId' => $adjustmentId, 'employeeId' => $adjustment['employeeId'] ?? null, 'status' => 'approved'];
		}//end foreach

		return $outcome;
	}//end approveCycle()

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

		if ($kind === 'collective' && $this->raiseOf($cycle) === null) {
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
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<int, string> $selection Hand-picked employee ids, or empty.
	 * @param array<string, array<int, array<string, mixed>>> $contracts Contracts by employee id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function candidates(array $cycle, array $selection, array $contracts): array {
		$employees = $this->gateway->loadAll('Employee');
		if ($selection !== []) {
			return array_values(array_filter($employees, static fn (array $e): bool => in_array((string)($e['id'] ?? ''), $selection, true)));
		}

		$scope = (is_array($cycle['scope'] ?? null) === true ? $cycle['scope'] : []);
		$administrationId = trim((string)($scope['administrationId'] ?? ''));
		$cao = trim((string)($scope['cao'] ?? ''));
		$orgUnitId = trim((string)($scope['orgUnitId'] ?? ''));
		$onDate = (string)$cycle['effectiveDate'];
		$placed = ($orgUnitId === '' ? [] : $this->placedIn($orgUnitId, $onDate));

		$out = [];
		foreach ($employees as $employee) {
			$employeeId = (string)($employee['id'] ?? '');
			if ($administrationId !== '' && trim((string)($employee['administrationId'] ?? '')) !== $administrationId) {
				continue;
			}

			if ($orgUnitId !== '' && isset($placed[$employeeId]) === false) {
				continue;
			}

			if ($cao !== '') {
				$covering = $this->covering(($contracts[$employeeId] ?? []), $onDate);
				if ($covering === null || trim((string)($covering['cao'] ?? '')) !== $cao) {
					continue;
				}
			}

			$out[] = $employee;
		}//end foreach

		return $out;
	}//end candidates()

	/**
	 * The proposal for one employee, or ['skip' => reason].
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<string, mixed> $employee The employee.
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 * @param string $callerUid Who proposes.
	 *
	 * @return array<string, mixed>
	 */
	private function buildProposal(array $cycle, array $employee, array $contracts, string $callerUid): array {
		$gross = ($employee['grossMonthlySalary'] ?? null);
		$current = (is_numeric($gross) === true ? (int)round(((float)$gross) * 100) : null);

		if ((string)$cycle['kind'] === 'step-increase') {
			$proposal = $this->stepProposal($cycle, $contracts);
		} else {
			$proposal = $this->raiseProposal($cycle, $contracts, $current);
		}

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
				'proposedBy' => $callerUid,
				'employeeUserId' => ($userId === '' ? null : $userId),
			],
			$proposal
		);
	}//end buildProposal()

	/**
	 * A collective raise on the contract covering the cycle's effective date.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 * @param int|null $current Current gross monthly salary in cents.
	 *
	 * @return array<string, mixed>
	 */
	private function raiseProposal(array $cycle, array $contracts, ?int $current): array {
		$effectiveDate = (string)$cycle['effectiveDate'];
		$contract = $this->covering($contracts, $effectiveDate);
		if ($contract === null) {
			return ['skip' => 'no-contract-on-effective-date'];
		}

		if ($current === null || $current <= 0) {
			return ['skip' => 'no-salary'];
		}

		$raise = $this->raiseOf($cycle);
		$proposal = [
			'contractId' => (string)$contract['id'],
			'effectiveDate' => $effectiveDate,
			'adjustmentKind' => 'collective',
		];

		if ($raise['percentage'] !== null) {
			$factor = (1 + ($raise['percentage'] / 100));
			$proposal['proposedSalary'] = (int)round($current * $factor);
			$proposal['rationale'] = 'Collectieve verhoging van ' . $this->number($raise['percentage']) . '% (' . (string)($cycle['name'] ?? '') . ').';
			$hourly = ($contract['hourlyWage'] ?? null);
			if (is_numeric($hourly) === true && (float)$hourly > 0) {
				$proposal['proposedHourlyWage'] = round(((float)$hourly) * $factor, 2);
			}

			return $proposal;
		}

		$proposal['proposedSalary'] = ($current + (int)$raise['amount']);
		$proposal['rationale'] = 'Collectieve verhoging van ' . $this->number($raise['amount'] / 100) . ' euro per maand (' . (string)($cycle['name'] ?? '') . ').';

		return $proposal;
	}//end raiseProposal()

	/**
	 * The next step of the band for a contract whose step date falls in the
	 * cycle's period.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	private function stepProposal(array $cycle, array $contracts): array {
		$stepped = null;
		foreach ($contracts as $contract) {
			if (trim((string)($contract['salaryBandId'] ?? '')) !== '' && is_numeric($contract['salaryStep'] ?? null) === true) {
				$stepped = $contract;
				break;
			}
		}

		if ($stepped === null) {
			return ['skip' => 'no-band-step'];
		}

		$stepDate = trim((string)($stepped['stepDate'] ?? ''));
		if ($stepDate === '' || $this->inPeriod($stepDate, $cycle) === false || $this->covering([$stepped], $stepDate) === null) {
			return ['skip' => 'step-not-due'];
		}

		$band = $this->gateway->findObjectData((string)$stepped['salaryBandId'], 'SalaryBand');
		$steps = $this->stepsOf($band);
		$from = (int)$stepped['salaryStep'];
		$next = null;
		foreach ($steps as $step => $cents) {
			if ($step > $from) {
				$next = [$step, $cents];
				break;
			}
		}

		if ($next === null) {
			return ['skip' => 'top-of-band'];
		}

		return [
			'contractId' => (string)$stepped['id'],
			'effectiveDate' => $stepDate,
			'adjustmentKind' => 'step-increase',
			'fromStep' => $from,
			'toStep' => $next[0],
			'proposedSalary' => $next[1],
			'targetBandId' => (string)$stepped['salaryBandId'],
			'rationale' => 'Periodiek: van trede ' . $from . ' naar trede ' . $next[0] . ' in ' . (string)($band['title'] ?? 'de schaal') . '.',
		];
	}//end stepProposal()

	/**
	 * Save (or, on a dry run, count) one proposal and add it to the outcome.
	 *
	 * @param array<string, mixed> $outcome The running outcome.
	 * @param array<string, mixed> $proposal The CompAdjustment payload.
	 * @param array<string, mixed> $employee The employee.
	 * @param bool $dryRun Write nothing.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function record(array $outcome, array $proposal, array $employee, bool $dryRun): array {
		if ($dryRun === false) {
			try {
				$this->gateway->save($proposal, 'CompAdjustment');
			} catch (\Throwable $e) {
				$this->logger->warning('CompCollectiveService: voorstel voor ' . (string)$employee['id'] . ' niet opgeslagen: ' . $e->getMessage());
				$outcome['failed']++;
				$outcome['skipped'][] = ['employeeId' => (string)$employee['id'], 'name' => $this->nameOf($employee), 'reason' => 'failed'];
				return $outcome;
			}

			$outcome['created']++;
		}

		$outcome['wouldCreate']++;
		if (count($outcome['rows']) < self::PREVIEW_ROWS) {
			$outcome['rows'][] = [
				'employeeId' => (string)$employee['id'],
				'name' => $this->nameOf($employee),
				'currentSalary' => $proposal['currentSalary'],
				'proposedSalary' => $proposal['proposedSalary'],
				'fromStep' => ($proposal['fromStep'] ?? null),
				'toStep' => ($proposal['toStep'] ?? null),
				'effectiveDate' => $proposal['effectiveDate'],
			];
		}

		return $outcome;
	}//end record()

	/**
	 * The cycle's raise: a percentage wins over an amount; null when neither.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 *
	 * @return array{percentage: float|null, amount: int|null}|null
	 */
	private function raiseOf(array $cycle): ?array {
		$percentage = ($cycle['raisePercentage'] ?? null);
		if (is_numeric($percentage) === true && (float)$percentage !== 0.0) {
			return ['percentage' => (float)$percentage, 'amount' => null];
		}

		$amount = ($cycle['raiseAmountCents'] ?? null);
		if (is_numeric($amount) === true && (int)$amount !== 0) {
			return ['percentage' => null, 'amount' => (int)$amount];
		}

		return null;
	}//end raiseOf()

	/**
	 * Whether a date falls in the cycle's period: 'YYYY' is that year,
	 * 'YYYY-MM' that month, anything else the year of the effective date.
	 *
	 * @param string $date ISO date.
	 * @param array<string, mixed> $cycle The cycle.
	 *
	 * @return bool
	 */
	private function inPeriod(string $date, array $cycle): bool {
		$period = trim((string)($cycle['period'] ?? ''));
		if (preg_match('/^\d{4}(-\d{2})?$/', $period) !== 1) {
			$period = substr((string)$cycle['effectiveDate'], 0, 4);
		}

		return str_starts_with($date, $period);
	}//end inPeriod()

	/**
	 * The contract covering a date: started on or before it, not ended before
	 * it. The latest-starting one when several do.
	 *
	 * @param array<int, array<string, mixed>> $contracts Contracts.
	 * @param string $onDate ISO date.
	 *
	 * @return array<string, mixed>|null
	 */
	private function covering(array $contracts, string $onDate): ?array {
		$found = null;
		foreach ($contracts as $contract) {
			$start = (string)($contract['startDate'] ?? '');
			$end = trim((string)($contract['endDate'] ?? ''));
			if ($start === '' || $start > $onDate || ($end !== '' && $end < $onDate)) {
				continue;
			}

			if ($found === null || $start > (string)$found['startDate']) {
				$found = $contract;
			}
		}

		return $found;
	}//end covering()

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
			if ($this->covering([$assignment], $onDate) !== null) {
				$placed[(string)($assignment['employeeId'] ?? '')] = true;
			}
		}

		return $placed;
	}//end placedIn()

	/**
	 * A band's steps as step => cents, ascending.
	 *
	 * @param array<string, mixed>|null $band The SalaryBand.
	 *
	 * @return array<int, int>
	 */
	private function stepsOf(?array $band): array {
		$steps = [];
		foreach ((is_array($band['steps'] ?? null) === true ? $band['steps'] : []) as $step) {
			if (is_numeric($step['step'] ?? null) === true && is_numeric($step['monthlySalaryCents'] ?? null) === true) {
				$steps[(int)$step['step']] = (int)$step['monthlySalaryCents'];
			}
		}

		ksort($steps);
		return $steps;
	}//end stepsOf()

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
	 * A number without trailing zeros, Dutch decimal comma.
	 *
	 * @param float $value The number.
	 *
	 * @return string
	 */
	private function number(float $value): string {
		return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
	}//end number()

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

	/**
	 * @return mixed OpenRegister's TransitionEngine.
	 */
	private function transitionEngine(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException('humaniq requires the OpenRegister app, which is not installed on this instance.');
		}

		return $this->container->get(self::TRANSITION_ENGINE);
	}//end transitionEngine()

}//end class
