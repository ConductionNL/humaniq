<?php

/**
 * Engine Input Snapshot
 *
 * Reads a payslip's `engineInputSnapshot`, the record of the inputs the
 * payroll engine calculated from. Shared by NlEngineChecks'
 * `nl-engine-provenance-complete` table derivation and the closure kept as
 * its parity oracle. Pure and stateless.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Decoder for the engine input snapshot.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
final class EngineInputSnapshot {

	/**
	 * Decode an `engineInputSnapshot` value to an array, or null when
	 * missing/undecodable.
	 *
	 * Accepts EITHER a raw JSON string (a hand-built test fixture, or a
	 * direct-SQL read) OR an already-decoded array — live-verified against
	 * 8080: `OCA\OpenRegister\Db\MagicMapper::rowToObjectEntity()` blanket
	 * `json_decode()`s any string column value that happens to parse as
	 * valid JSON, regardless of the schema's declared `type: string`, so
	 * every real `ObjectService`/`RuleAuditService` read of this field
	 * returns it pre-decoded.
	 *
	 * @param mixed $snapshot The raw `engineInputSnapshot` field value.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public function decode(mixed $snapshot): ?array {
		if ($snapshot === null || $snapshot === '' || $snapshot === []) {
			return null;
		}

		if (is_array($snapshot) === true) {
			return $snapshot;
		}

		$decoded = json_decode(trim((string)$snapshot), true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			return null;
		}

		return $decoded;

	}//end decode()

}//end class
