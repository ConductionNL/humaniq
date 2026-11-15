<?php

/**
 * Humaniq CostAdditionQuery
 *
 * What a cost addition policy is matched against: the CLA the contract
 * names, the contract's own id and the costing date.
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
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-The-cost-rate-is-served-over-HTTP-and-never-persisted
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * What a cost addition policy is matched against.
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-The-cost-rate-is-served-over-HTTP-and-never-persisted
 */
final class CostAdditionQuery {

	/**
	 * The CLA the contract names, or ''.
	 *
	 * @var string
	 */
	public string $claId = '';

	/**
	 * The contract's own id.
	 *
	 * @var string
	 */
	public string $contractId = '';

	/**
	 * ISO date the costing applies to (`YYYY-MM-DD`).
	 *
	 * @var string
	 */
	public string $onDate = '';
}//end class
