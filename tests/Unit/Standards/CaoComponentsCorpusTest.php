<?php

/**
 * The CAO corpus describes its components in one computable shape.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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

use OCA\Humaniq\Standards\CaoRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Every allowance is one of three kinds, keeps its figures and flags, and
 * resolves only from a confirmed leaf.
 */
class CaoComponentsCorpusTest extends TestCase {

	/**
	 * The percentages each file carried before the rewrite, and its flags.
	 *
	 * @var array<string, array{pcts: list<float>, verified: bool, placeholder: bool}>
	 */
	private const BEFORE = [
		'cao-abu' => ['pcts' => [0.0], 'verified' => false, 'placeholder' => true],
		'cao-gemeenten' => ['pcts' => [], 'verified' => false, 'placeholder' => true],
		'cao-generiek' => ['pcts' => [], 'verified' => true, 'placeholder' => false],
		'cao-horeca' => ['pcts' => [15.0], 'verified' => false, 'placeholder' => true],
		'cao-metaal-techniek' => ['pcts' => [13.3], 'verified' => false, 'placeholder' => true],
		'cao-onderwijs-po' => ['pcts' => [], 'verified' => false, 'placeholder' => true],
		'cao-onderwijs-vo' => ['pcts' => [], 'verified' => false, 'placeholder' => true],
		'cao-rijk' => ['pcts' => [16.5], 'verified' => true, 'placeholder' => false],
		'cao-voorbeeld' => ['pcts' => [10.0], 'verified' => true, 'placeholder' => false],
		'cao-ziekenhuizen' => ['pcts' => [20.0, 40.0, 35.0, 65.0], 'verified' => false, 'placeholder' => true],
		'cao-zorg-vvt' => ['pcts' => [47.0, 52.0], 'verified' => false, 'placeholder' => true],
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		CaoRegistry::reset();
	}//end setUp()

	/**
	 * Every entry is one of the three kinds, and each file keeps its
	 * percentages and its verified and placeholder flags.
	 *
	 * @return void
	 */
	public function testEveryAllowanceHasAKindAndKeepsItsFigures(): void {
		foreach (self::BEFORE as $caoId => $before) {
			$leaf = CaoRegistry::get($caoId)['allowances'];
			$this->assertSame($before['verified'], ($leaf['verified'] ?? false), $caoId);
			$this->assertSame($before['placeholder'], ($leaf['placeholder'] ?? false), $caoId);

			$pcts = [];
			foreach ((array)$leaf['value'] as $key => $component) {
				$this->assertContains($component['kind'] ?? null, ['percentage-of-wage', 'fixed-monthly', 'hourly-surcharge'], $caoId . ' ' . $key);
				if (isset($component['pct']) === true) {
					$pcts[] = (float)$component['pct'];
				}

				foreach ((array)($component['windows'] ?? []) as $window) {
					$pcts[] = (float)$window['pct'];
				}

				if (isset($component['holidayPct']) === true) {
					$pcts[] = (float)$component['holidayPct'];
				}
			}

			foreach ($before['pcts'] as $pct) {
				$this->assertContains($pct, $pcts, $caoId);
			}
		}//end foreach
	}//end testEveryAllowanceHasAKindAndKeepsItsFigures()

	/**
	 * A placeholder leaf resolves to null; the fictional example resolves its
	 * shift allowance and its night premium.
	 *
	 * @return void
	 */
	public function testComponentsResolveOnlyFromAConfirmedLeaf(): void {
		$this->assertNull(CaoRegistry::components('cao-metaal-techniek'));
		$this->assertNull(CaoRegistry::components('cao-zorg-vvt'));
		$this->assertNull(CaoRegistry::components('cao-onbekend'));
		$this->assertNull(CaoRegistry::components('cao-generiek'));

		$components = CaoRegistry::components('cao-voorbeeld');
		$this->assertSame(['kind' => 'percentage-of-wage', 'pct' => 10.0], array_intersect_key($components['ploegentoeslag'], ['kind' => 1, 'pct' => 1]));
		$this->assertSame('hourly-surcharge', $components['nachttoeslag']['kind']);
		$this->assertSame('00:00', $components['nachttoeslag']['windows'][0]['from']);
		$this->assertSame(40.0, $components['nachttoeslag']['windows'][0]['pct']);

		$this->assertSame(['ploegentoeslag'], array_keys(CaoRegistry::componentShapes('cao-metaal-techniek')));
		$this->assertSame([], CaoRegistry::componentShapes('cao-onbekend'));
	}//end testComponentsResolveOnlyFromAConfirmedLeaf()

}//end class
