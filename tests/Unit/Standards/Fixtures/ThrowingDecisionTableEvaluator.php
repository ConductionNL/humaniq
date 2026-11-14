<?php

/**
 * Throwing decision-table evaluator
 *
 * Stands in for an OpenRegister evaluator that refuses a table — an
 * unimplemented hit policy, a malformed expression — so the rule engine's
 * own posture towards a throwing predicate can be exercised without a
 * deliberately broken table in the shipped corpus.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Fixtures
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

namespace OCA\Humaniq\Tests\Unit\Standards\Fixtures;

use RuntimeException;

/**
 * An evaluator that refuses every table it is handed.
 */
class ThrowingDecisionTableEvaluator {

	/**
	 * Refuse the table.
	 *
	 * @param array<string, mixed> $table  The decision table (ignored).
	 * @param array<string, mixed> $inputs The derived inputs (ignored).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the signature mirrors the real evaluator.
	 */
	public function evaluate(array $table, array $inputs): array {
		throw new RuntimeException('hit policy not implemented');
	}//end evaluate()

}//end class
