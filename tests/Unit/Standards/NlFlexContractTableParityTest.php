<?php

/**
 * Table/legacy parity for the converted flexible-contract signals (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the fixed-term chain
 * and on-call offer signals of NlFlexContractChecks are declared decision
 * tables, and their pre-conversion closures stay as the parity oracle
 * (`NlFlexContractChecks::legacyChecks()`). This suite drives both over one
 * pinned matrix (chain length one to four with short, long and restarting
 * gaps, chains passing 36 months inside, at the edge of and beyond the
 * window, live, ended and future contracts; on-call contracts by age, end
 * and offer, unreadable starts and other types) and asserts identical
 * verdicts on every fixture. Dates are relative to today, as the rules read
 * them.
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

use OCA\Humaniq\Standards\Checks\NlFlexContractChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the flexible-contract signals.
 */
class NlFlexContractTableParityTest extends TestCase {

	/**
	 * A date relative to today.
	 *
	 * @param string $offset A strtotime offset.
	 *
	 * @return string
	 */
	private static function day(string $offset): string {
		return date('Y-m-d', strtotime($offset));

	}//end day()

	/**
	 * Chain fixtures: label => [contract under test, the employee's contracts].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}>
	 */
	private function chains(): array {
		$own = static fn (string $start, string $end): array => ['id' => 'cX', 'employeeId' => 'emp-1', 'type' => 'temporary', 'startDate' => self::day($start), 'endDate' => self::day($end)];
		$prior = static fn (string $id, string $start, string $end): array => ['id' => $id, 'type' => 'temporary', 'startDate' => self::day($start), 'endDate' => self::day($end)];
		$owns = [
			'live' => $own('-2 months', '+8 months'),
			'live long' => $own('-11 months', '+6 months'),
			'ended' => $own('-8 months', '-1 month'),
			'future' => $own('+1 month', '+6 months'),
		];
		$histories = [
			'alone' => [],
			'one short gap' => [$prior('c1', '-15 months', '-3 months')],
			'one long gap' => [$prior('c1', '-21 months', '-10 months')],
			'two short gaps' => [$prior('c1', '-26 months', '-16 months'), $prior('c2', '-15 months', '-3 months')],
			'three short gaps' => [$prior('c0', '-40 months', '-27 months'), $prior('c1', '-26 months', '-16 months'), $prior('c2', '-15 months', '-3 months')],
			'35 months back' => [$prior('c1', '-35 months', '-11 months -1 day')],
			'34 months back' => [$prior('c1', '-34 months', '-11 months -1 day')],
			'33 months back' => [$prior('c1', '-33 months', '-11 months -1 day')],
			'38 months back' => [$prior('c1', '-38 months', '-11 months -1 day')],
		];

		$fixtures = [];
		foreach ($owns as $oLabel => $contract) {
			foreach ($histories as $hLabel => $history) {
				$fixtures[$oLabel . ' / ' . $hLabel] = [$contract, array_merge($history, [$contract])];
			}
		}

		$fixtures['live, no id'] = [['employeeId' => 'emp-1', 'type' => 'temporary', 'startDate' => self::day('-2 months'), 'endDate' => self::day('+8 months')], $histories['two short gaps']];
		$fixtures['open-ended'] = [['id' => 'cX', 'employeeId' => 'emp-1', 'type' => 'permanent', 'startDate' => self::day('-2 months')], $histories['two short gaps']];
		$fixtures['unreadable dates'] = [['id' => 'cX', 'employeeId' => 'emp-1', 'startDate' => 'ooit', 'endDate' => 'later'], []];

		return $fixtures;

	}//end chains()

	/**
	 * On-call fixtures: label => contract.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function onCalls(): array {
		$fixtures = [];
		foreach (['oproep', 'temporary', ''] as $type) {
			foreach (['13 months' => '-13 months', '12 months' => '-12 months', '11 months' => '-11 months', 'unreadable' => 'onbekend', 'absent' => null] as $sLabel => $start) {
				foreach (['open' => null, 'running' => '+2 months', 'ended' => '-2 months', 'unreadable end' => 'straks'] as $eLabel => $end) {
					foreach (['offer' => self::day('-1 month'), 'no offer' => null, 'blank offer' => ' '] as $aLabel => $offer) {
						$contract = ['type' => $type];
						if ($start !== null) {
							$contract['startDate'] = ($start === 'onbekend' ? $start : self::day($start));
						}

						if ($end !== null) {
							$contract['endDate'] = ($end === 'straks' ? $end : self::day($end));
						}

						if ($offer !== null) {
							$contract['vasteUrenAanbodOp'] = $offer;
						}

						$fixtures[sprintf('%s start=%s end=%s %s', var_export($type, true), $sLabel, $eLabel, $aLabel)] = $contract;
					}
				}
			}
		}

		return $fixtures;

	}//end onCalls()

	/**
	 * Every fixture per rule: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$byRule = ['nl-signaal-ketenregeling' => [], 'nl-signaal-oproep-vaste-uren' => []];
		foreach ($this->chains() as $label => [$contract, $siblings]) {
			$byRule['nl-signaal-ketenregeling'][$label] = [$contract, ['signals' => ['contractsByEmployeeId' => ['emp-1' => $siblings]]]];
		}

		$byRule['nl-signaal-ketenregeling']['no index'] = [$this->chains()['live / two short gaps'][0], []];
		foreach ($this->onCalls() as $label => $contract) {
			$byRule['nl-signaal-oproep-vaste-uren'][$label] = [$contract, []];
		}

		return $byRule;

	}//end fixtures()

	/**
	 * Evaluate one table-declared check the way the engine's wrapper does.
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec    The declared check.
	 * @param array<string, mixed>                                 $object  The fixture.
	 * @param array<string, mixed>                                 $context The evaluation context.
	 *
	 * @return bool
	 */
	private function tableVerdict(array $spec, array $object, array $context): bool {
		$inputs = ($spec['derive'])($object, $context);
		if ($inputs === null) {
			return true;
		}

		return TableCheckEvaluator::satisfied($spec['table'], $inputs);

	}//end tableVerdict()

	/**
	 * Both rules answer identically through the table path and the legacy
	 * oracle, and each rule's matrix reaches both verdicts.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlFlexContractChecks::tables()['EmploymentContract'];
		$legacy = NlFlexContractChecks::legacyChecks()['EmploymentContract'];

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy rule has a table form and vice versa');

		foreach ($this->fixtures() as $ruleId => $fixtures) {
			$seen = [];
			foreach ($fixtures as $label => [$object, $context]) {
				$verdict = $this->tableVerdict($tables[$ruleId], $object, $context);
				$seen[$verdict === true ? 'pass' : 'fail'] = true;
				$this->assertSame(
					(bool)($legacy[$ruleId])($object, $context),
					$verdict,
					sprintf('%s: table and legacy verdicts diverge on fixture "%s"', $ruleId, $label)
				);
			}

			$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $ruleId . ': the matrix must reach both verdicts');
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * Only the table form is registered with the engine.
	 *
	 * @return void
	 */
	public function testNoClosureIsRegistered(): void {
		$this->assertSame([], NlFlexContractChecks::checks());

	}//end testNoClosureIsRegistered()

}//end class
