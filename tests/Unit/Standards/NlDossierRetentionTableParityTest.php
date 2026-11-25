<?php

/**
 * Table/legacy parity for the converted retention-ceiling rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the
 * `nl-bewaartermijn-verstreken` rule of NlDossierRetentionChecks is a declared
 * decision table on each of its shapes, and the pre-conversion predicates stay
 * as the parity oracle (`NlDossierRetentionChecks::legacyChecks()`). This
 * suite drives every covered schema over pinned matrices (archive status x
 * action date; document held x retained-until for both Employee documents;
 * case status x retention date) and asserts identical verdicts on every
 * fixture. Dates are relative to today, as the rule reads them.
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

use OCA\Humaniq\Standards\Checks\NlDossierRetentionChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the retention-ceiling rule.
 */
class NlDossierRetentionTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$day = static fn (string $offset): string => (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
		$dates = ['past' => $day('-1 day'), 'today' => $day('today'), 'future' => $day('+1 day'), 'unreadable' => 'ooit', 'blank' => ' ', 'absent' => null];

		$ceilings = [];
		foreach (['bewaren' => 'bewaren', 'vernietigd' => 'vernietigd', 'none' => null] as $sLabel => $status) {
			foreach ($dates as $dLabel => $date) {
				$ceilings[sprintf('archiefstatus=%s actiedatum=%s', $sLabel, $dLabel)] = [['@self' => ['retention' => ['archiefstatus' => $status, 'archiefactiedatum' => $date]]], []];
			}
		}

		$ceilings['no @self'] = [[], []];
		$ceilings['@self without retention'] = [['@self' => ['id' => 'x']], []];

		$employees = [];
		foreach (['held' => true, 'not held' => false, 'truthy' => 1] as $iLabel => $identity) {
			foreach ($dates as $iuLabel => $identityUntil) {
				foreach (['held' => true, 'not held' => false] as $vLabel => $verklaring) {
					foreach ($dates as $vuLabel => $verklaringUntil) {
						$employees[sprintf('identity %s until=%s, verklaring %s until=%s', $iLabel, $iuLabel, $vLabel, $vuLabel)] = [
							[
								'identityDocumentVerified' => $identity,
								'identityDocumentRetainedUntil' => $identityUntil,
								'loonheffingenVerklaringOnFile' => $verklaring,
								'loonheffingenVerklaringRetainedUntil' => $verklaringUntil,
							],
							[],
						];
					}
				}
			}
		}

		$cases = [];
		foreach (['afgesloten', 'open', null] as $status) {
			foreach ($dates as $dLabel => $date) {
				$cases[sprintf('%s retainedUntil=%s', var_export($status, true), $dLabel)] = [['status' => $status, 'retainedUntil' => $date], []];
			}
		}

		$fixtures = ['Employee' => $employees, 'EmployeeRelationsCase' => $cases];
		foreach (array_keys(NlDossierRetentionChecks::tables()) as $schema) {
			$fixtures[$schema] = ($fixtures[$schema] ?? $ceilings);
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
	 * Both rules answer identically through the table path and the legacy oracle.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlDossierRetentionChecks::tables();
		$legacy = NlDossierRetentionChecks::legacyChecks();
		$fixtures = $this->fixtures();

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy object type has a table form and vice versa');

		foreach ($tables as $objectType => $specs) {
			$this->assertSame(array_keys($legacy[$objectType]), array_keys($specs));
			foreach ($fixtures[$objectType] as $label => [$object, $context]) {
				foreach ($specs as $ruleId => $spec) {
					$this->assertSame(
						(bool)($legacy[$objectType][$ruleId])($object, $context),
						$this->tableVerdict($spec, $object, $context),
						sprintf('%s/%s: table and legacy verdicts diverge on fixture "%s"', $objectType, $ruleId, $label)
					);
				}
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * The matrix exercises both verdicts for every rule.
	 *
	 * @return void
	 */
	public function testTheMatrixExercisesBothVerdicts(): void {
		$fixtures = $this->fixtures();
		foreach (NlDossierRetentionChecks::tables() as $objectType => $specs) {
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($fixtures[$objectType] as [$object, $context]) {
					$seen[$this->tableVerdict($spec, $object, $context) === true ? 'pass' : 'fail'] = true;
				}

				$this->assertSame(
					['fail' => true, 'pass' => true],
					['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)],
					sprintf('%s/%s: the fixture matrix must exercise both verdicts', $objectType, $ruleId)
				);
			}
		}

	}//end testTheMatrixExercisesBothVerdicts()

	/**
	 * Only the table form is registered.
	 *
	 * @return void
	 */
	public function testTheRulesAreTableDeclaredOnly(): void {
		$this->assertSame([], NlDossierRetentionChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
