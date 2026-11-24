<?php

/**
 * Tests for BuildsSatisfiedTables.
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\BuildsSatisfiedTables;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FIRST-hit `satisfied` table builder.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
class BuildsSatisfiedTablesTest extends TestCase {

	/**
	 * Build a table through the trait.
	 *
	 * @param array<int, array<string, string>> $inputs The declared inputs.
	 * @param array<int, array<string, mixed>>  $rules  The deciding rows.
	 *
	 * @return array<string, mixed>
	 */
	private function table(array $inputs, array $rules): array {
		$builder = new class {
			use BuildsSatisfiedTables;

			/**
			 * Expose the trait's builder.
			 *
			 * @param array<int, array<string, string>> $inputs The declared inputs.
			 * @param array<int, array<string, mixed>>  $rules  The deciding rows.
			 *
			 * @return array<string, mixed>
			 */
			public function build(array $inputs, array $rules): array {
				return self::satisfiedTable($inputs, $rules);
			}//end build()
		};

		return $builder->build($inputs, $rules);

	}//end table()

	/**
	 * The shape: FIRST hit, the inputs as given, one boolean output, the rows then a catch-all.
	 *
	 * @return void
	 */
	public function testShapeEndsWithACatchAllSatisfiedRow(): void {
		$inputs = [['name' => 'a', 'type' => 'boolean'], ['name' => 'b', 'type' => 'string']];
		$row = ['id' => 'violated', 'inputEntries' => ['true', '"x"'], 'outputEntries' => [false]];

		$table = $this->table($inputs, [$row]);

		$this->assertSame('FIRST', $table['hitPolicy']);
		$this->assertSame($inputs, $table['inputs']);
		$this->assertSame([['name' => 'satisfied', 'type' => 'boolean']], $table['outputs']);
		$this->assertSame([$row, ['id' => 'satisfied', 'inputEntries' => ['-', '-'], 'outputEntries' => [true]]], $table['rules']);

	}//end testShapeEndsWithACatchAllSatisfiedRow()

	/**
	 * Through the shared evaluator: the deciding row wins, anything else falls to the catch-all.
	 *
	 * @return void
	 */
	public function testDecidingRowWinsAndEverythingElsePasses(): void {
		$table = $this->table(
			[['name' => 'a', 'type' => 'boolean'], ['name' => 'b', 'type' => 'string']],
			[['id' => 'violated', 'inputEntries' => ['true', '"x"'], 'outputEntries' => [false]]]
		);

		$this->assertFalse(TableCheckEvaluator::satisfied($table, ['a' => true, 'b' => 'x']));
		$this->assertTrue(TableCheckEvaluator::satisfied($table, ['a' => false, 'b' => 'x']));
		$this->assertTrue(TableCheckEvaluator::satisfied($table, ['a' => true, 'b' => 'y']));

	}//end testDecidingRowWinsAndEverythingElsePasses()

}//end class
