<?php

/**
 * Golden test for CompBandValidator: the verdict for every shape of band and salary.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CompBandValidator;
use PHPUnit\Framework\TestCase;

/**
 * The verdict for every shape of band and salary.
 */
class CompBandValidatorGoldenTest extends TestCase {

	/**
	 * Every shape, judged.
	 *
	 * @return void
	 */
	public function testTheVerdictForEveryShapeOfBand(): void {
		$band = ['minSalary' => 300000, 'maxSalary' => '420000'];
		$cases = [
			'inside' => [$band, 350000],
			'at-min' => [$band, 300000],
			'at-max' => [$band, 420000],
			'below' => [$band, 299999],
			'above' => [$band, 420001],
			'fractional-bounds' => [['minSalary' => '300000.9', 'maxSalary' => 420000.9], 420000],
			'no-band' => [null, 350000],
			'no-min' => [['maxSalary' => 420000], 350000],
			'text-max' => [['minSalary' => 300000, 'maxSalary' => 'veel'], 350000],
			'empty-band' => [[], 350000],
		];
		$validator = new CompBandValidator();
		$got = [];
		foreach ($cases as $name => [$candidate, $cents]) {
			$got[$name] = $validator->evaluate($candidate, $cents);
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/comp-band-verdicts.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheVerdictForEveryShapeOfBand()
}//end class
