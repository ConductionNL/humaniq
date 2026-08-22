<?php

/**
 * Unit tests for CompEffectiveDateGuard.
 *
 * Pins the fail-closed contract of the CompAdjustment `effectuate` lifecycle
 * guard (comp-cycles): a present, on-or-before-today effectiveDate allows;
 * an empty, malformed, or future-dated effectiveDate all deny.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\CompEffectiveDateGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CompEffectiveDateGuard.
 *
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-005
 */
class CompEffectiveDateGuardTest extends TestCase {

	/**
	 * @return void
	 */
	public function testTodayEffectiveDateAllows(): void {
		$guard = new CompEffectiveDateGuard();
		$result = $guard->check(['effectiveDate' => gmdate('Y-m-d')], 'effectuate', 'alice');

		$this->assertTrue($result->isAllowed());

	}//end testTodayEffectiveDateAllows()

	/**
	 * @return void
	 */
	public function testPastEffectiveDateAllows(): void {
		$guard = new CompEffectiveDateGuard();
		$result = $guard->check(['effectiveDate' => '2020-01-01'], 'effectuate', 'alice');

		$this->assertTrue($result->isAllowed());

	}//end testPastEffectiveDateAllows()

	/**
	 * @return void
	 */
	public function testFutureEffectiveDateDenies(): void {
		$tomorrow = gmdate('Y-m-d', (strtotime('today') + 86400));

		$guard = new CompEffectiveDateGuard();
		$result = $guard->check(['effectiveDate' => $tomorrow], 'effectuate', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('toekomst', (string)$result->getMessage());

	}//end testFutureEffectiveDateDenies()

	/**
	 * @return void
	 */
	public function testEmptyEffectiveDateDenies(): void {
		$guard = new CompEffectiveDateGuard();
		$result = $guard->check(['effectiveDate' => ''], 'effectuate', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testEmptyEffectiveDateDenies()

	/**
	 * @return void
	 */
	public function testMissingEffectiveDateKeyDenies(): void {
		$guard = new CompEffectiveDateGuard();
		$result = $guard->check([], 'effectuate', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testMissingEffectiveDateKeyDenies()

	/**
	 * @return void
	 */
	public function testMalformedEffectiveDateDenies(): void {
		$guard = new CompEffectiveDateGuard();
		$result = $guard->check(['effectiveDate' => 'not-a-date'], 'effectuate', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testMalformedEffectiveDateDenies()

}//end class
