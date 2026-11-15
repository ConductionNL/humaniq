<?php

/**
 * Rule Audit Tally
 *
 * Counts one audit run: per object type the objects checked, compliant and
 * with violations, the totals across types, the violations per severity and
 * per rule, and the fifteen most violated rules.
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
 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Standards\Violation;

/**
 * The counters of one RuleAuditService::audit() run.
 *
 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
 */
final class RuleAuditTally {

	/**
	 * The report being filled.
	 *
	 * @var array<string, mixed>
	 */
	private array $report;

	/**
	 * Violations per rule id.
	 *
	 * @var array<string, int>
	 */
	private array $byRule = [];

	/**
	 * @param array<string, mixed> $header The catalogue and coverage figures that open the report.
	 */
	public function __construct(array $header) {
		$this->report = $header + [
			'types' => [],
			'objectsChecked' => 0,
			'objectsCompliant' => 0,
			'objectsWithViolations' => 0,
			'violationsBySeverity' => ['mandatory' => 0, 'conditional' => 0, 'recommended' => 0],
			'topViolatedRules' => [],
		];

	}//end __construct()

	/**
	 * Open the counters of one object type.
	 *
	 * @param string $type The object type.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
	 */
	public function startType(string $type): void {
		$this->report['types'][$type] = ['checked' => 0, 'compliant' => 0, 'withViolations' => 0, 'violations' => 0];

	}//end startType()

	/**
	 * Count one checked object and its violations.
	 *
	 * @param string           $type       The object type.
	 * @param list<Violation>  $violations The object's violations.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
	 */
	public function record(string $type, array $violations): void {
		$this->report['types'][$type]['checked']++;
		$this->report['objectsChecked']++;

		if (empty($violations) === true) {
			$this->report['types'][$type]['compliant']++;
			$this->report['objectsCompliant']++;
			return;
		}

		$this->report['types'][$type]['withViolations']++;
		$this->report['objectsWithViolations']++;
		foreach ($violations as $violation) {
			$this->recordViolation($type, $violation);
		}

	}//end record()

	/**
	 * The finished report, with the fifteen most violated rules.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
	 */
	public function report(): array {
		arsort($this->byRule);
		foreach (array_slice($this->byRule, 0, 15, true) as $ruleId => $count) {
			$this->report['topViolatedRules'][] = ['ruleId' => $ruleId, 'count' => $count];
		}

		return $this->report;

	}//end report()

	/**
	 * Count one violation for its type, severity and rule.
	 *
	 * @param string    $type      The object type.
	 * @param Violation $violation The violation.
	 *
	 * @return void
	 */
	private function recordViolation(string $type, Violation $violation): void {
		$this->report['types'][$type]['violations']++;
		$this->report['violationsBySeverity'][$violation->severity] = (($this->report['violationsBySeverity'][$violation->severity] ?? 0) + 1);
		$this->byRule[$violation->ruleId] = (($this->byRule[$violation->ruleId] ?? 0) + 1);

	}//end recordViolation()
}//end class
