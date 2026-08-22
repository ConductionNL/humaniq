<?php

/**
 * Rule Violation
 *
 * A single failed machine-checkable rule, produced by RuleEngine when an object
 * does not satisfy an applicable rule from the RuleCatalogue. Carries the rule
 * id, its severity and source citation, and the rule statement, so a lifecycle
 * guard can block (on `mandatory`) or warn and the violation can be logged or
 * surfaced with full traceability back to the standard/law.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards;

use JsonSerializable;

/**
 * Immutable value object describing one violated rule.
 */
final class Violation implements JsonSerializable {

	/**
	 * @param string $ruleId The violated rule's catalogue id.
	 * @param string $severity `mandatory` | `conditional` | `recommended`.
	 * @param string $source Human citation (e.g. "WTD 2003/88/EC art. 6").
	 * @param string $statement The rule statement.
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $severity,
		public readonly string $source,
		public readonly string $statement,
	) {

	}//end __construct()

	/**
	 * @return array<string, string>
	 */
	public function jsonSerialize(): array {
		return [
			'ruleId' => $this->ruleId,
			'severity' => $this->severity,
			'source' => $this->source,
			'statement' => $this->statement,
		];

	}//end jsonSerialize()

}//end class
