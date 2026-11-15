<?php

/**
 * Golden test for CaoRegistry::overtimeToeslagPercentages over every shape of overtime leaf.
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
 * @spec openspec/specs/cao-library/spec.md#REQ-CAO-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\CaoRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The overtime percentages of every shape of overtime leaf.
 */
class CaoOvertimeGoldenTest extends TestCase {

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		CaoRegistry::reset();
	}//end tearDown()

	/**
	 * Every shape, read through a seeded registry.
	 *
	 * @return void
	 */
	public function testThePercentagesOfEveryShapeOfOvertimeLeaf(): void {
		$leaf = static fn (mixed $percentages, array $extra=[]): array => array_merge(['value' => ['toeslagPercentages' => $percentages], 'verified' => true], $extra);
		$caos = [
			'full' => ['overtime' => $leaf(['doordeweeks' => 25, 'zaterdag' => '50', 'zondag' => 100.5])],
			'mixed' => ['overtime' => $leaf(['doordeweeks' => 'veel', 7 => 30, 'zondag' => null])],
			'none-numeric' => ['overtime' => $leaf(['doordeweeks' => 'veel'])],
			'empty' => ['overtime' => $leaf([])],
			'not-a-list' => ['overtime' => $leaf('25%')],
			'unverified' => ['overtime' => $leaf(['doordeweeks' => 25], ['verified' => false])],
			'placeholder' => ['overtime' => $leaf(['doordeweeks' => 25], ['placeholder' => true])],
			'no-value' => ['overtime' => ['verified' => true]],
			'no-key' => ['overtime' => ['value' => ['other' => 1], 'verified' => true]],
			'value-scalar' => ['overtime' => ['value' => 'x', 'verified' => true]],
			'no-leaf' => [],
		];
		$cache = new ReflectionProperty(CaoRegistry::class, 'cache');
		$cache->setValue(null, $caos);

		$got = [];
		foreach (array_merge(array_keys($caos), ['unknown']) as $caoId) {
			$got[$caoId] = CaoRegistry::overtimeToeslagPercentages($caoId);
		}

		$this->assertSame(
			[
				'full' => ['doordeweeks' => 25.0, 'zaterdag' => 50.0, 'zondag' => 100.5],
				'mixed' => [7 => 30.0],
				'none-numeric' => null,
				'empty' => null,
				'not-a-list' => null,
				'unverified' => null,
				'placeholder' => null,
				'no-value' => null,
				'no-key' => null,
				'value-scalar' => null,
				'no-leaf' => null,
				'unknown' => null,
			],
			$got
		);
	}//end testThePercentagesOfEveryShapeOfOvertimeLeaf()
}//end class
