<?php

/**
 * Tests for the EnsureRoleGroups repair step.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Repair
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\EnsureRoleGroups;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Creates the two groups once and leaves existing ones alone.
 */
class EnsureRoleGroupsTest extends TestCase {

	/**
	 * Both groups are created on a fresh instance, and a second run creates
	 * nothing.
	 *
	 * @return void
	 */
	public function testCreatesBothGroupsOnceAndASecondRunChangesNothing(): void {
		$existing = [];
		$created = [];
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(function (string $gid) use (&$existing): bool {
			return in_array($gid, $existing, true);
		});
		$groupManager->method('createGroup')->willReturnCallback(function (string $gid) use (&$existing, &$created): IGroup {
			$existing[] = $gid;
			$created[] = $gid;
			return $this->createMock(IGroup::class);
		});

		$step = new EnsureRoleGroups($groupManager);
		$step->run($this->createMock(IOutput::class));
		$this->assertSame(['humaniq-hr', 'humaniq-payroll'], $created);

		$step->run($this->createMock(IOutput::class));
		$this->assertSame(['humaniq-hr', 'humaniq-payroll'], $created);
	}//end testCreatesBothGroupsOnceAndASecondRunChangesNothing()

	/**
	 * An existing HR group, with its members, is left as it is.
	 *
	 * @return void
	 */
	public function testAnExistingGroupIsLeftAlone(): void {
		$created = [];
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(fn (string $gid): bool => $gid === 'humaniq-hr');
		$groupManager->method('createGroup')->willReturnCallback(function (string $gid) use (&$created): IGroup {
			$created[] = $gid;
			return $this->createMock(IGroup::class);
		});

		(new EnsureRoleGroups($groupManager))->run($this->createMock(IOutput::class));

		$this->assertSame(['humaniq-payroll'], $created);
	}//end testAnExistingGroupIsLeftAlone()

}//end class
