<?php

/**
 * Table/legacy parity for the converted engine rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the table-version
 * and provenance rules of NlEngineChecks are declared decision tables, and
 * their pre-conversion closures stay as the parity oracle
 * (`NlEngineChecks::legacyChecks()`). This suite drives both over one pinned
 * matrix (engine versions bundled, versioned with `@`, unknown, padded,
 * blank or absent, with and without a calculation time; payslips of engine
 * runs, manual runs, unknown and blank run ids, snapshots as arrays, JSON,
 * broken JSON, empty or absent, jurisdictions matching, lower-cased,
 * different, blank or absent, runs of a bundled or an unbundled artefact)
 * and asserts identical verdicts. The output-consistency rule stays a
 * closure, which the last test pins.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlEngineChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the engine rules.
 */
class NlEngineTableParityTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		NlEngineChecks::resetBundledPackIdsCache();

	}//end setUp()

	/**
	 * The pinned matrix per rule: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$versions = ['bundled' => 'nl-2026', 'versioned' => 'nl-2026@3', 'padded' => ' nl-2026 ', 'unknown' => 'nl-2031', 'at only' => '@3', 'blank' => ' ', 'absent' => null];
		$fixtures = ['nl-engine-table-version' => [], 'nl-engine-provenance-complete' => []];

		foreach ($versions as $vLabel => $version) {
			foreach (['calculated' => '2026-07-14T10:00:00Z', 'blank' => ' ', 'absent' => null] as $cLabel => $calculatedAt) {
				$run = [];
				if ($version !== null) {
					$run['engineVersion'] = $version;
				}

				if ($calculatedAt !== null) {
					$run['calculatedAt'] = $calculatedAt;
				}

				$fixtures['nl-engine-table-version'][sprintf('version=%s calculated=%s', $vLabel, $cLabel)] = [$run, []];
			}
		}

		$runs = ['run-nl' => ['engineVersion' => 'nl-2026@3'], 'run-other' => ['engineVersion' => 'xx-9999'], 'run-manual' => ['engineVersion' => ''], 'run-odd' => 'x'];
		$snapshots = [
			'array NL' => ['jurisdiction' => 'NL'],
			'json lower' => '{"jurisdiction": " nl "}',
			'array BE' => ['jurisdiction' => 'BE'],
			'no jurisdiction' => ['other' => 1],
			'blank jurisdiction' => '{"jurisdiction": ""}',
			'broken json' => '{nope',
			'json scalar' => '"NL"',
			'empty array' => [],
			'blank' => '',
			'absent' => null,
		];
		foreach (['nl run' => 'run-nl', 'unbundled run' => 'run-other', 'manual run' => 'run-manual', 'odd run' => 'run-odd', 'unknown run' => 'run-9', 'padded run' => ' run-nl ', 'blank run' => '', 'no run' => null] as $rLabel => $runId) {
			foreach ($snapshots as $sLabel => $snapshot) {
				$payslip = [];
				if ($runId !== null) {
					$payslip['payrollRunId'] = $runId;
				}

				if ($snapshot !== null) {
					$payslip['engineInputSnapshot'] = $snapshot;
				}

				$fixtures['nl-engine-provenance-complete'][sprintf('%s snapshot=%s', $rLabel, $sLabel)] = [$payslip, ['payroll' => ['runsById' => $runs]]];
			}
		}

		return $fixtures;

	}//end fixtures()

	/**
	 * Both rules answer identically through the table path and the legacy
	 * oracle, and each matrix reaches both verdicts.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlEngineChecks::tables();
		$legacy = NlEngineChecks::legacyChecks();
		$fixtures = $this->fixtures();

		$this->assertSame(array_map('array_keys', $legacy), array_map('array_keys', $tables), 'every legacy rule has a table form and vice versa');

		foreach ($tables as $objectType => $specs) {
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($fixtures[$ruleId] as $label => [$object, $context]) {
					$inputs = ($spec['derive'])($object, $context);
					$verdict = ($inputs === null || TableCheckEvaluator::satisfied($spec['table'], $inputs) === true);
					$seen[$verdict === true ? 'pass' : 'fail'] = true;
					$this->assertSame(
						(bool)($legacy[$objectType][$ruleId])($object, $context),
						$verdict,
						sprintf('%s/%s: table and legacy verdicts diverge on fixture "%s"', $objectType, $ruleId, $label)
					);
				}

				$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $ruleId . ': the matrix must reach both verdicts');
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * Only the output-consistency formula stays a closure, and no rule is
	 * registered both ways.
	 *
	 * @return void
	 */
	public function testOnlyTheOutputConsistencyFormulaStaysAClosure(): void {
		$this->assertSame(['Payslip' => ['nl-engine-output-consistency']], array_map('array_keys', NlEngineChecks::checks()));
		$this->assertArrayNotHasKey('nl-engine-output-consistency', NlEngineChecks::tables()['Payslip']);

	}//end testOnlyTheOutputConsistencyFormulaStaysAClosure()

}//end class
