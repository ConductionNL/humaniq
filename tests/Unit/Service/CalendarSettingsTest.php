<?php

/**
 * Calendar settings test
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
 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CalendarSettings;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Which shared calendars the leave and interview syncs write to.
 *
 * @covers \OCA\Humaniq\Service\CalendarSettings
 * @uses   \OCA\Humaniq\Tests\Unit\Support\AppConfigValues
 */
class CalendarSettingsTest extends TestCase {

	/**
	 * Unset, every target is empty and the syncs skip.
	 *
	 * @return void
	 */
	public function testUnsetTargetsAreEmpty(): void {
		$settings = $this->settings([]);

		self::assertSame(['', '', '', ''], [$settings->getLeaveCalendarPrincipal(), $settings->getLeaveCalendarUri(), $settings->getInterviewCalendarPrincipal(), $settings->getInterviewCalendarUri()]);
	}//end testUnsetTargetsAreEmpty()

	/**
	 * Each target reads its own key, as stored.
	 *
	 * @return void
	 */
	public function testEachTargetReadsItsOwnKey(): void {
		$settings = $this->settings(
			[
				'leave_calendar_principal' => 'principals/users/hr',
				'leave_calendar_uri' => 'verlof',
				'interview_calendar_principal' => 'principals/users/recruiting',
				'interview_calendar_uri' => ' sollicitaties ',
			]
		);

		self::assertSame(
			['principals/users/hr', 'verlof', 'principals/users/recruiting', ' sollicitaties '],
			[$settings->getLeaveCalendarPrincipal(), $settings->getLeaveCalendarUri(), $settings->getInterviewCalendarPrincipal(), $settings->getInterviewCalendarUri()]
		);
	}//end testEachTargetReadsItsOwnKey()

	/**
	 * The settings over an app config holding the given values.
	 *
	 * @param array<string, string> $values The stored app config values by key.
	 *
	 * @return CalendarSettings
	 */
	private function settings(array $values): CalendarSettings {
		$config = AppConfigValues::answer($this->createMock(IAppConfig::class), $values);

		return new CalendarSettings($config);
	}//end settings()

}//end class
