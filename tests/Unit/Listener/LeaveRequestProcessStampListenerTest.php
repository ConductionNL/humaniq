<?php

/**
 * LeaveRequestProcessStampListener: who decided a leave request and when,
 * per edge of design D1, inert to client input, history kept on resubmit.
 *
 * @category Tests
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
 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\LeaveRequestProcessStampListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Humaniq\Listener\LeaveRequestProcessStampListener
 */
class LeaveRequestProcessStampListenerTest extends TestCase {

	private const EARLIER = ['step' => 'manager', 'decision' => 'rejected', 'by' => 'manager0', 'at' => '2026-10-01T09:00:00Z', 'remark' => 'Te kort dag'];

	private function listener(string $uid = 'manager1'): LeaveRequestProcessStampListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return new LeaveRequestProcessStampListener(
			gateway: new HoursRegisterGateway(
				container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => new FakeObjectStore(), 'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper()]),
				settingsService: $settings,
				orgResolution: new OrgResolutionService()
			),
			userSession: $session,
			logger: new NullLogger()
		);
	}

	private static function entity(array $payload, string $schema = 'LeaveRequest'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($payload);
		$entity->setUuid('lr-1');
		return $entity;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function update(array $incoming, array $stored, string $uid = 'manager1'): array {
		$event = new ObjectUpdatingEvent(self::entity($incoming), self::entity($stored));
		$this->listener($uid)->handle($event);
		$this->assertFalse($event->isPropagationStopped());
		return $event->getModifiedData();
	}

	public function testCreateStartsADraftWithNoProcessFields(): void {
		$event = new ObjectCreatingEvent(self::entity(['status' => 'approved', 'approvedBy' => 'attacker', 'decisions' => [self::EARLIER]]));
		$this->listener()->handle($event);

		$this->assertSame(
			['status' => 'draft', 'decisions' => [], 'submittedAt' => null, 'approvedBy' => null, 'approvedAt' => null, 'rejectionReason' => null],
			$event->getModifiedData()
		);
	}

	public function testSubmittingStampsTheTimeAndClearsTheVerdict(): void {
		$modified = $this->update(
			['status' => 'submitted', 'approvedBy' => 'attacker'],
			['status' => 'rejected', 'approvedBy' => 'manager0', 'approvedAt' => '2026-10-01T09:00:00Z', 'decisions' => [self::EARLIER]]
		);

		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $modified['submittedAt']);
		$this->assertNull($modified['approvedBy']);
		$this->assertNull($modified['approvedAt']);
		$this->assertSame([self::EARLIER], $modified['decisions'], 'a resubmit keeps the earlier decisions');
	}

	public function testTheManagerStepAppendsADecisionOnly(): void {
		$modified = $this->update(['status' => 'manager-approved', 'decisionRemark' => ' Akkoord '], ['status' => 'submitted', 'decisions' => [self::EARLIER]]);

		$this->assertNull($modified['approvedBy'], 'the final verdict is not stamped yet');
		$this->assertCount(2, $modified['decisions']);
		$this->assertSame(self::EARLIER, $modified['decisions'][0]);
		$this->assertSame(['manager', 'approved', 'manager1', 'Akkoord'], [$modified['decisions'][1]['step'], $modified['decisions'][1]['decision'], $modified['decisions'][1]['by'], $modified['decisions'][1]['remark']]);
	}

	public function testApprovingStampsTheSessionUserNotTheClient(): void {
		$modified = $this->update(['status' => 'approved', 'approvedBy' => 'attacker', 'approvedAt' => '1999-01-01T00:00:00Z'], ['status' => 'submitted']);

		$this->assertSame('manager1', $modified['approvedBy']);
		$this->assertNotSame('1999-01-01T00:00:00Z', $modified['approvedAt']);
		$this->assertSame('manager', $modified['decisions'][0]['step']);
		$this->assertSame('approved', $modified['decisions'][0]['decision']);
	}

	public function testTheHrStepIsRecordedAsHr(): void {
		$manager = ['step' => 'manager', 'decision' => 'approved', 'by' => 'manager1', 'at' => '2026-11-01T09:00:00Z', 'remark' => null];
		$modified = $this->update(['status' => 'approved'], ['status' => 'manager-approved', 'decisions' => [$manager]], 'hr-ellen');

		$this->assertSame('hr-ellen', $modified['approvedBy']);
		$this->assertSame([$manager['step'], 'hr'], array_column($modified['decisions'], 'step'));
		$this->assertSame('hr-ellen', $modified['decisions'][1]['by']);
	}

	public function testRejectingKeepsTheReasonAsRemarkAndField(): void {
		$modified = $this->update(['status' => 'rejected', 'rejectionReason' => ' Geen bezetting '], ['status' => 'submitted']);

		$this->assertSame('Geen bezetting', $modified['rejectionReason']);
		$this->assertSame('Geen bezetting', $modified['decisions'][0]['remark']);
		$this->assertSame('rejected', $modified['decisions'][0]['decision']);
	}

	public function testAWriteWithoutAnEdgeRestoresTheStoredFields(): void {
		$stored = ['status' => 'approved', 'approvedBy' => 'manager1', 'approvedAt' => '2026-11-01T09:00:00Z', 'decisions' => [self::EARLIER], 'submittedAt' => '2026-10-31T08:00:00Z'];
		$modified = $this->update(['status' => 'approved', 'approvedBy' => 'attacker', 'decisions' => [], 'reason' => 'x'], $stored);

		$this->assertSame('manager1', $modified['approvedBy']);
		$this->assertSame([self::EARLIER], $modified['decisions']);
		$this->assertSame('2026-10-31T08:00:00Z', $modified['submittedAt']);
	}

	public function testOtherSchemasAreLeftAlone(): void {
		$event = new ObjectCreatingEvent(self::entity(['status' => 'approved'], 'Timesheet'));
		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}
}
