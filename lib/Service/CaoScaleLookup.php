<?php

/**
 * Humaniq CaoScaleLookup
 *
 * The monthly minimum of a CAO scale, from the CAO library, as an injectable
 * seam for the personnel budget (reporting-personnel-budget-and-scenarios D2).
 * Null when the scale is not in the library or not verified: the budget then
 * flags the place unpriced rather than pricing it from a placeholder.
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
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Standards\CaoRegistry;

/**
 * Looks up a CAO scale minimum.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */
class CaoScaleLookup {

	/**
	 * The monthly minimum of a scale, in cents, or null when it is not sourced.
	 *
	 * @param string $cao The CAO id.
	 * @param string $schaal The scale.
	 *
	 * @return int|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CaoRegistry is the static CAO library; this class is its seam.
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
	 */
	public function minimumCents(string $cao, string $schaal): ?int {
		return CaoRegistry::minMaandloonCents($cao, $schaal);
	}//end minimumCents()

}//end class
