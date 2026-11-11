<?php

/**
 * Unit tests for ManagerAccessStamps (mss-team-scope REQ-MSS-009): the
 * manager and deputy stamps on Employee, SickLeaveCase, LeaveTransaction and
 * CompAdjustment, and what the schemas' own rules let each account do with a
 * stamped record. Real gateway over the in-memory store.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ManagerAccessStamps;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ManagerAccessStampsTest extends TestCase {

	use ApprovalsFixture;

	/**
	 * A day inside the deputy period of the fixture.
	 */
	private const IN_PERIOD = '2026-07-20';

	/**
	 * A day after it.
	 */
	private const AFTER_PERIOD = '2026-08-02';

	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
		$this->store->seed('Employee', 'emp-kees', ['firstName' => 'Kees', 'lastName' => 'Verkoop', 'nextcloudUserId' => 'kees']);
		$this->store->seed('Employee', 'emp-piet', ['firstName' => 'Piet', 'lastName' => 'Verkoop', 'nextcloudUserId' => 'piet']);
		$this->store->seed('OrgUnit', 'unit-sales', ['name' => 'Verkoop', 'managerId' => 'emp-kees']);
		$this->store->seed('OrgAssignment', 'as-piet', ['employeeId' => 'emp-piet', 'orgUnitId' => 'unit-sales', 'startDate' => '2024-01-01']);
	}//end setUp()

	private function stamps(): ManagerAccessStamps {
		return new ManagerAccessStamps(
			gateway: $this->gateway(),
			marker: new InternalWriteMarker(),
			deputies: $this->deputies(),
			orgResolution: new OrgResolutionService(),
			logger: new NullLogger()
		);
	}//end stamps()

	private function stored(string $schema, string $uuid): array {
		return (array)$this->store->find($uuid, schema: $schema)?->getObject();
	}//end stored()

	/**
	 * Whether a schema's rules for a verb admit an account that holds no
	 * humaniq group, on a record: a `$userId` equality or a `$contains`
	 * on the account.
	 *
	 * @param string $schema The schema name.
	 * @param string $verb The verb.
	 * @param string $uid The account.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool
	 */
	private function admits(string $schema, string $verb, string $uid, array $record): bool {
		foreach (RegisterSchemaValidator::schema($schema)['authorization'][$verb] as $rule) {
			if (is_array($rule) === false || ($rule['group'] ?? '') !== 'authenticated' || is_array($rule['match'] ?? null) === false) {
				continue;
			}

			$holds = true;
			foreach ($rule['match'] as $field => $expected) {
				$value = ($record[$field] ?? null);
				$holds = $holds && (($expected === '$userId' && $value === $uid)
					|| ($expected === ['$contains' => '$userId'] && is_array($value) === true && in_array($uid, $value, true) === true));
			}

			if ($holds === true) {
				return true;
			}
		}

		return false;
	}//end admits()

	public function testTheTeamManagerIsStampedAndReadsEveryKindOfRecord(): void {
		$service = $this->stamps();
		$records = [
			'Employee' => [[], 'emp-sam'],
			'SickLeaveCase' => [['employeeId' => 'emp-sam', 'status' => 'open'], 'sl-1'],
			'LeaveTransaction' => [['employeeId' => 'emp-sam', 'userId' => 'sam', 'status' => 'submitted'], 'lt-1'],
			'CompAdjustment' => [['employeeId' => 'emp-sam', 'employeeUserId' => 'sam', 'status' => 'proposed'], 'ca-1'],
		];

		foreach ($records as $schema => [$record, $uuid]) {
			$stamped = array_merge($record, $service->stampsFor($record, $schema, $uuid, self::AFTER_PERIOD));

			self::assertSame('mila', $stamped['managerUserId'], $schema);
			self::assertSame([], $stamped['deputyUserIds'], $schema);
			self::assertTrue($this->admits($schema, 'read', 'mila', $stamped), $schema . ': the team manager reads');
			$declared = RegisterSchemaValidator::schema($schema);
			self::assertSame([], RegisterSchemaValidator::errorsAgainst(['properties' => $declared['properties']], ['managerUserId' => 'mila', 'deputyUserIds' => ['dirk']]), $schema);
			self::assertSame([], RegisterSchemaValidator::errorsAgainst(['properties' => $declared['properties']], ['managerUserId' => null, 'deputyUserIds' => null]), $schema);
		}
	}//end testTheTeamManagerIsStampedAndReadsEveryKindOfRecord()

	public function testTheManagerOfAnotherTeamIsRefused(): void {
		$record = ['employeeId' => 'emp-sam', 'status' => 'open'];
		$stamped = array_merge($record, $this->stamps()->stampsFor($record, 'SickLeaveCase', 'sl-1', self::IN_PERIOD));

		self::assertFalse($this->admits('SickLeaveCase', 'read', 'kees', $stamped));
		self::assertFalse($this->admits('Employee', 'read', 'kees', array_merge(['nextcloudUserId' => 'sam'], $this->stamps()->stampsFor([], 'Employee', 'emp-sam', self::IN_PERIOD))));

		$theirs = array_merge(['employeeId' => 'emp-piet'], $this->stamps()->stampsFor(['employeeId' => 'emp-piet'], 'SickLeaveCase', 'sl-2', self::IN_PERIOD));
		self::assertTrue($this->admits('SickLeaveCase', 'read', 'kees', $theirs));
		self::assertFalse($this->admits('SickLeaveCase', 'read', 'mila', $theirs));
	}//end testTheManagerOfAnotherTeamIsRefused()

	public function testAnIncomingStampIsReplaced(): void {
		$record = ['employeeId' => 'emp-sam', 'managerUserId' => 'kees', 'deputyUserIds' => ['kees']];

		self::assertSame(['managerUserId' => 'mila', 'deputyUserIds' => []], $this->stamps()->stampsFor($record, 'CompAdjustment', 'ca-1', self::AFTER_PERIOD));
	}//end testAnIncomingStampIsReplaced()

	public function testTheDeputyReadsAndDecidesTheQueueOnlyDuringTheirPeriod(): void {
		$record = ['employeeId' => 'emp-sam', 'userId' => 'sam', 'status' => 'submitted'];
		$during = array_merge($record, $this->stamps()->stampsFor($record, 'LeaveTransaction', 'lt-1', self::IN_PERIOD));
		$after = array_merge($record, $this->stamps()->stampsFor($record, 'LeaveTransaction', 'lt-1', self::AFTER_PERIOD));

		self::assertSame(['dirk'], $during['deputyUserIds']);
		self::assertTrue($this->admits('LeaveTransaction', 'read', 'dirk', $during));
		self::assertTrue($this->admits('LeaveTransaction', 'update', 'dirk', $during));
		self::assertTrue($this->admits('LeaveTransaction', 'update', 'mila', $during));
		self::assertSame([], $after['deputyUserIds']);
		self::assertFalse($this->admits('LeaveTransaction', 'read', 'dirk', $after));
	}//end testTheDeputyReadsAndDecidesTheQueueOnlyDuringTheirPeriod()

	public function testARecordWithoutAResolvableManagerCarriesNoStamp(): void {
		$this->store->seed('Employee', 'emp-loose', ['nextcloudUserId' => 'loose']);

		self::assertSame(['managerUserId' => null, 'deputyUserIds' => []], $this->stamps()->stampsFor(['employeeId' => 'emp-loose'], 'SickLeaveCase', 'sl-3', self::IN_PERIOD));
		self::assertSame(['managerUserId' => null, 'deputyUserIds' => []], $this->stamps()->stampsFor([], 'SickLeaveCase', 'sl-4', self::IN_PERIOD));
	}//end testARecordWithoutAResolvableManagerCarriesNoStamp()

	public function testThePassStampsStoredRecordsOnceAndADryRunWritesNothing(): void {
		$this->store->seed('SickLeaveCase', 'sl-1', ['employeeId' => 'emp-sam', 'status' => 'open']);
		$this->store->seed('LeaveTransaction', 'lt-1', ['employeeId' => 'emp-piet', 'userId' => 'piet', 'status' => 'submitted']);
		$this->store->seed('CompAdjustment', 'ca-1', ['employeeId' => 'emp-sam', 'managerUserId' => 'mila', 'deputyUserIds' => ['dirk']]);

		$dry = $this->stamps()->run(true, self::IN_PERIOD);
		self::assertSame(['inspected' => 1, 'changed' => 0, 'failed' => 0], $dry['CompAdjustment']);
		self::assertSame(1, $dry['SickLeaveCase']['changed']);
		self::assertArrayNotHasKey('managerUserId', $this->stored('SickLeaveCase', 'sl-1'));

		$report = $this->stamps()->run(false, self::IN_PERIOD);
		self::assertSame(['inspected' => 1, 'changed' => 1, 'failed' => 0], $report['SickLeaveCase']);
		self::assertSame(['inspected' => 1, 'changed' => 1, 'failed' => 0], $report['LeaveTransaction']);
		self::assertSame(['inspected' => 1, 'changed' => 0, 'failed' => 0], $report['CompAdjustment']);
		self::assertSame(['inspected' => 5, 'changed' => 3, 'failed' => 0], $report['Employee']);

		$case = $this->stored('SickLeaveCase', 'sl-1');
		self::assertSame('mila', $case['managerUserId']);
		self::assertSame(['dirk'], $case['deputyUserIds']);
		self::assertSame('open', $case['status']);
		self::assertSame('kees', $this->stored('LeaveTransaction', 'lt-1')['managerUserId']);

		foreach ($this->stamps()->run(false, self::IN_PERIOD) as $schema => $counts) {
			self::assertSame(0, $counts['changed'], $schema . ': a second pass changes nothing');
		}

		$later = $this->stamps()->run(false, self::AFTER_PERIOD);
		self::assertSame(1, $later['SickLeaveCase']['changed'], 'the deputy is removed once the period ends');
		self::assertSame([], $this->stored('SickLeaveCase', 'sl-1')['deputyUserIds']);
	}//end testThePassStampsStoredRecordsOnceAndADryRunWritesNothing()

}//end class
