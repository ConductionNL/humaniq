<?php

/**
 * Builds Satisfied Tables
 *
 * Builds the decision-table shape every table-declared compliance check uses
 * (ProvidesTables): FIRST hit policy, the declared inputs, one boolean
 * `satisfied` output, the deciding rows, then a catch-all satisfied row so
 * every derivable input row decides (no `no_rule_matched`). The result is in
 * the exact inline grammar the `openregister.decision-table` flow node
 * consumes.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Builder for FIRST-hit `satisfied` decision tables, mixed into the
 * table-declaring providers.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
trait BuildsSatisfiedTables {

	/**
	 * The decision table: the given rows, then a catch-all satisfied row.
	 *
	 * @param array<int, array<string, string>> $inputs The declared inputs (`name`, `type`).
	 * @param array<int, array<string, mixed>>  $rules  The deciding rows, before the catch-all.
	 *
	 * @return array<string, mixed> The decision table.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function satisfiedTable(array $inputs, array $rules): array {
		$rules[] = ['id' => 'satisfied', 'inputEntries' => array_fill(0, count($inputs), '-'), 'outputEntries' => [true]];

		return [
			'hitPolicy' => 'FIRST',
			'inputs' => $inputs,
			'outputs' => [
				['name' => 'satisfied', 'type' => 'boolean'],
			],
			'rules' => $rules,
		];

	}//end satisfiedTable()
}//end trait
