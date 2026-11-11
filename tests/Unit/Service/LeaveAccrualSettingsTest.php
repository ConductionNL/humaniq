<?php

/**
 * Leave accrual settings test
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
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\LeaveAccrualSettings;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Whether the leave accrual job runs, and the above-statutory hours it accrues.
 *
 * @covers \OCA\Humaniq\Service\LeaveAccrualSettings
 * @uses   \OCA\Humaniq\Tests\Unit\Support\AppConfigValues
 */
class LeaveAccrualSettingsTest extends TestCase {

	/**
	 * The job is on unless switched off.
	 *
	 * @return void
	 */
	public function testTheJobIsOnByDefault(): void {
		self::assertTrue($this->settings([])->isLeaveAccrualEnabled());
		self::assertFalse($this->settings(['leave_accrual_enabled' => false])->isLeaveAccrualEnabled());
	}//end testTheJobIsOnByDefault()

	/**
	 * The annual above-statutory hours default to 0 and are never negative or non-numeric.
	 *
	 * @return void
	 */
	public function testTheAnnualBovenwettelijkHours(): void {
		self::assertSame(0.0, $this->settings([])->getLeaveBovenwettelijkAnnualHours());
		self::assertSame(13.33, $this->settings(['leave_bovenwettelijk_annual_hours' => ' 13.33 '])->getLeaveBovenwettelijkAnnualHours());
		self::assertSame(0.0, $this->settings(['leave_bovenwettelijk_annual_hours' => '-4'])->getLeaveBovenwettelijkAnnualHours());
		self::assertSame(0.0, $this->settings(['leave_bovenwettelijk_annual_hours' => 'veel'])->getLeaveBovenwettelijkAnnualHours());
		self::assertSame(0.0, $this->settings(['leave_bovenwettelijk_annual_hours' => ''])->getLeaveBovenwettelijkAnnualHours());
	}//end testTheAnnualBovenwettelijkHours()

	/**
	 * The settings over an app config holding the given values.
	 *
	 * @param array<string, string|bool> $values The stored app config values by key.
	 *
	 * @return LeaveAccrualSettings
	 */
	private function settings(array $values): LeaveAccrualSettings {
		$config = AppConfigValues::answer($this->createMock(IAppConfig::class), $values);

		return new LeaveAccrualSettings($config);
	}//end settings()

}//end class
