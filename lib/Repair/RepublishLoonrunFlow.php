<?php

/**
 * Humaniq Republish Loonrun Flow Repair Step
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * Generalised (platform-hr-workflows): after the Loonrun repair it hands every
 * other flow humaniq declares to {@see ShippedFlowPublisher}, so a flow shipped
 * after an instance installed humaniq gets a published version on upgrade.
 *
 * @category Repair
 * @package  OCA\Humaniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 * @spec openspec/specs/hr-workflows/spec.md#REQ-HWF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\Kernel\Contracts\LoonrunFlowRepairServiceInterface;
use OCA\Humaniq\Kernel\Contracts\ShippedFlowPublisherInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Republishes the shipped Loonrun flow when its published gate still reads
 * the v1 keypath.
 *
 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */
class RepublishLoonrunFlow implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param LoonrunFlowRepairServiceInterface  $repairService The repair itself.
	 * @param LoggerInterface                    $logger        The logger.
	 * @param ShippedFlowPublisherInterface|null $publisher     Publishes the other shipped flows; skipped when null.
	 *
	 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function __construct(
		private readonly LoonrunFlowRepairServiceInterface $repairService,
		private readonly LoggerInterface $logger,
		private readonly ?ShippedFlowPublisherInterface $publisher=null,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function getName(): string {
		return 'Republish the shipped humaniq flows, so Loonrun approvals stop taking the rejected exit and new flows get published';
	}//end getName()

	/**
	 * Run the repair step. Failures warn and return: a broken flow store
	 * must not abort the rest of the upgrade.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function run(IOutput $output): void {
		try {
			$report = $this->repairService->repair();
			$output->info('Loonrun flow repair: ' . $report['status'] . '. ' . $report['detail']);
		} catch (\Throwable $e) {
			$output->warning('Could not repair the Loonrun flow: ' . $e->getMessage());
			$this->logger->error('Humaniq: Loonrun flow repair failed', ['exception' => $e->getMessage()]);
		}

		$this->publishShippedFlows(output: $output);

	}//end run()

	/**
	 * Publish every other shipped flow that needs it, one info line per flow.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-workflows/spec.md#REQ-HWF-001
	 */
	private function publishShippedFlows(IOutput $output): void {
		if ($this->publisher === null) {
			return;
		}

		try {
			$reports = $this->publisher->publishAll();
		} catch (\Throwable $e) {
			$output->warning('Could not publish the shipped flows: ' . $e->getMessage());
			$this->logger->error('Humaniq: shipped flow publishing failed', ['exception' => $e->getMessage()]);
			return;
		}

		foreach ($reports as $report) {
			$output->info('Shipped flow ' . $report['flow'] . ': ' . $report['status'] . '. ' . $report['detail']);
		}

	}//end publishShippedFlows()

}//end class
