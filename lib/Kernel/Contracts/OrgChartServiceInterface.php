<?php

/**
 * Contract of OrgChartService
 *
 * The public surface of OrgChartService that other components call, so they depend
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

use OCA\Humaniq\Service\OrgChartRequest;

/**
 * Contract of OrgChartService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrgChartServiceInterface {
	/**
	 * The chart: `tree` for the list view, `nodes` and `edges` for the drawn
	 * chart (x is the position among the leaves, y the depth), and its
	 * `width` in leaves and `depth` in levels.
	 *
	 * @param OrgChartRequest $request The rows, the day, the root and the readable people.
	 *
	 * @return array{tree: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, edges: array<int, array<string, string>>, width: int, depth: int}
	 *
	 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
	 */
	public function chart(OrgChartRequest $request): array;
}//end interface
