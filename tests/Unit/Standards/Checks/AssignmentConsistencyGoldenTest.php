<?php

/**
 * Golden test for the asset-issue and org-placement assignment-consistency checks.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/specs/mss-team-scope/spec.md#REQ-MSS-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlAssetChecks;
use OCA\Humaniq\Standards\Checks\NlOrgChecks;
use PHPUnit\Framework\TestCase;

/**
 * The assignment-consistency verdicts for every shape of issue and placement.
 */
class AssignmentConsistencyGoldenTest extends TestCase {

	/**
	 * The one predicate registered under a rule id.
	 *
	 * @param array<string, array<string, callable>> $checks The provider's checks.
	 * @param string $ruleId The rule id.
	 *
	 * @return callable
	 */
	private function predicate(array $checks, string $ruleId): callable {
		foreach ($checks as $bySchema) {
			if (isset($bySchema[$ruleId]) === true) {
				return $bySchema[$ruleId];
			}
		}

		self::fail('No predicate ' . $ruleId);
	}//end predicate()

	/**
	 * Every shape of asset issue.
	 *
	 * @return void
	 */
	public function testTheAssetIssueVerdictForEveryShape(): void {
		$check = $this->predicate(NlAssetChecks::checks(), 'nl-asset-assignment-consistency');
		$context = ['related' => ['Asset' => ['byId' => ['as-1' => ['status' => 'issued'], 'as-2' => ['status' => 'available'], 'as-3' => 'x']], 'Employee' => ['byId' => ['emp-1' => []]]]];
		$issue = ['assetId' => 'as-1', 'employeeId' => 'emp-1', 'issuedOn' => '2026-01-10'];
		$cases = [
			'open' => [$issue, $context],
			'returned-in-order' => [array_merge($issue, ['assetId' => 'gone', 'returnedOn' => '2026-02-01']), $context],
			'returned-same-day' => [array_merge($issue, ['returnedOn' => '2026-01-10']), $context],
			'returned-before-issue' => [array_merge($issue, ['returnedOn' => '2026-01-09']), $context],
			'returned-bad-issue-date' => [array_merge($issue, ['issuedOn' => 'ooit', 'returnedOn' => '2026-01-09']), $context],
			'returned-bad-return-date' => [array_merge($issue, ['returnedOn' => 'later']), $context],
			'no-asset' => [array_merge($issue, ['assetId' => ' ']), $context],
			'unknown-asset' => [array_merge($issue, ['assetId' => 'as-9']), $context],
			'asset-not-issued' => [array_merge($issue, ['assetId' => 'as-2']), $context],
			'asset-odd' => [array_merge($issue, ['assetId' => 'as-3']), $context],
			'no-employee' => [array_merge($issue, ['employeeId' => '']), $context],
			'unknown-employee' => [array_merge($issue, ['employeeId' => 'emp-9']), $context],
			'padded-ids' => [array_merge($issue, ['assetId' => ' as-1 ', 'employeeId' => ' emp-1 ']), $context],
			'no-context' => [$issue, []],
		];
		$got = [];
		foreach ($cases as $name => [$o, $c]) {
			$got[$name] = $check($o, $c);
		}

		$this->assertSame(
			[
				'open' => true,
				'returned-in-order' => true,
				'returned-same-day' => true,
				'returned-before-issue' => false,
				'returned-bad-issue-date' => true,
				'returned-bad-return-date' => true,
				'no-asset' => false,
				'unknown-asset' => false,
				'asset-not-issued' => false,
				'asset-odd' => false,
				'no-employee' => false,
				'unknown-employee' => false,
				'padded-ids' => true,
				'no-context' => false,
			],
			$got
		);
	}//end testTheAssetIssueVerdictForEveryShape()

	/**
	 * Every shape of org placement.
	 *
	 * @return void
	 */
	public function testThePlacementVerdictForEveryShape(): void {
		$check = $this->predicate(NlOrgChecks::checks(), 'nl-org-assignment-consistency');
		$context = ['related' => ['OrgUnit' => ['byId' => ['u-1' => ['active' => true], 'u-2' => ['active' => false], 'u-3' => ['active' => 'yes'], 'u-4' => 'x']]]];
		$placement = ['orgUnitId' => 'u-1', 'startDate' => '2025-01-01'];
		$cases = [
			'open' => [$placement, $context],
			'ends-later' => [array_merge($placement, ['endDate' => '2099-12-31']), $context],
			'ended' => [array_merge($placement, ['orgUnitId' => 'gone', 'endDate' => '2025-06-30']), $context],
			'ends-before-start' => [array_merge($placement, ['endDate' => '2024-12-31']), $context],
			'ends-before-start-future' => [array_merge($placement, ['startDate' => '2100-01-01', 'endDate' => '2099-12-31']), $context],
			'bad-start' => [array_merge($placement, ['startDate' => 'ooit', 'endDate' => '2024-12-31']), $context],
			'bad-end' => [array_merge($placement, ['endDate' => 'later']), $context],
			'no-unit' => [array_merge($placement, ['orgUnitId' => '']), $context],
			'unknown-unit' => [array_merge($placement, ['orgUnitId' => 'u-9']), $context],
			'inactive-unit' => [array_merge($placement, ['orgUnitId' => 'u-2']), $context],
			'truthy-unit' => [array_merge($placement, ['orgUnitId' => 'u-3']), $context],
			'odd-unit' => [array_merge($placement, ['orgUnitId' => 'u-4']), $context],
			'padded-unit' => [array_merge($placement, ['orgUnitId' => ' u-1 ']), $context],
			'no-context' => [$placement, []],
		];
		$got = [];
		foreach ($cases as $name => [$o, $c]) {
			$got[$name] = $check($o, $c);
		}

		$this->assertSame(
			[
				'open' => true,
				'ends-later' => true,
				'ended' => true,
				'ends-before-start' => false,
				'ends-before-start-future' => false,
				'bad-start' => true,
				'bad-end' => true,
				'no-unit' => false,
				'unknown-unit' => false,
				'inactive-unit' => false,
				'truthy-unit' => false,
				'odd-unit' => false,
				'padded-unit' => true,
				'no-context' => false,
			],
			$got
		);
	}//end testThePlacementVerdictForEveryShape()
}//end class
