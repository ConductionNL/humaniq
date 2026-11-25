<?php

/**
 * Unit tests for DateWindow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\DateWindow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Standards\Checks\DateWindow
 */
class DateWindowTest extends TestCase {

	/**
	 * Today and the last day are inside; yesterday and the day after are
	 * not; an unreadable date answers null.
	 *
	 * @return void
	 */
	public function testTheWindowIncludesBothEnds(): void {
		$day = static fn (string $offset): string => (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
		$window = new DateWindow();

		self::assertTrue($window->contains($day('today'), 14));
		self::assertTrue($window->contains($day('+14 days'), 14));
		self::assertFalse($window->contains($day('+15 days'), 14));
		self::assertFalse($window->contains($day('-1 day'), 14));
		self::assertTrue($window->contains($day('today'), 0));
		self::assertNull($window->contains('binnenkort', 14));
		self::assertNull($window->contains('', 14));
	}//end testTheWindowIncludesBothEnds()
}//end class
