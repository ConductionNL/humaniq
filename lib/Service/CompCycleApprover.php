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
	 * @param HoursRegisterGateway $gateway Reads the cycle's adjustments.
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
				$this->logger->warning('CompCycleApprover: goedkeuren van ' . $adjustmentId . ' geweigerd: ' . $e->getMessage());
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
	 * @return mixed OpenRegister's TransitionEngine.
	 */
	private function transitionEngine(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException('humaniq requires the OpenRegister app, which is not installed on this instance.');
		}

		return $this->container->get(self::TRANSITION_ENGINE);
	}//end transitionEngine()

}//end class
