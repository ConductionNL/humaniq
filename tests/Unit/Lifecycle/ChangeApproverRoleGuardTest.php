<?php

/**
 * Unit tests for ChangeApproverRoleGuard.
 *
 * Only a user holding the approver role of the request's kind, in the
 * request's administration, may approve or reject it: an hr user on an hr
 * rule, never an hr user on an accountant rule, the manager on a manager rule,
 * a Nextcloud administrator always, and never the employee it is about or
 * the person who asked.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\ChangeApproverRoleGuard;
use OCA\Humaniq\Service\AdministrationService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Who may decide on a change request.
 */
class ChangeApproverRoleGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var ChangeApproverRoleGuard
	 */
	private ChangeApproverRoleGuard $guard;

	/**
	 * hr.user is hr in ADM-001, acc.user accountant in ADM-001, hr.other hr
	 * in ADM-002; root is a Nextcloud administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$roles = [
			'hr.user' => [['administrationId' => 'ADM-001', 'name' => 'Demo', 'role' => 'hr', 'mode' => 'standard']],
			'acc.user' => [['administrationId' => 'ADM-001', 'name' => 'Demo', 'role' => 'accountant', 'mode' => 'standard']],
			'hr.other' => [['administrationId' => 'ADM-002', 'name' => 'Other', 'role' => 'hr', 'mode' => 'standard']],
		];
		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('accessibleAdministrations')->willReturnCallback(static fn (string $uid): array => ($roles[$uid] ?? []));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$this->guard = new ChangeApproverRoleGuard(administrations: $administrations, groupManager: $groups);
	}//end setUp()

	/**
	 * A request under one approver role.
	 *
	 * @param string $role The approver role.
	 *
	 * @return array<string, mixed>
	 */
	private function request(string $role): array {
		return [
			'employeeId' => 'emp-1',
			'changeKind' => $role === 'accountant' ? 'salaris' : 'bankrekening',
			'approverRole' => $role,
			'userId' => 'a.visser',
			'requestedBy' => 'a.visser',
			'managerUserId' => 'm.manager',
			'administrationId' => 'ADM-001',
			'status' => 'ingediend',
		];
	}//end request()

	/**
	 * An hr user approves an hr request.
	 *
	 * @return void
	 */
	public function testAnHrUserApprovesAnHrRequest(): void {
		self::assertTrue($this->guard->check($this->request('hr'), 'goedkeuren', 'hr.user')->isAllowed());
	}//end testAnHrUserApprovesAnHrRequest()

	/**
	 * An hr user cannot approve a salary request that needs an accountant,
	 * and an hr user of another administration cannot approve an hr request.
	 *
	 * @return void
	 */
	public function testTheWrongRoleOrAdministrationIsRefused(): void {
		self::assertFalse($this->guard->check($this->request('accountant'), 'goedkeuren', 'hr.user')->isAllowed());
		self::assertTrue($this->guard->check($this->request('accountant'), 'goedkeuren', 'acc.user')->isAllowed());
		self::assertFalse($this->guard->check($this->request('hr'), 'afwijzen', 'hr.other')->isAllowed());
	}//end testTheWrongRoleOrAdministrationIsRefused()

	/**
	 * The manager decides on a manager request; an hr user does not.
	 *
	 * @return void
	 */
	public function testTheManagerDecidesOnAManagerRequest(): void {
		self::assertTrue($this->guard->check($this->request('manager'), 'goedkeuren', 'm.manager')->isAllowed());
		self::assertFalse($this->guard->check($this->request('manager'), 'goedkeuren', 'hr.user')->isAllowed());
	}//end testTheManagerDecidesOnAManagerRequest()

	/**
	 * The employee the request is about, or who asked, never decides on it,
	 * not even as an administrator; an administrator otherwise may.
	 *
	 * @return void
	 */
	public function testTheSubjectAndTheRequesterAreRefused(): void {
		$request = $this->request('hr');
		self::assertFalse($this->guard->check($request, 'goedkeuren', 'a.visser')->isAllowed());
		self::assertFalse($this->guard->check(array_merge($request, ['userId' => 'root']), 'goedkeuren', 'root')->isAllowed());
		self::assertFalse($this->guard->check(array_merge($request, ['userId' => null, 'requestedBy' => 'hr.user']), 'goedkeuren', 'hr.user')->isAllowed());
		self::assertTrue($this->guard->check($request, 'goedkeuren', 'root')->isAllowed());
		self::assertFalse($this->guard->check($request, 'goedkeuren', '')->isAllowed());
	}//end testTheSubjectAndTheRequesterAreRefused()

}//end class
