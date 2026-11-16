<?php

/**
 * Contract of WorkforceReportSettings
 *
 * The public surface of WorkforceReportSettings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of WorkforceReportSettings.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WorkforceReportSettingsInterface {
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
	public function getFormationNetFteLeaveTypes(): array;

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
	public function getFormationLongTermSickWeeks(): int;
}//end interface
