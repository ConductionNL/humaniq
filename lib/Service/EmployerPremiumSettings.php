<?php

/**
 * Humaniq Employer Premium Settings
 *
 * The employer-specific employee-insurance premium facts the payroll run needs
 * and the tax-year tables cannot know: the Aof tariff class and the
 * gedifferentieerde Whk percentage.
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
 * Reads the employer's Aof tariff and Whk percentage from humaniq's app config.
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */
final class EmployerPremiumSettings {

	/**
	 * @param IAppConfig $appConfig humaniq's app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The employer's Aof (arbeidsongeschiktheidsfonds) tariff classification
	 * (payroll-core-engine design.md D2 step 9): `laag` for small employers,
	 * `hoog` for other employers — a per-employer fact the tax-year tables
	 * cannot know. Configurable via app config key `payroll_aof_tariff`;
	 * anything other than `hoog` falls back to the honest default `laag`.
	 *
	 * @return string `laag` or `hoog`.
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
	 */
	public function getPayrollAofTariff(): string {
		$value = strtolower(trim($this->appConfig->getAppValueString('payroll_aof_tariff', 'laag')));
		return $value === 'hoog' ? 'hoog' : 'laag';
	}//end getPayrollAofTariff()

	/**
	 * The employer's gedifferentieerde Whk (Werkhervattingskas) percentage
	 * (payroll-core-engine design.md D2 step 9) — employer-specific, set by
	 * the Belastingdienst Whk-beschikking. Configurable via app config key
	 * `payroll_whk_percentage`; unset/blank/non-numeric falls back to the
	 * tables' flagged national average passed in by the caller (the tables
	 * file marks its `whk` value `placeholder: true`).
	 *
	 * @param float $tablesDefault The tables' flagged national-average Whk percentage.
	 *
	 * @return float The Whk percentage (percentage scale, e.g. 1.52).
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
	 */
	public function getPayrollWhkPercentage(float $tablesDefault): float {
		$value = trim($this->appConfig->getAppValueString('payroll_whk_percentage', ''));
		if ($value === '' || is_numeric($value) === false) {
			return $tablesDefault;
		}

		$percentage = (float)$value;
		return $percentage >= 0.0 ? $percentage : $tablesDefault;
	}//end getPayrollWhkPercentage()

}//end class
