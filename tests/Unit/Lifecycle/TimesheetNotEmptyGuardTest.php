<?php

/**
 * TimesheetNotEmptyGuard unit tests
 *
 * Allow with bookings and hours; deny empty, zero-hours, and — fail-closed —
 * missing aggregates (hours-process-redesign, REQ-TEC-004's empty-submit
 * scenario).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Lifecycle;

use OCA\Hrmq\Lifecycle\TimesheetNotEmptyGuard;
use PHPUnit\Framework\TestCase;

/**
 * Submit is allowed only for a timesheet with bookings and hours.
 */
class TimesheetNotEmptyGuardTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var TimesheetNotEmptyGuard
	 */
	private TimesheetNotEmptyGuard $guard;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new TimesheetNotEmptyGuard();
	}//end setUp()

	/**
	 * A timesheet with bookings and hours may submit.
	 *
	 * @return void
	 */
	public function testAllowsSubmitWithEntriesAndHours(): void {
		$result = $this->guard->check(['entryCount' => 2, 'hours' => 12.5], 'submit', 'admin');
		$this->assertTrue($result->isAllowed());
	}//end testAllowsSubmitWithEntriesAndHours()

	/**
	 * Zero bookings deny, with a Dutch message naming the problem.
	 *
	 * @return void
	 */
	public function testDeniesEmptyTimesheet(): void {
		$result = $this->guard->check(['entryCount' => 0, 'hours' => 8], 'submit', 'admin');
		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('geen urenboekingen', (string)$result->getMessage());
	}//end testDeniesEmptyTimesheet()

	/**
	 * Bookings summing to zero hours deny.
	 *
	 * @return void
	 */
	public function testDeniesZeroHours(): void {
		$result = $this->guard->check(['entryCount' => 1, 'hours' => 0], 'submit', 'admin');
		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('nul uren', (string)$result->getMessage());
	}//end testDeniesZeroHours()

	/**
	 * Missing aggregates deny — fail closed, never allow on a guess.
	 *
	 * @return void
	 */
	public function testDeniesMissingAggregates(): void {
		$this->assertFalse($this->guard->check([], 'submit', 'admin')->isAllowed());
		$this->assertFalse($this->guard->check(['hours' => 8], 'submit', 'admin')->isAllowed());
		$this->assertFalse($this->guard->check(['entryCount' => 1], 'submit', 'admin')->isAllowed());
		$this->assertFalse(
			$this->guard->check(['entryCount' => 'not-a-number', 'hours' => 'x'], 'submit', 'admin')->isAllowed()
		);
	}//end testDeniesMissingAggregates()

}//end class
