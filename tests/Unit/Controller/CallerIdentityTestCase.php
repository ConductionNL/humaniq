<?php

/**
 * Caller identity test case
 *
 * Shared doubles for the tests that prove a controller acts for the session user
 * and checks that user's own roles, never a value the request claims.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Service\CallerContext;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Session users: "clerk" (no role), "hr-only" (humaniq-hr), "pay-only"
 * (humaniq-payroll) and "boss" (Nextcloud admin). Every request claims to be
 * "boss" with every role.
 */
abstract class CallerIdentityTestCase extends TestCase {

	/**
	 * A request that claims another user and every role.
	 *
	 * @param string $f The user the request claims to be.
	 *
	 * @return IRequest
	 */
	protected function request(string $f = 'boss'): IRequest {
		$forged = ['userId' => $f, 'uid' => $f, 'user' => $f, 'requestedBy' => $f, 'roles' => ['admin', 'humaniq-hr', 'humaniq-payroll'], 'isAdmin' => true, 'isHr' => true, 'isPayroll' => true];
		$r = $this->createMock(IRequest::class);
		$r->method('getParam')->willReturnCallback(fn ($k, $d = null) => $forged[$k] ?? $d);
		$r->method('getParams')->willReturn($forged);
		return $r;
	}

	/**
	 * Group membership that answers per user.
	 *
	 * @return IGroupManager
	 */
	protected function groupManager(): IGroupManager {
		$gm = $this->createMock(IGroupManager::class);
		$gm->method('isAdmin')->willReturnCallback(fn ($u) => $u === 'boss');
		$gm->method('isInGroup')->willReturnCallback(fn ($u, $g) => ($u === 'hr-only' && $g === HumaniqRoles::HR_GROUP) || ($u === 'pay-only' && $g === HumaniqRoles::PAYROLL_GROUP));
		return $gm;
	}

	/**
	 * The caller context of one session user.
	 *
	 * @param string $sessionUid The session user.
	 * @param ContainerInterface $container The container.
	 *
	 * @return CallerContext
	 */
	protected function context(string $sessionUid, ContainerInterface $container): CallerContext {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($sessionUid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new CallerContext($session, new HumaniqRoles($this->groupManager()), $container, new NullLogger());
	}

	/**
	 * Settings with OpenRegister available.
	 *
	 * @return SettingsService
	 */
	protected function settings(): SettingsService {
		$s = $this->createMock(SettingsService::class);
		$s->method('isOpenRegisterAvailable')->willReturn(true);
		$s->method('getRegisterSlug')->willReturn('humaniq');
		return $s;
	}

	/**
	 * A container that must not be touched before the role guard.
	 *
	 * @return ContainerInterface
	 */
	protected function untouchable(): ContainerInterface {
		$c = $this->createMock(ContainerInterface::class);
		$c->expects($this->never())->method('get');
		return $c;
	}

	/**
	 * A container whose ObjectService serves the given rows by id, and all rows on findAll.
	 *
	 * @param array<string, array<string, mixed>> $rows Rows by id.
	 *
	 * @return ContainerInterface
	 */
	protected function serving(array $rows): ContainerInterface {
		$objects = new class($rows) {
			public function __construct(private readonly array $rows) {
			}
			public function setRegister($r) {
				return $this;
			}
			public function setSchema($s) {
				return $this;
			}
			public function find(...$a) {
				$id = $a['id'] ?? $a[0];
				return $this->rows[$id] ?? null;
			}
			public function findAll(...$a) {
				return array_values($this->rows);
			}
		};
		$c = $this->createMock(ContainerInterface::class);
		$c->method('get')->willReturn($objects);
		return $c;
	}
}
