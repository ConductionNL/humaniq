<?php

/**
 * Humaniq Expense Settings
 *
 * The employer's expense settings: the default reimbursement route, the
 * mileage rate and the route planner source the travel calculation uses.
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
 * Reads the employer's expense settings from app config.
 *
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
 */
final class ExpenseSettings {

	/**
	 * @param IAppConfig $appConfig The app config interface.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The route an approved claim takes by default, `payroll` or `direct`
	 * (payroll-expenses-and-allowances D1), configurable via app config key
	 * `expense_reimbursement_route`. Unset or unknown keeps today's direct route.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function getExpenseReimbursementRoute(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'expense_reimbursement_route', 'direct');
		return $value === 'payroll' ? 'payroll' : 'direct';
	}//end getExpenseReimbursementRoute()

	/**
	 * expenses-travel-calculation: the rate per kilometre the employer pays
	 * for business and commuting travel. App config key
	 * `mileage_rate_per_km` (for example `0.30`); null when unset or not a
	 * positive number, and the caller then pays the tax-free rate from the
	 * rule corpus.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
	 */
	public function getMileageRatePerKm(): ?float {
		$raw = trim(str_replace(',', '.', $this->appConfig->getValueString(Application::APP_ID, 'mileage_rate_per_km', '')));
		if (is_numeric($raw) === false || (float)$raw <= 0.0) {
			return null;
		}

		return (float)$raw;
	}//end getMileageRatePerKm()

	/**
	 * expenses-travel-calculation: the integriq source that answers the road
	 * distance between two postcodes (app config `route_distance_source`, a
	 * source uuid) and the path on it (`route_distance_endpoint`, default
	 * empty). Empty source means no route planner is configured.
	 *
	 * @return array{source: string, endpoint: string}
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
	 */
	public function getRouteDistanceSource(): array {
		return [
			'source' => trim($this->appConfig->getValueString(Application::APP_ID, 'route_distance_source', '')),
			'endpoint' => trim($this->appConfig->getValueString(Application::APP_ID, 'route_distance_endpoint', '')),
		];
	}//end getRouteDistanceSource()

}//end class
