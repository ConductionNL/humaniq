<?php

/**
 * Golden test for CaoComponents::normalise: every shape of declared component.
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\CaoComponents;
use PHPUnit\Framework\TestCase;

/**
 * Every shape of declared component, normalised.
 */
class CaoComponentsNormaliseGoldenTest extends TestCase {

	/**
	 * Every shape, normalised.
	 *
	 * @return void
	 */
	public function testEveryShapeOfDeclaredComponent(): void {
		$cases = [
			'percentage' => ['kind' => 'percentage-of-wage', 'pct' => '8.33', 'holidayPct' => 8, 'extra' => 'dropped'],
			'percentage-text' => ['kind' => 'percentage-of-wage', 'pct' => 'veel', 'holidayPct' => null],
			'amounts' => ['kind' => 'fixed-monthly', 'minAmountCents' => '1500.7', 'amountCents' => 2500, 'pct' => 1],
			'amounts-text' => ['kind' => 'fixed-monthly', 'amountCents' => 'x', 'minAmountCents' => null],
			'windows' => ['kind' => 'hourly-surcharge', 'windows' => ['a' => ['label' => 'Avond', 'days' => ['MA', 'Di'], 'from' => '20:00', 'to' => '24:00', 'pct' => '25'], 'b' => 'x', 'c' => ['from' => '7:00', 'to' => '25:00']]],
			'windows-not-a-list' => ['kind' => 'hourly-surcharge', 'windows' => 'nachts'],
			'unknown-kind' => ['kind' => 'other', 'pct' => 5],
			'no-kind' => ['pct' => 5],
			'not-an-array' => 'pct',
		];
		$got = [];
		foreach ($cases as $name => $raw) {
			$got[$name] = CaoComponents::normalise(raw: $raw);
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/cao-components-normalise.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
	}//end testEveryShapeOfDeclaredComponent()
}//end class
