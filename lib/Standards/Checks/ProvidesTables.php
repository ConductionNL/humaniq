<?php

/**
 * Provides Tables (optional CheckProvider capability)
 *
 * A CheckProvider whose rules are tabular (thresholds, enumerations, boolean
 * gates over derived values) implements this to declare them as OpenRegister
 * decision tables instead of opaque PHP closures. The table travels in the
 * exact inline grammar the `openregister.decision-table` flow node consumes,
 * so matching is the shared engine's job and the same definition could later
 * run in a flow step unchanged. The provider keeps only the domain half: a
 * `derive` callable that computes the table's inputs from the object.
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
 * Optional capability: declare checks as OpenRegister decision tables.
 */
interface ProvidesTables {
	/**
	 * Table-declared checks, keyed by object type then catalogue rule id. Each
	 * entry carries:
	 *
	 * - `derive`: `callable(array $object, array $context): ?array` — computes
	 *   the table's declared inputs from the object (domain semantics: date
	 *   arithmetic, statutory formulas, sums). Returning `null` means the rule
	 *   is not decidable from this object alone and passes vacuously, per the
	 *   corpus' machineCheckable discipline.
	 * - `table`: the decision table in OpenRegister's inline grammar
	 *   (`hitPolicy`, `inputs`, `outputs`, `rules`), declaring a boolean
	 *   `satisfied` output the engine reads. Use `hitPolicy: FIRST` with a
	 *   final catch-all rule so every derivable input row decides.
	 *
	 * Every rule id MUST be a real RuleCatalogue id, exactly as with closure
	 * checks.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function tables(): array;
}//end interface
