<?php

/**
 * Unit tests for PrivilegedSessionResolver.
 *
 * Pins the `--as-user` privileged-session establishment contract shared by
 * every `occ humaniq:avg:*` command (avg-dsr design.md D3, REQ-DSR-004): an
 * unknown uid or a non-admin uid is refused with a one-line controlled
 * message (never an uncaught throw) and `IUserSession::setUser()` is never
 * called; a real administrator uid establishes the session.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Command
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
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\PrivilegedSessionResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PrivilegedSessionResolver.
 *
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
 */
class PrivilegedSessionResolverTest extends TestCase {

	/**
	 * An unknown --as-user is refused with a one-line message;
	 * `IUserSession::setUser()` is never called.
	 *
	 * @return void
	 */
	public function testUnknownUserIsRefusedControlled(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('nonexistent-uid')->willReturn(null);

		$groupManager = $this->createMock(IGroupManager::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->never())->method('setUser');

		$resolver = new PrivilegedSessionResolver($userManager, $groupManager, $userSession);
		$error = $resolver->establish('nonexistent-uid');

		$this->assertNotNull($error);
		$this->assertStringContainsString('nonexistent-uid', $error);

	}//end testUnknownUserIsRefusedControlled()

	/**
	 * A valid but non-admin --as-user is refused with a one-line message;
	 * `IUserSession::setUser()` is never called.
	 *
	 * @return void
	 */
	public function testNonAdminUserIsRefusedControlled(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('regular-user');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('regular-user')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('regular-user')->willReturn(false);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->never())->method('setUser');

		$resolver = new PrivilegedSessionResolver($userManager, $groupManager, $userSession);
		$error = $resolver->establish('regular-user');

		$this->assertNotNull($error);
		$this->assertStringContainsString('beheerder', $error);

	}//end testNonAdminUserIsRefusedControlled()

	/**
	 * A real administrator uid establishes the session -- `setUser()` is
	 * called with the resolved admin and `establish()` returns null.
	 *
	 * @return void
	 */
	public function testAdminUserEstablishesSession(): void {
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('admin')->willReturn($admin);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->once())->method('setUser')->with($admin);

		$resolver = new PrivilegedSessionResolver($userManager, $groupManager, $userSession);
		$error = $resolver->establish('admin');

		$this->assertNull($error);

	}//end testAdminUserEstablishesSession()

	/**
	 * An empty --as-user is refused before any resolve.
	 *
	 * @return void
	 */
	public function testEmptyUidIsRefused(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->never())->method('get');

		$groupManager = $this->createMock(IGroupManager::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->never())->method('setUser');

		$resolver = new PrivilegedSessionResolver($userManager, $groupManager, $userSession);
		$error = $resolver->establish('');

		$this->assertNotNull($error);

	}//end testEmptyUidIsRefused()

}//end class
