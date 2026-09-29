<?php

/**
 * Unit tests for ApprovalDecisionStampListener (self-service-approvals-inbox D1): a submit and a decision are stamped on the real OpenRegister update event, and the stamped request still fits its schema.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ApprovalDecisionStampListener;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ApprovalDecisionStampListenerTest extends TestCase {

	private const LEAVE = ['employeeId' => 'f1a3c2b4-0d5e-4c6f-8a7b-9c0d1e2f3a4b', 'leaveType' => 'holiday', 'startDate' => '2026-07-20', 'endDate' => '2026-07-24', 'hours' => 40];

	public function testASubmitStampsWhenAndClearsAnEarlierVerdict(): void {
		$stamps = $this->move('LeaveRequest', array_merge(self::LEAVE, ['status' => 'rejected', 'approvedBy' => 'mila', 'approvedAt' => '2026-07-01T09:00:00Z']), 'submitted');

		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)$stamps['submittedAt']);
		self::assertNull($stamps['approvedBy']);
		self::assertNull($stamps['approvedAt']);
	}//end testASubmitStampsWhenAndClearsAnEarlierVerdict()

	public function testARejectionStampsWhoDecidedAndWhen(): void {
		$before = array_merge(self::LEAVE, ['status' => 'submitted', 'submittedAt' => '2026-07-10T09:00:00Z']);
		$stamps = $this->move('LeaveRequest', $before, 'rejected');

		self::assertSame('dirk', $stamps['approvedBy']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$stamps['approvedAt']);
		self::assertSame([], RegisterSchemaValidator::errors('LeaveRequest', array_merge($before, ['status' => 'rejected', 'rejectionReason' => 'Te druk'], $stamps)));
	}//end testARejectionStampsWhoDecidedAndWhen()

	public function testAnApprovedClaimIsStampedAndAnEditIsNot(): void {
		$claim = ['employeeId' => 'f1a3c2b4-0d5e-4c6f-8a7b-9c0d1e2f3a4b', 'title' => 'Hotel', 'amount' => 120.5, 'currency' => 'EUR', 'category' => 'accommodation', 'expenseDate' => '2026-07-02', 'status' => 'submitted'];

		self::assertSame('dirk', $this->move('Expense', $claim, 'approved')['approvedBy']);
		self::assertSame([], $this->move('Expense', $claim, 'submitted'));
		self::assertSame([], $this->move('Expense', array_merge($claim, ['status' => 'approved']), 'reimbursed'));
	}//end testAnApprovedClaimIsStampedAndAnEditIsNot()

	/**
	 * Run one status move through the listener and return its stamps.
	 */
	private function move(string $schema, array $before, string $to): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dirk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$event = new ObjectUpdatingEvent($this->entity($schema, array_merge($before, ['status' => $to])), $this->entity($schema, $before));
		(new ApprovalDecisionStampListener($session))->handle($event);

		return $event->getModifiedData();
	}//end move()

	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('req-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

}//end class
