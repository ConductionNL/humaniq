<?php

/**
 * Table/legacy parity for the converted org-placement rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables:
 * `nl-org-assignment-consistency` of NlOrgChecks is a declared decision
 * table, and its pre-conversion closure stays as the parity oracle
 * (`NlOrgChecks::legacyChecks()`). This suite drives both over one pinned
 * matrix (start and end dates in order, reversed, equal, unreadable, blank or
 * absent, ending in the past, today or the future; unit active, inactive,
 * truthy, malformed, unknown, blank or absent; index missing) and asserts
 * identical verdicts. The unit-cycle and manager-consistency rules stay
 * closures, which the last test pins.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlOrgChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the org-placement rule.
 */
class NlOrgTableParityTest extends TestCase {

	/**
	 * The pinned OrgAssignment matrix: label => [object, context].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	private function fixtures(): array {
		$day = static fn (string $offset): string => (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
		$units = ['u-on' => ['active' => true], 'u-off' => ['active' => false], 'u-truthy' => ['active' => 1], 'u-odd' => 'x'];
		$contexts = ['index' => ['related' => ['OrgUnit' => ['byId' => $units]]], 'no index' => [], 'broken index' => ['related' => ['OrgUnit' => ['byId' => 'x']]]];
		$starts = ['past' => $day('-1 year'), 'future' => $day('+1 year'), 'unreadable' => 'ooit', 'blank' => ' ', 'absent' => null];
		$ends = ['open' => null, 'blank' => '', 'past' => $day('-1 month'), 'today' => $day('today'), 'future' => $day('+2 years'), 'before past start' => $day('-2 years'), 'unreadable' => 'later'];
		$unitIds = ['active' => 'u-on', 'padded active' => ' u-on ', 'inactive' => 'u-off', 'truthy' => 'u-truthy', 'odd' => 'u-odd', 'unknown' => 'u-9', 'blank' => '', 'absent' => null];

		$fixtures = [];
		foreach ($starts as $sLabel => $start) {
			foreach ($ends as $eLabel => $end) {
				foreach ($unitIds as $uLabel => $unitId) {
					foreach ($contexts as $cLabel => $context) {
						$placement = [];
						foreach (['startDate' => $start, 'endDate' => $end, 'orgUnitId' => $unitId] as $field => $value) {
							if ($value !== null) {
								$placement[$field] = $value;
							}
						}

						$fixtures[sprintf('start=%s end=%s unit=%s %s', $sLabel, $eLabel, $uLabel, $cLabel)] = [$placement, $context];
					}
				}
			}
		}

		return $fixtures;

	}//end fixtures()

	/**
	 * The table path agrees with the legacy oracle on every fixture and
	 * reaches both verdicts.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$spec = NlOrgChecks::tables()['OrgAssignment']['nl-org-assignment-consistency'];
		$legacy = NlOrgChecks::legacyChecks()['OrgAssignment']['nl-org-assignment-consistency'];

		$this->assertSame(['OrgAssignment' => ['nl-org-assignment-consistency']], array_map('array_keys', NlOrgChecks::legacyChecks()));
		$this->assertSame(['OrgAssignment' => ['nl-org-assignment-consistency']], array_map('array_keys', NlOrgChecks::tables()));

		$seen = [];
		foreach ($this->fixtures() as $label => [$object, $context]) {
			$inputs = ($spec['derive'])($object, $context);
			$verdict = ($inputs === null || TableCheckEvaluator::satisfied($spec['table'], $inputs) === true);
			$seen[$verdict === true ? 'pass' : 'fail'] = true;
			$this->assertSame((bool)$legacy($object, $context), $verdict, sprintf('table and legacy verdicts diverge on fixture "%s"', $label));
		}

		$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], 'the matrix must reach both verdicts');

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * The graph rules stay closures, and no rule is registered both ways.
	 *
	 * @return void
	 */
	public function testOnlyTheGraphRulesStayClosures(): void {
		$closures = NlOrgChecks::checks();
		$this->assertSame(
			['OrgUnit' => ['nl-org-unit-cycle'], 'Timesheet' => ['nl-mss-manager-consistency'], 'Expense' => ['nl-mss-manager-consistency'], 'LeaveRequest' => ['nl-mss-manager-consistency']],
			array_map('array_keys', $closures)
		);
		$this->assertArrayNotHasKey('OrgAssignment', $closures);

	}//end testOnlyTheGraphRulesStayClosures()

}//end class
