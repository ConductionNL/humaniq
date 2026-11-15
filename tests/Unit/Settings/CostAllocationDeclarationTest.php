<?php

/**
 * The CostAllocation and WageCostAllocation schemas, the aggregation the
 * wage costs page reads, and their demo objects.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The declarations payroll-cost-allocation relies on.
 */
class CostAllocationDeclarationTest extends TestCase {

	/**
	 * A fixed allocation over two cost centres is valid; an unknown basis
	 * is not.
	 *
	 * @return void
	 */
	public function testACostAllocationDeclaresItsBasisAndSplits(): void {
		$allocation = ['employeeId' => '5b1d3f4e-0000-4000-8000-000000000001', 'contractId' => null, 'basis' => 'fixed', 'splits' => [['costCenter' => 'CC-100', 'projectId' => null, 'percentage' => 60], ['costCenter' => 'CC-200', 'projectId' => 'PRJ-7', 'percentage' => 40]], 'startDate' => '2026-01-01', 'endDate' => null, 'administrationId' => 'ADM-001'];
		self::assertSame([], RegisterSchemaValidator::errors('CostAllocation', $allocation));
		self::assertNotSame([], RegisterSchemaValidator::errors('CostAllocation', array_merge($allocation, ['basis' => 'guess'])));
		self::assertNotSame([], RegisterSchemaValidator::errors('CostAllocation', array_merge($allocation, ['splits' => [['costCenter' => 'CC-100', 'percentage' => 120]]])));
	}//end testACostAllocationDeclaresItsBasisAndSplits()

	/**
	 * The wage cost lines declare the sum per cost centre and period.
	 *
	 * @return void
	 */
	public function testWageCostsAreAggregatedPerCostCentreAndPeriod(): void {
		$schema = RegisterSchemaValidator::schema('WageCostAllocation');
		$aggregation = $schema['configuration']['x-openregister-aggregations']['wageCostByCostCenter'];
		self::assertSame('sum', $aggregation['metric']);
		self::assertSame('totalCost', $aggregation['field']);
		self::assertSame(['costCenter', 'period'], $aggregation['groupBy']);
		self::assertSame(['fixed', 'hours', 'placement', 'placement-equal-split', 'unallocated'], $schema['properties']['allocationSource']['enum']);
	}//end testWageCostsAreAggregatedPerCostCentreAndPeriod()

	/**
	 * Both schemas are listed in the register, and each has three valid
	 * demo objects (gate 101).
	 *
	 * @return void
	 */
	public function testTheRegisterListsBothWithThreeDemoObjects(): void {
		$root = dirname(__DIR__, 3);
		$register = json_decode((string)file_get_contents($root . '/lib/Settings/humaniq_register.json'), true);
		$mock = json_decode((string)file_get_contents($root . '/lib/Settings/humaniq_mock_register.json'), true);
		foreach (['CostAllocation', 'WageCostAllocation'] as $name) {
			self::assertContains($name, $this->listedSchemas($register), $name);
			$demo = array_values(array_filter($mock['components']['objects'] ?? [], static fn (array $o): bool => ($o['@self']['schema'] ?? '') === $name));
			self::assertGreaterThanOrEqual(3, count($demo), $name);
			foreach ($demo as $object) {
				$payload = $object;
				unset($payload['@self']);
				self::assertSame([], RegisterSchemaValidator::errors($name, $payload), $name . ' ' . ($object['@self']['slug'] ?? ''));
			}
		}
	}//end testTheRegisterListsBothWithThreeDemoObjects()

	/**
	 * Every schema slug the register lists.
	 *
	 * @param array<string, mixed> $register The register document.
	 *
	 * @return list<string>
	 */
	private function listedSchemas(array $register): array {
		$found = [];
		array_walk_recursive(
			$register,
			static function (mixed $value, int|string $key) use (&$found): void {
				if (is_string($value) === true) {
					$found[] = $value;
				}
			}
		);

		return $found;
	}//end listedSchemas()

}//end class
