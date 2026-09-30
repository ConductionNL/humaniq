<?php

/**
 * Tests for PayrollRunFindingStampListener.
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\PayrollRunFindingStampListener;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The reviewer who acknowledges a finding is stamped, and stays stamped.
 */
class PayrollRunFindingStampListenerTest extends TestCase {

	/**
	 * A finding as the check writes it.
	 *
	 * @var array<string, mixed>
	 */
	private const FINDING = [
		'payrollRunId' => 'run-5',
		'employeeId' => 'emp-1',
		'kind' => 'deviation',
		'severity' => 'warning',
		'message' => 'Net pay is far from the usual amount.',
		'component' => 'nettoPay',
		'currentValue' => 5500.0,
		'baselineValue' => 2750.0,
		'status' => 'open',
		'checkedAt' => '2026-05-25T10:00:00Z',
	];

	/**
	 * The listener with the given signed-in user.
	 *
	 * @param string|null $uid The user, or null for none.
	 *
	 * @return PayrollRunFindingStampListener
	 */
	private function listener(?string $uid): PayrollRunFindingStampListener {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return new PayrollRunFindingStampListener($session);
	}//end listener()

	/**
	 * An entity holding the data.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('finding-1');
		$entity->setSchema('PayrollRunFinding');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Save old as new through the listener and return what is stored.
	 *
	 * @param array<string, mixed> $old The stored finding.
	 * @param array<string, mixed> $new The finding as written.
	 * @param string|null          $uid The signed-in user.
	 *
	 * @return array<string, mixed>
	 */
	private function save(array $old, array $new, ?string $uid): array {
		$event = new ObjectUpdatingEvent($this->entity($new), $this->entity($old));
		$this->listener($uid)->handle($event);
		return array_merge($new, $event->getModifiedData());
	}//end save()

	/**
	 * Acknowledging stamps the reviewer, and the stored finding is valid.
	 *
	 * @return void
	 */
	public function testAcknowledgingStampsTheReviewer(): void {
		$new = array_merge(self::FINDING, ['status' => 'acknowledged', 'acknowledgementNote' => 'bonus agreed with the manager', 'acknowledgedBy' => 'someone-else']);
		$saved = $this->save(self::FINDING, $new, 'reviewer');

		$this->assertSame('reviewer', $saved['acknowledgedBy']);
		$this->assertSame('bonus agreed with the manager', $saved['acknowledgementNote']);
		$this->assertSame([], RegisterSchemaValidator::errors('PayrollRunFinding', $saved));
	}//end testAcknowledgingStampsTheReviewer()

	/**
	 * A later write, such as the check's recheck, keeps the reviewer.
	 *
	 * @return void
	 */
	public function testALaterWriteKeepsTheReviewer(): void {
		$old = array_merge(self::FINDING, ['status' => 'acknowledged', 'acknowledgedBy' => 'reviewer', 'acknowledgementNote' => 'fine']);
		$new = array_merge($old, ['currentValue' => 5600.0, 'acknowledgedBy' => null]);

		$this->assertSame('reviewer', $this->save($old, $new, null)['acknowledgedBy']);
		$this->assertSame('reviewer', $this->save($old, array_merge($old, ['acknowledgedBy' => 'reviewer']), 'other')['acknowledgedBy']);
	}//end testALaterWriteKeepsTheReviewer()

	/**
	 * An open finding has no reviewer, a write without a user stamps none, and
	 * another event is ignored.
	 *
	 * @return void
	 */
	public function testNothingIsStampedWithoutAnAcknowledgement(): void {
		$this->assertArrayNotHasKey('acknowledgedBy', $this->save(self::FINDING, array_merge(self::FINDING, ['message' => 'changed']), 'reviewer'));
		$this->assertArrayNotHasKey('acknowledgedBy', $this->save(self::FINDING, array_merge(self::FINDING, ['status' => 'acknowledged']), null));

		$created = new ObjectCreatedEvent($this->entity(self::FINDING));
		$this->listener('reviewer')->handle($created);
		$this->assertSame(self::FINDING, $created->getObject()->getObject());
	}//end testNothingIsStampedWithoutAnAcknowledgement()

}//end class
