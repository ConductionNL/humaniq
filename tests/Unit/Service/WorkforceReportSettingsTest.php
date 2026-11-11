<?php

/**
 * Workforce report settings test
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
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * What the formation and department figure settings read from app config.
 *
 * @covers \OCA\Humaniq\Service\SettingsService
 * @uses   \OCA\Humaniq\Tests\Unit\Support\AppConfigValues
 */
class WorkforceReportSettingsTest extends TestCase {

	/**
	 * The net FTE leave types are a trimmed comma list without empty codes.
	 *
	 * @return void
	 */
	public function testTheNetFteLeaveTypes(): void {
		self::assertSame(['parental', 'zwangerschap'], $this->settings([])->getFormationNetFteLeaveTypes());
		self::assertSame(['a', 'b'], $this->settings(['formation_net_fte_leave_types' => ' a, ,b ,'])->getFormationNetFteLeaveTypes());
		self::assertSame([], $this->settings(['formation_net_fte_leave_types' => ''])->getFormationNetFteLeaveTypes());
	}//end testTheNetFteLeaveTypes()

	/**
	 * The long-term sickness threshold defaults to 6 and is never negative.
	 *
	 * @return void
	 */
	public function testTheLongTermSickWeeks(): void {
		self::assertSame(6, $this->settings([])->getFormationLongTermSickWeeks());
		self::assertSame(10, $this->settings(['formation_long_term_sick_weeks' => 10])->getFormationLongTermSickWeeks());
		self::assertSame(0, $this->settings(['formation_long_term_sick_weeks' => -3])->getFormationLongTermSickWeeks());
	}//end testTheLongTermSickWeeks()

	/**
	 * The smallest unit a manager sees figures of defaults to 5 and is at least 1.
	 *
	 * @return void
	 */
	public function testTheDepartmentFiguresMinimum(): void {
		self::assertSame(5, $this->settings([])->getDepartmentFiguresMinimumMembers());
		self::assertSame(8, $this->settings(['department_figures_minimum_members' => 8])->getDepartmentFiguresMinimumMembers());
		self::assertSame(1, $this->settings(['department_figures_minimum_members' => 0])->getDepartmentFiguresMinimumMembers());
	}//end testTheDepartmentFiguresMinimum()

	/**
	 * The settings over an app config holding the given values.
	 *
	 * @param array<string, string|int> $values The stored app config values by key.
	 *
	 * @return SettingsService
	 */
	private function settings(array $values): SettingsService {
		$config = AppConfigValues::answer($this->createMock(IAppConfig::class), $values);

		return new SettingsService($config, $this->createMock(IAppManager::class), $this->createMock(ContainerInterface::class), new NullLogger());
	}//end settings()

}//end class
