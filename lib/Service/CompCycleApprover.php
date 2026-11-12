<?php

/**
 * Comp Cycle Approver
 *
 * Approves every proposed adjustment in a compensation cycle
 * (comp-collective-raise-and-step-increase design.md D3). Each approval is its
 * own `approve` transition through OpenRegister's TransitionEngine, so the
 * lifecycle listener runs NoSelfApprovalGuard per adjustment and the declared
 * approval notification fires. The caller's own proposals, and their own
 * raise, are skipped and reported as `refused-self-approval`.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bulk approval for a compensation cycle, four eyes per adjustment.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 */
class CompCycleApprover {

	/**
	 * OpenRegister's transition engine, resolved lazily.
	 *
	 * @var string
	 */
	private const TRANSITION_ENGINE = 'OCA\OpenRegister\Service\Lifecycle\TransitionEngine';

	/**
	 * @param RecordStoreInterface $gateway Reads the cycle's adjustments.
	 * @param ContainerInterface $container Resolves OpenRegister's TransitionEngine.
	 * @param OpenRegisterAvailability $openRegister OpenRegister availability.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly ContainerInterface $container,
		private readonly OpenRegisterAvailability $openRegister,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

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
		$approval = new CycleApproval();
		$approval->callerUid = $callerUid;
		$approval->data = self::approvalData(callerUid: $callerUid, decisionReason: $decisionReason);
		$approval->outcome = ['status' => 'ok', 'cycleId' => $cycleId, 'approved' => 0, 'refusedSelfApproval' => 0, 'failed' => 0, 'rows' => []];
		foreach ($this->gateway->findFiltered('CompAdjustment', ['cycleId' => $cycleId]) as $adjustment) {
			if ((string)($adjustment['status'] ?? '') === 'proposed') {
				$this->approveOne(approval: $approval, adjustment: $adjustment);
			}
		}

		return $approval->outcome;
	}//end approveCycle()

	/**
	 * What every approve transition carries: the approver and, when given,
	 * the trimmed decision reason.
	 *
	 * @param string      $callerUid      Who approves.
	 * @param string|null $decisionReason An optional reason.
	 *
	 * @return array<string, string>
	 */
	private static function approvalData(string $callerUid, ?string $decisionReason): array {
		$data = ['approvedBy' => $callerUid];
		if ($decisionReason !== null && trim($decisionReason) !== '') {
			$data['decisionReason'] = trim($decisionReason);
		}

		return $data;
	}//end approvalData()

	/**
	 * Approve one proposed adjustment, unless the caller proposed it or it
	 * is their own raise; a refused transition is counted and reported.
	 *
	 * @param CycleApproval        $approval   The cycle being approved.
	 * @param array<string, mixed> $adjustment The proposed CompAdjustment.
	 *
	 * @return void
	 */
	private function approveOne(CycleApproval $approval, array $adjustment): void {
		if (self::isOwn(adjustment: $adjustment, callerUid: $approval->callerUid) === true) {
			$approval->outcome['refusedSelfApproval']++;
			$approval->outcome['rows'][] = self::row(adjustment: $adjustment, status: 'refused-self-approval');
			return;
		}

		try {
			$this->transitionEngine()->transition(objectId: (string)($adjustment['id'] ?? ''), action: 'approve', data: $approval->data);
		} catch (\Throwable $e) {
			$this->logger->warning('CompCycleApprover: goedkeuren van ' . (string)($adjustment['id'] ?? '') . ' geweigerd: ' . $e->getMessage());
			$approval->outcome['failed']++;
			$approval->outcome['rows'][] = self::row(adjustment: $adjustment, status: 'failed') + ['message' => $e->getMessage()];
			return;
		}

		$approval->outcome['approved']++;
		$approval->outcome['rows'][] = self::row(adjustment: $adjustment, status: 'approved');
	}//end approveOne()

	/**
	 * Whether the caller proposed the adjustment or receives it.
	 *
	 * @param array<string, mixed> $adjustment The CompAdjustment.
	 * @param string               $callerUid  Who approves.
	 *
	 * @return bool
	 */
	private static function isOwn(array $adjustment, string $callerUid): bool {
		$ownProposal = trim((string)($adjustment['proposedBy'] ?? '')) === $callerUid;
		$ownRaise = trim((string)($adjustment['employeeUserId'] ?? '')) === $callerUid;

		return $ownProposal === true || $ownRaise === true;
	}//end isOwn()

	/**
	 * One outcome row.
	 *
	 * @param array<string, mixed> $adjustment The CompAdjustment.
	 * @param string               $status     What happened to it.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(array $adjustment, string $status): array {
		return ['adjustmentId' => (string)($adjustment['id'] ?? ''), 'employeeId' => $adjustment['employeeId'] ?? null, 'status' => $status];
	}//end row()

	/**
	 * @return mixed OpenRegister's TransitionEngine.
	 */
	private function transitionEngine(): mixed {
		if ($this->openRegister->isAvailable() === false) {
			throw new RuntimeException('humaniq requires the OpenRegister app, which is not installed on this instance.');
		}

		return $this->container->get(self::TRANSITION_ENGINE);
	}//end transitionEngine()

}//end class
