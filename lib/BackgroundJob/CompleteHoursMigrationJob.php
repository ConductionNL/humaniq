<?php

/**
 * Hrmq Complete Hours Migration Background Job.
 *
 * Completion vehicle for the hours-process migration when the repair step
 * could not finish during `occ upgrade`: upgrade runs in maintenance mode,
 * where user filesystems cannot mount, so OpenRegister's folder access check
 * can never pass for objects with a bound folder — every write throws
 * FolderAccessDeniedException regardless of the acting user. The repair step
 * enqueues this one-shot job instead of failing; the job re-runs the same
 * idempotent migration pass on the next background-job execution, outside
 * maintenance mode, where the acting-user impersonation works.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Hrmq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */

declare(strict_types=1);

namespace OCA\Hrmq\BackgroundJob;

use OCA\Hrmq\Repair\MigrateHoursProcess;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One-shot completion of the hours-process migration outside maintenance mode.
 *
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */
class CompleteHoursMigrationJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Job time factory.
	 * @param ContainerInterface $container Resolves the repair step lazily (it needs OpenRegister).
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}//end __construct()

	/**
	 * Re-run the idempotent migration pass outside maintenance mode.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- $argument is mandated by the QueuedJob::run() signature; this one-shot job takes no argument.
	 */
	protected function run($argument): void {
		try {
			$step = $this->container->get(MigrateHoursProcess::class);
			$summary = $step->runDeferred();
			$this->logger->info(
				sprintf(
					'hrmq: hours-process migration completed by background job: %d timesheets processed, %d entries created, %d rows with unresolvable user link',
					$summary['processed'],
					$summary['entriesCreated'],
					$summary['unresolvableUserLinks']
				)
			);
		} catch (\Throwable $e) {
			// A queued job runs once; a failure here is logged, never retried
			// silently — the migration stays idempotent, so the next app
			// upgrade re-attempts (and re-enqueues on maintenance-mode failure).
			$this->logger->warning(
				'hrmq: hours-process migration background completion failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
