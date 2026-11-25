<?php

/**
 * Table/legacy parity for the converted administratie-scope rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables:
 * `nl-administratie-scope-consistency` of NlAdministratieChecks is a declared
 * decision table on Payslip and on every Employee-anchored type, and the
 * pre-conversion closures stay as the parity oracle
 * (`NlAdministratieChecks::legacyChecks()`). This suite drives both over one
 * pinned matrix (own administratie equal, different, padded, blank or absent;
 * parent reference present, blank, padded, unknown or absent; parent with an
 * equal, different, blank or absent administratie; index missing or not an
 * array) on every registered type and asserts identical verdicts.
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

use OCA\Humaniq\Standards\Checks\NlAdministratieChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the administratie-scope rule.
 */
class NlAdministratieTableParityTest extends TestCase {

	/**
	 * The pinned matrix for one type: label => [object, context].
	 *
	 * @param string $refField The field naming the parent.
	 * @param bool   $viaRun   True when the parent index is `payroll.runsById`.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	private function fixtures(string $refField, bool $viaRun): array {
		$index = static fn ($parents): array => ($viaRun === true ? ['payroll' => ['runsById' => $parents]] : ['related' => ['Employee' => ['byId' => $parents]]]);
		$parentsBy = [
			'equal parent' => ['p-1' => ['administrationId' => 'ADM-001']],
			'other parent' => ['p-1' => ['administrationId' => 'ADM-002']],
			'padded parent' => ['p-1' => ['administrationId' => ' ADM-001 ']],
			'blank parent' => ['p-1' => ['administrationId' => '']],
			'parent without field' => ['p-1' => []],
			'blank-key parent' => ['' => ['administrationId' => 'ADM-002']],
		];
		$contexts = ['no index' => [], 'broken index' => $index('kapot')];
		foreach ($parentsBy as $label => $parents) {
			$contexts[$label] = $index($parents);
		}

		$fixtures = [];
		foreach (['ADM-001' => 'ADM-001', 'padded' => ' ADM-001', 'other' => 'ADM-003', 'blank' => '', 'absent' => null] as $oLabel => $own) {
			foreach (['ref' => 'p-1', 'padded ref' => ' p-1 ', 'unknown ref' => 'p-9', 'blank ref' => '', 'absent ref' => null] as $rLabel => $ref) {
				foreach ($contexts as $cLabel => $context) {
					$object = [];
					if ($own !== null) {
						$object['administrationId'] = $own;
					}

					if ($ref !== null) {
						$object[$refField] = $ref;
					}

					$fixtures[sprintf('own=%s %s %s', $oLabel, $rLabel, $cLabel)] = [$object, $context];
				}
			}
		}

		return $fixtures;

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
	 * The rule answers identically through the table path and the legacy
	 * oracle on every type, and reaches both verdicts on each.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryType(): void {
		$tables = NlAdministratieChecks::tables();
		$legacy = NlAdministratieChecks::legacyChecks();

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy type has a table form and vice versa');
		$this->assertSame([], NlAdministratieChecks::checks());

		foreach ($tables as $objectType => $specs) {
			$this->assertSame(array_keys($legacy[$objectType]), array_keys($specs));
			$viaRun = ($objectType === 'Payslip');
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($this->fixtures($viaRun === true ? 'payrollRunId' : 'employeeId', $viaRun) as $label => [$object, $context]) {
					$verdict = $this->tableVerdict($spec, $object, $context);
					$seen[$verdict === true ? 'pass' : 'fail'] = true;
					$this->assertSame(
						(bool)($legacy[$objectType][$ruleId])($object, $context),
						$verdict,
						sprintf('%s/%s: table and legacy verdicts diverge on fixture "%s"', $objectType, $ruleId, $label)
					);
				}

				$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $objectType . ': the matrix must reach both verdicts');
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryType()

}//end class
