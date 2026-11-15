<?php

/**
 * Unit tests for AdministrationAccessTrust.
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AdministrationAccessTrust;
use OCA\Humaniq\Service\HumaniqRoles;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Which access rows are believed, and who may grant.
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */
class AdministrationAccessTrustTest extends TestCase {

	/**
	 * The trust check with `admin` as the only administrator.
	 *
	 * @return AdministrationAccessTrust
	 */
	private function trust(): AdministrationAccessTrust {
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isAdmin')->willReturnCallback(static fn (?string $uid): bool => $uid === 'admin');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new AdministrationAccessTrust($roles, $appConfig);
	}//end trust()

	/**
	 * One access row.
	 *
	 * @param string $userId           The user the row is for.
	 * @param string $administrationId The administration.
	 * @param string $role             The role.
	 * @param string $owner            Who wrote the row.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $userId, string $administrationId, string $role, string $owner): array {
		return ['userId' => $userId, 'administrationId' => $administrationId, 'role' => $role, '@self' => ['owner' => $owner]];
	}//end row()

	/**
	 * Rows from an administrator, from the system, and from HR the
	 * administrator appointed are believed; a chain of HR grants holds.
	 *
	 * @return void
	 */
	public function testRowsGrantedWithStandingAreBelieved(): void {
		$rows = [
			// Listed before the row that gives its owner standing.
			self::row('employee', 'ADM-001', 'employee', 'hr-second'),
			self::row('hr-second', 'ADM-001', 'hr', 'hr-first'),
			self::row('hr-first', 'ADM-001', 'hr', 'admin'),
			self::row('accountant', 'ADM-002', 'accountant', '__system__'),
		];

		self::assertSame($rows, $this->trust()->trusted($rows));
	}//end testRowsGrantedWithStandingAreBelieved()

	/**
	 * A self-grant is not believed, nor is a grant by HR of a different
	 * administration, nor a row without an owner.
	 *
	 * @return void
	 */
	public function testRowsWithoutStandingAreDropped(): void {
		$believed = self::row('hr-first', 'ADM-001', 'hr', 'admin');
		$rows = [
			$believed,
			self::row('colleague', 'ADM-001', 'hr', 'colleague'),
			self::row('friend', 'ADM-002', 'hr', 'hr-first'),
			['userId' => 'nobody', 'administrationId' => 'ADM-001', 'role' => 'hr'],
		];

		self::assertSame([$believed], $this->trust()->trusted($rows));
	}//end testRowsWithoutStandingAreDropped()

	/**
	 * Administrators and believed HR of the administration may grant; a
	 * self-granted HR, an accountant and an unknown user may not.
	 *
	 * @return void
	 */
	public function testWhoMayGrant(): void {
		$rows = [
			self::row('hr-first', 'ADM-001', 'hr', 'admin'),
			self::row('accountant', 'ADM-001', 'accountant', 'admin'),
			self::row('colleague', 'ADM-002', 'hr', 'colleague'),
		];
		$trust = $this->trust();

		self::assertTrue($trust->mayGrant('admin', 'ADM-009', $rows));
		self::assertTrue($trust->mayGrant('hr-first', 'ADM-001', $rows));
		self::assertFalse($trust->mayGrant('hr-first', 'ADM-002', $rows));
		self::assertFalse($trust->mayGrant('accountant', 'ADM-001', $rows));
		self::assertFalse($trust->mayGrant('colleague', 'ADM-002', $rows));
		self::assertFalse($trust->mayGrant('', 'ADM-001', $rows));
		self::assertFalse($trust->mayGrant('hr-first', '', $rows));
	}//end testWhoMayGrant()
}//end class
