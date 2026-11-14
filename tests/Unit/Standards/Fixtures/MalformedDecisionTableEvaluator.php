<?php

/**
 * Malformed decision-table evaluator
 *
 * Stands in for an OpenRegister evaluator whose result does not carry the
 * `outputs` map the delegate expects. No real evaluator returns this shape,
 * which is exactly why the fail-closed branch that handles it needs a stand-in
 * to be exercised at all.
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

/**
 * An evaluator returning a result the delegate cannot read.
 */
class MalformedDecisionTableEvaluator {

	/**
	 * The shape every instance returns. Set before the evaluator is resolved.
	 *
	 * @var mixed
	 */
	public static mixed $result = null;

	/**
	 * Return the configured malformed result, whatever it is.
	 *
	 * @param array<string, mixed> $table  The decision table (ignored).
	 * @param array<string, mixed> $inputs The derived inputs (ignored).
	 *
	 * @return mixed
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the signature mirrors the real evaluator.
	 */
	public function evaluate(array $table, array $inputs): mixed {
		return self::$result;
	}//end evaluate()

}//end class
