<?php

/**
 * Payroll Approve Node
 *
 * `humaniq.payroll-approve`: the ONE write this change adds that no service
 * performed before (payroll-run-as-a-flow design.md D2/D3). It flips a
 * `draft` PayrollRun to `approved` after the review user task decided
 * `approved`, and refuses every other starting status — approved, posted and
 * paid runs are booked truth consumed by glpost/netpay, exactly the guard
 * `PayrollRunService` applies on recalculation.
 *
 * It writes `status` and NOTHING else. The schema declares no
 * `approvedBy`/`approvedAt`, and OpenRegister silently drops undeclared
 * properties on save, so stamping them here would be a silent no-op dressed
 * as an audit trail. WHO approved and WHEN is engine state already: the task
 * row (`completedBy`, `completedAt`) and the flow run's step history carry
 * it (design.md D3).
 *
 * The acting identity is the dispatcher's concern: contributed nodes run
 * inside FlowRunAsScope, so this class neither resolves nor impersonates a
 * user (REQ-PRF-006).
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use RuntimeException;

/**
 * Flips a reviewed draft payroll run to approved.
 *
 * @psalm-suppress MissingDependency IFlowNode is OpenRegister's, loaded at
 *     runtime and suppressed as an undefined class in psalm.xml, so psalm
 *     cannot verify the implements-relationship here. The declared contract
 *     is real: the vendored test stub mirrors it method-for-method and the
 *     unit suite compiles against it.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
 */
class PayrollApproveNode extends PayrollFlowNodeBase {

	/**
	 * The item key the outcome lands under.
	 *
	 * @var string
	 */
	public const OUTCOME_KEY = 'approval';

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
	 */
	public function getId(): string {
		return 'humaniq.payroll-approve';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Approve payroll run');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
	 */
	public function getDescription(): string {
		return $this->l10n->t('Set a reviewed draft payroll run to approved. Refuses runs in any other status.');
	}//end getDescription()

	/**
	 * Approve the draft run each item names.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration (`runId`, default `{{ payroll.runId }}`).
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying the approval outcome.
	 *
	 * @throws RuntimeException When the run is missing, not draft, or the write fails.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $context is part of the
	 *     IFlowNode::execute() contract; this adapter reads no run-level
	 *     metadata (the acting identity is applied by the dispatcher).
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
	 */
	public function execute(array $items, array $config, array $context): array {
		if ($items === []) {
			return [];
		}

		$out = [];
		foreach ($items as $item) {
			$json = (array)(((array)$item)['json'] ?? []);
			$run = $this->requireRun(config: $config, json: $json);
			$runId = $this->idOf($run);

			$status = (string)($run['status'] ?? '');
			if ($status !== 'draft') {
				throw new RuntimeException(
					'Loonrun ' . $runId . ' heeft status "' . $status
					. '" en kan niet goedgekeurd worden: alleen concept-runs zijn goed te keuren.'
				);
			}

			// Status-only update: same wholesale-save idiom as
			// PayrollRunService::generate()'s totals write, @self stripped.
			$runUpdate = array_merge($run, ['status' => 'approved']);
			unset($runUpdate['@self']);

			try {
				$this->objectService()->saveObject(
					object: $runUpdate,
					register: $this->register(),
					schema: 'PayrollRun',
					uuid: $runId,
					_rbac: false,
					_multitenancy: false
				);
			} catch (\Throwable $e) {
				throw new RuntimeException(
					'Goedkeuren van loonrun ' . $runId . ' is mislukt: ' . $e->getMessage(),
					0,
					$e
				);
			}

			$out[] = $this->withOutcome(
				(array)$item,
				self::OUTCOME_KEY,
				[
					'runId' => $runId,
					'status' => 'approved',
					'period' => (string)($run['period'] ?? ''),
					'administrationId' => (string)($run['administrationId'] ?? ''),
				]
			);
		}//end foreach

		return $out;
	}//end execute()

}//end class
