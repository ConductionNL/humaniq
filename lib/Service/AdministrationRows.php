<?php

/**
 * Administration Rows
 *
 * The implicit page-scoping rule REQ-MULTI-004 applies to denormalized-scoped
 * register rows: a row belongs to an administration when its
 * `administrationId` is exactly that administration's id; a null, blank or
 * mismatched value is another administration's row.
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Scopes register rows to one administration.
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-004
 */
final class AdministrationRows {

	/**
	 * The rows scoped to the administration, in their order.
	 *
	 * @param array<int, array<string, mixed>> $rows             The rows.
	 * @param string                           $administrationId The administration's id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-004
	 */
	public static function scopedTo(array $rows, string $administrationId): array {
		$scoped = [];
		foreach ($rows as $row) {
			if ((string)($row['administrationId'] ?? '') === $administrationId) {
				$scoped[] = $row;
			}
		}

		return $scoped;

	}//end scopedTo()
}//end class
