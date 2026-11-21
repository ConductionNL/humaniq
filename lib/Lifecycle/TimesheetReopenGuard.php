<?php

/**
 * Humaniq TimesheetReopenGuard
 *
 * OpenRegister lifecycle guard on the Timesheet `reopen` transition: an
 * approved timesheet goes back to draft for a correction until a payroll run
 * that is approved or later has paid its hours. A draft run recalculates and
 * a deleted run no longer counts (HoursPayService pays such a timesheet
 * again), so neither stops the reopen. Referenced from the Timesheet schema
 * `x-openregister-lifecycle.transitions.reopen.requires` in
 * `lib/Settings/register.d/hr-timesheet.json`. Clearing the approval fields
 * on reopen is TimesheetProcessStampListener's.
 *
 * Read-only, as every guard: it judges the timesheet as stored, not the
 * payload, so a payload without `payrollRunId` cannot talk its way past a
 * paid run. Fails closed when the stored timesheet cannot be read.
 *
 * @category Lifecycle
 * @package  OCA\Humaniq\Lifecycle
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
 * @spec openspec/specs/humaniq-timesheet-approval/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Refuses reopening a timesheet whose hours an approved or later payroll run paid.
 *
 * @spec openspec/specs/humaniq-timesheet-approval/spec.md
 */
class TimesheetReopenGuard implements LifecycleGuardInterface {

	/**
	 * PayrollRun states in which the run's figures are final.
	 */
	private const FINAL_RUN_STATES = ['approved', 'posted', 'paid'];

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $store Reads the stored timesheet and its run.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approval/spec.md
	 */
	public function __construct(
		private readonly RecordStoreInterface $store,
	) {
	}//end __construct()

	/**
	 * Allow the reopen unless a final payroll run paid the timesheet.
	 *
	 * @param array<string, mixed> $object The incoming Timesheet payload.
	 * @param string               $action The transition (reopen).
	 * @param string               $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the static allow()/deny() factories.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approval/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$id = trim((string)($object['id'] ?? ($object['@self']['id'] ?? ($object['uuid'] ?? ''))));
		$stored = ($id === '' ? null : $this->store->findObjectData($id, 'Timesheet'));
		if ($stored === null) {
			return GuardResult::deny('De urenstaat kon niet worden gelezen; heropenen is geweigerd.');
		}

		$runId = trim((string)($stored['payrollRunId'] ?? ''));
		if ($runId === '') {
			return GuardResult::allow();
		}

		$run = $this->store->findObjectData($runId, 'PayrollRun');
		if ($run === null || in_array((string)($run['status'] ?? ''), self::FINAL_RUN_STATES, true) === false) {
			return GuardResult::allow();
		}

		return GuardResult::deny(
			sprintf(
				'De uren van deze urenstaat zijn uitbetaald in loonrun %s (%s); heropenen is geweigerd.',
				trim((string)($run['period'] ?? '')) !== '' ? (string)$run['period'] : $runId,
				$runId
			)
		);
	}//end check()
}//end class
