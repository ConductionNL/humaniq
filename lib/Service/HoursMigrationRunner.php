<?php

/**
 * Humaniq Hours Migration Runner.
 *
 * Execution context for the hours-process migration pass: resolves an acting
 * user (occ has no session, and OpenRegister's folder access check
 * default-denies a sessionless caller), and classifies a
 * FolderAccessDeniedException as "defer to a background job" — during
 * `occ upgrade` maintenance mode user filesystems cannot mount, so the folder
 * check can never pass there regardless of the acting user. Extracted from
 * MigrateHoursProcess so the repair step stays within the phpmd complexity
 * and coupling budgets while the plumbing keeps its own unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Acting-user scope + maintenance-mode deferral for the hours migration pass.
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */
class HoursMigrationRunner {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Session to impersonate through when occ has none.
	 * @param IGroupManager $groupManager Resolves the fallback admin acting user.
	 * @param IJobList $jobList Queues the one-shot completion job on deferral.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the migration pass under a resolvable acting user.
	 *
	 * A real session user always wins (never overridden). Without one, the
	 * first admin is impersonated for the duration and the (null) session is
	 * restored afterwards — OpenRegister's own ImportHandler precedent. When
	 * no user resolves at all the pass still runs; folder-dependent operations
	 * then skip inside ObjectService, exactly as during a register import.
	 *
	 * @param callable(): array{processed: int, entriesCreated: int, unresolvableUserLinks: int} $pass The migration pass.
	 *
	 * @return array{processed: int, entriesCreated: int, unresolvableUserLinks: int} The pass result.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function runAsActingUser(callable $pass): array {
		if ($this->userSession->getUser() !== null) {
			return $pass();
		}

		$actingUser = null;
		try {
			$adminGroup = $this->groupManager->get('admin');
			if ($adminGroup !== null) {
				$admins = $adminGroup->getUsers();
				if (count($admins) > 0) {
					$actingUser = reset($admins);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: hours-process migration could not resolve an acting admin user',
				['exception' => $e->getMessage()]
			);
		}

		if ($actingUser === null) {
			return $pass();
		}

		$this->userSession->setUser($actingUser);
		try {
			return $pass();
		} finally {
			$this->userSession->setUser(null);
		}
	}//end runAsActingUser()

	/**
	 * Whether a failed pass should defer to the background completion job.
	 *
	 * True only for OpenRegister's FolderAccessDeniedException: under
	 * `occ upgrade` maintenance mode the folder check can never pass, so the
	 * failure is a property of the CONTEXT, not the data. When true, the
	 * one-shot completion job is enqueued (at most once).
	 *
	 * @param \Throwable $e The failure raised by the pass.
	 * @param string $jobClass The queued-job class to enqueue on deferral.
	 *
	 * @return bool True when the failure was deferred to the job.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function deferIfMaintenanceDenied(\Throwable $e, string $jobClass): bool {
		if (is_a($e, 'OCA\OpenRegister\Exception\FolderAccessDeniedException') === false) {
			return false;
		}

		if ($this->jobList->has($jobClass, null) === false) {
			$this->jobList->add($jobClass);
		}

		return true;
	}//end deferIfMaintenanceDenied()
}//end class
