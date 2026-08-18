<?php

/**
 * Upserts Objects (optional CheckProvider capability)
 *
 * A `SeedsObjects` provider whose samples carry a stable natural (business) key —
 * one that is NOT the OpenRegister-assigned object id — implements this to
 * declare that key's field name per object type. `RuleTestDataSeeder` then
 * matches existing rows by that field's value and upserts (create when no row
 * matches, update in place when one does) instead of the default `SeedsObjects`
 * gate of "create once, only when the type has no rows at all". This is what
 * lets a provider's seed genuinely converge to its source data on re-seed
 * (cao-library design.md D7/REQ-CAO-006), rather than silently going stale after
 * the first seed run.
 *
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Optional capability: declare the natural-key field samples upsert on.
 */
interface UpsertsObjects {
	/**
	 * The natural-key field name to upsert seeded samples on, keyed by object
	 * type. Only object types declared here get upsert-by-key seeding; any
	 * `SeedsObjects` sample for a type NOT declared here keeps the default
	 * create-once-when-empty behaviour.
	 *
	 * @return array<string, string>
	 */
	public static function upsertKeys(): array;
}//end interface
