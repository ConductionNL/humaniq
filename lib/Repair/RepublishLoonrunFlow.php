<?php

/**
 * Humaniq Republish Loonrun Flow Repair Step
 *
 * The v1 Loonrun declaration shipped a switch-gate condition reading
 * `review.outcome` where the engine places the outcome under
 * `json.review.outcome`, so every approved review took the rejected exit
 * (payroll-run-as-a-flow design.md D8). The corrected declaration re-imports
 * on upgrade, but OpenRegister's schema-flow import only publishes a FRESH
 * import: an instance that already imported v1 keeps the broken PUBLISHED
 * version. This step republishes the corrected head, through
 * `LoonrunFlowRepairService`, which owns the guard rails: only an unmodified
 * v1 publish is touched, open drafts are never published over, enablement
 * and ownership stay as they are, and a second run is a reported no-op.
 *
 * Registered as `post-migration` AND `install`, after `InitializeRegister`,
 * so the schema re-import has already corrected the flow's editable head by
 * the time this runs — the same ordering argument the other Migrate* steps
 * document in appinfo/info.xml. `occ maintenance:repair` re-runs it on
 * demand, which idempotence makes safe.
 *
 * @category Repair
 * @package  OCA\Humaniq\Repair
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\Service\LoonrunFlowRepairService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Republishes the shipped Loonrun flow when its published gate still reads
 * the v1 keypath.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */
class RepublishLoonrunFlow implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param LoonrunFlowRepairService $repairService The repair itself.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function __construct(
		private readonly LoonrunFlowRepairService $repairService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function getName(): string {
		return 'Republish the humaniq Loonrun flow so approvals stop taking the rejected exit';
	}//end getName()

	/**
	 * Run the repair step. Failures warn and return: a broken flow store
	 * must not abort the rest of the upgrade.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function run(IOutput $output): void {
		try {
			$report = $this->repairService->repair();
		} catch (\Throwable $e) {
			$output->warning('Could not repair the Loonrun flow: ' . $e->getMessage());
			$this->logger->error('Humaniq: Loonrun flow repair failed', ['exception' => $e->getMessage()]);
			return;
		}

		$output->info('Loonrun flow repair: ' . $report['status'] . '. ' . $report['detail']);

	}//end run()

}//end class
