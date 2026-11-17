<?php

/**
 * Contract of AnalyticsService
 *
 * The public surface of AnalyticsService that other components call, so they depend
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
 * Contract of AnalyticsService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AnalyticsServiceInterface {
	/**
	 * Default period window when the caller supplies none.
	 *
	 * @var string
	 */
	public const DEFAULT_PERIOD = 'year';

	/**
	 * Time-series data for the Dashboard's three `endpointSource` trend
	 * charts.
	 *
	 * @param string $metric One of ALLOWED_TREND_METRICS.
	 * @param string $period One of \OCA\Humaniq\Service\TrendPeriods::PERIOD_MONTHS' keys.
	 * @param string $administrationId The caller's ALREADY-AUTHORIZED active administration (REQ-DSI-005) — never resolved here.
	 * @param array{id: string, minimumMembers: int}|null $unit department-figures: restrict to the people placed in this unit and its children, and withhold a period with fewer people than minimumMembers (0 for none); null or an empty id for the whole administration.
	 *
	 * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
	 *
	 * @throws \InvalidArgumentException When metric or period is not recognised.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-004
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-006
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-007
	 */
	public function getTrends(
		string $metric,
		string $period,
		string $administrationId,
		?array $unit=null,
	): array;
}//end interface
