<?php

/**
 * Humaniq Leave Accrual Settings
 *
 * The settings LeaveAccrualJob reads: whether it may run, and the annual
 * above-statutory (bovenwettelijk) leave hours it accrues 1/12 of per month.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\AppFramework\Services\IAppConfig;

/**
 * Reads the leave accrual settings from humaniq's app config.
 *
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-003
 */
final class LeaveAccrualSettings {

	/**
	 * @param IAppConfig $appConfig humaniq's app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Whether `LeaveAccrualJob` may run (leave-accrual-job design.md D6) — an
	 * operator off-switch, configurable via app config key
	 * `leave_accrual_enabled`. Defaults to `true`; the job returns immediately
	 * with zero writes when this is `false`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function isLeaveAccrualEnabled(): bool {
		return $this->appConfig->getAppValueBool('leave_accrual_enabled', true);
	}//end isLeaveAccrualEnabled()

	/**
	 * The annual bovenwettelijk (above-statutory) leave hours LeaveAccrualJob
	 * accrues 1/12 of per month (leave-accrual-job design.md D3), configurable
	 * via app config key `leave_bovenwettelijk_annual_hours`. Defaults to `0`
	 * — statutory-only until an employer configures it; CAO-derived
	 * bovenwettelijk is a named fast-follow.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-003
	 */
	public function getLeaveBovenwettelijkAnnualHours(): float {
		$value = trim($this->appConfig->getAppValueString('leave_bovenwettelijk_annual_hours', '0'));
		if ($value === '' || is_numeric($value) === false) {
			return 0.0;
		}

		$hours = (float)$value;
		return $hours >= 0.0 ? $hours : 0.0;
	}//end getLeaveBovenwettelijkAnnualHours()

}//end class
