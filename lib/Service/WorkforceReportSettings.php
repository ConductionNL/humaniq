<?php

/**
 * Humaniq Workforce Report Settings
 *
 * The settings the workforce reports read: which leave and how long a sickness
 * counts against a formation's net FTE, and the smallest unit a manager sees
 * department figures of.
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

use OCA\Humaniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads the workforce report settings from app config.
 *
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
 */
final class WorkforceReportSettings {

	/**
	 * @param IAppConfig $appConfig The app config interface.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The leave type codes the net FTE of a formation leaves out
	 * (people-formation-positions design.md D3), configurable via app config
	 * key `formation_net_fte_leave_types` as a comma-separated list. Defaults
	 * to parental leave and pregnancy and childbirth leave, the tender's
	 * categories; long-term sickness is the separate threshold below.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
	 */
	public function getFormationNetFteLeaveTypes(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'formation_net_fte_leave_types', 'parental,zwangerschap');

		return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $code): bool => $code !== ''));
	}//end getFormationNetFteLeaveTypes()

	/**
	 * The number of weeks after which a sickness case counts against the net
	 * FTE of a formation (people-formation-positions design.md D3),
	 * configurable via app config key `formation_long_term_sick_weeks`.
	 * Defaults to 6.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
	 */
	public function getFormationLongTermSickWeeks(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, 'formation_long_term_sick_weeks', 6));
	}//end getFormationLongTermSickWeeks()

	/**
	 * department-figures: the fewest people a unit must hold in a period
	 * before its manager sees its figures, so a small team's absence or wage
	 * cost cannot be traced to one person. App config key
	 * `department_figures_minimum_members`, default 5; HR and accountants are
	 * not bound by it.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function getDepartmentFiguresMinimumMembers(): int {
		return max(1, $this->appConfig->getValueInt(Application::APP_ID, 'department_figures_minimum_members', 5));
	}//end getDepartmentFiguresMinimumMembers()

}//end class
