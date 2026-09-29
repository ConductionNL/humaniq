<?php

/**
 * Unit tests for the daily team message (self-service-announcements-and-digest D3, D4).
 *
 * TeamDigestComposer runs with the real HoursRegisterGateway and
 * UnitMembership over the in-memory object store; TeamDigestNode wraps it for
 * the flow engine, and the shipped "Dagbericht" declaration is read from the
 * register file.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Flow
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\TeamDigestNode;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\TeamDigestComposer;
use OCA\Humaniq\Service\UnitMembership;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use RuntimeException;

/**
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
 */
class TeamDigestNodeTest extends FlowNodeTestCase {

	private const TODAY = '2026-10-07';

	private FakeObjectStore $store;

	/**
	 * Team Burgerzaken with a sub-team, one member on leave until Friday, one
	 * off sick, one with a shared birthday, one with a birthday not shared.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('OrgUnit', 'unit-bz', ['name' => 'Burgerzaken', 'type' => 'afdeling']);
		$this->store->seed('OrgUnit', 'unit-balie', ['name' => 'Balie', 'type' => 'team', 'parentUnitId' => 'unit-bz']);
		$this->store->seed('OrgUnit', 'unit-fin', ['name' => 'Financien', 'type' => 'afdeling']);
		$this->member('emp-anna', 'Anna', 'Bakker', 'unit-bz', ['dateOfBirth' => '1990-03-01']);
		$this->member('emp-bram', 'Bram', 'Visser', 'unit-bz', ['dateOfBirth' => '1985-10-07', 'shareBirthday' => false]);
		$this->member('emp-cor', 'Cor', 'Smit', 'unit-bz', ['dateOfBirth' => '1979-10-07', 'shareBirthday' => true]);
		$this->member('emp-dirk', 'Dirk', 'Mulder', 'unit-balie', []);
		$this->member('emp-eva', 'Eva', 'Jansen', 'unit-fin', ['dateOfBirth' => '1992-10-07', 'shareBirthday' => true]);
		$this->store->seed('LeaveRequest', 'leave-anna', ['employeeId' => 'emp-anna', 'leaveType' => 'zwangerschapsverlof', 'reason' => 'Bevalling', 'startDate' => '2026-10-05', 'endDate' => '2026-10-09', 'status' => 'approved']);
		$this->store->seed('LeaveRequest', 'leave-bram-draft', ['employeeId' => 'emp-bram', 'leaveType' => 'vakantie', 'startDate' => '2026-10-06', 'endDate' => '2026-10-08', 'status' => 'submitted']);
		$this->store->seed('LeaveRequest', 'leave-anna-old', ['employeeId' => 'emp-anna', 'leaveType' => 'vakantie', 'startDate' => '2026-08-01', 'endDate' => '2026-08-14', 'status' => 'approved']);
		$this->store->seed('SickLeaveCase', 'sick-dirk', ['employeeId' => 'emp-dirk', 'firstSickDay' => '2026-10-06', 'status' => 'gemeld']);
		$this->store->seed('SickLeaveCase', 'sick-bram-over', ['employeeId' => 'emp-bram', 'firstSickDay' => '2026-09-01', 'recoveredDate' => '2026-09-04', 'status' => 'hersteld']);
	}//end setUp()

	/**
	 * Scenario: the morning message names who is away with a return date where
	 * known, and the shared birthday, and no leave type, reason or age.
	 *
	 * @return void
	 */
	public function testTheMorningMessage(): void {
		$digest = $this->composer()->compose('unit-bz', true, self::TODAY);

		self::assertSame(['Anna Bakker', 'Dirk Mulder'], array_column($digest['away'], 'name'));
		self::assertSame(['2026-10-09', null], array_column($digest['away'], 'until'));
		self::assertSame(['Cor Smit'], $digest['birthdays'], 'Only a birthday the employee agreed to share.');

		$message = $digest['message'];
		self::assertStringContainsString('Burgerzaken', $message);
		self::assertStringContainsString('Anna Bakker', $message);
		self::assertStringContainsString('9-10-2026', $message);
		self::assertStringContainsString('Dirk Mulder', $message);
		self::assertStringContainsString('Cor Smit', $message);
		foreach (['Bram', 'Eva', 'zwangerschap', 'Bevalling', 'ziek', 'sick', '1979', '47'] as $absent) {
			self::assertStringNotContainsStringIgnoringCase($absent, $message);
		}
	}//end testTheMorningMessage()

	/**
	 * Without sub-teams only the unit's own members count.
	 *
	 * @return void
	 */
	public function testSubTeamsCountOnlyWhenAskedFor(): void {
		$digest = $this->composer()->compose('unit-bz', false, self::TODAY);
		self::assertSame(['Anna Bakker'], array_column($digest['away'], 'name'));
	}//end testSubTeamsCountOnlyWhenAskedFor()

	/**
	 * A quiet day posts nothing, and an unknown unit is an error the run shows.
	 *
	 * @return void
	 */
	public function testAQuietDayPostsNothing(): void {
		$items = $this->node()->execute([], ['orgUnitId' => 'unit-fin', 'includeChildren' => true], []);
		self::assertCount(1, $items, 'Eva shares her birthday today.');

		$quiet = $this->node('2026-10-12')->execute([], ['orgUnitId' => 'unit-balie', 'includeChildren' => true], []);
		self::assertSame([], $quiet, 'Nothing to say: no item, so the Talk step posts nothing.');

		$this->expectException(RuntimeException::class);
		$this->node()->execute([], ['orgUnitId' => 'unit-missing'], []);
	}//end testAQuietDayPostsNothing()

	/**
	 * The node puts the message on the item where the Talk step reads it.
	 *
	 * @return void
	 */
	public function testTheNodeHandsTheMessageToTheTalkStep(): void {
		$items = $this->node()->execute([['json' => []]], ['orgUnitId' => 'unit-bz', 'includeChildren' => true], []);

		self::assertCount(1, $items);
		self::assertSame('humaniq.team-digest', $this->node()->getId());
		self::assertStringContainsString('Anna Bakker', $items[0]['json']['digest']['message']);
		self::assertSame(2, $items[0]['json']['digest']['awayCount']);

		$this->expectException(\InvalidArgumentException::class);
		$this->node()->validateConfig([]);
	}//end testTheNodeHandsTheMessageToTheTalkStep()

	/**
	 * The shipped "Dagbericht" flow starts on a working-day schedule, runs the
	 * team-digest step and posts it to Talk, and arrives disabled and inert.
	 *
	 * @return void
	 */
	public function testTheDagberichtFlowShipsInert(): void {
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/hr-org.json'), true);
		$flows = ($fragment['components']['schemas']['OrgUnit']['configuration']['x-openregister-flows'] ?? []);
		$flow = null;
		foreach ($flows as $candidate) {
			if (($candidate['name'] ?? '') === 'Dagbericht') {
				$flow = $candidate;
			}
		}

		self::assertIsArray($flow);
		self::assertSame('humaniq', $flow['app']);
		self::assertSame('schedule', $flow['trigger']);
		self::assertSame('0 8 * * 1-5', $flow['cron']);
		self::assertArrayNotHasKey('enabled', $flow, 'A declared flow arrives disabled; the declaration must not claim otherwise.');
		$types = array_column($flow['nodes'], 'type', 'id');
		self::assertSame(['openregister.trigger-schedule', 'humaniq.team-digest', 'openregister.send-talk-message', 'openregister.end'], array_values($types));
		$talk = array_values(array_filter($flow['nodes'], static fn (array $n): bool => $n['type'] === 'openregister.send-talk-message'))[0];
		self::assertSame('{{ digest.message }}', $talk['config']['message']);
		$ids = array_keys($types);
		foreach ($flow['edges'] as $edge) {
			self::assertContains($edge['from'], $ids);
			self::assertContains($edge['to'], $ids);
		}

		self::assertCount(count($ids) - 1, $flow['edges']);
	}//end testTheDagberichtFlowShipsInert()

	/**
	 * Seed a member with an assignment active today.
	 *
	 * @param string               $id     The employee id.
	 * @param string               $first  The first name.
	 * @param string               $last   The last name.
	 * @param string               $unitId The unit.
	 * @param array<string, mixed> $extra  More employee fields.
	 *
	 * @return void
	 */
	private function member(string $id, string $first, string $last, string $unitId, array $extra): void {
		$this->store->seed('Employee', $id, array_merge(['firstName' => $first, 'lastName' => $last, 'administrationId' => 'ADM-001'], $extra));
		$this->store->seed('OrgAssignment', 'asg-' . $id, ['employeeId' => $id, 'orgUnitId' => $unitId, 'startDate' => '2024-01-01']);
	}//end member()

	/**
	 * The composer over the fake store.
	 *
	 * @return TeamDigestComposer
	 */
	private function composer(): TeamDigestComposer {
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $this->settings(),
			orgResolution: new OrgResolutionService()
		);

		return new TeamDigestComposer(gateway: $gateway, membership: new UnitMembership(), l10n: $this->l10n());
	}//end composer()

	/**
	 * The node, on a fixed day.
	 *
	 * @param string $today The day the run is on.
	 *
	 * @return TeamDigestNode
	 */
	private function node(string $today=self::TODAY): TeamDigestNode {
		return new TeamDigestNode(l10n: $this->l10n(), urls: $this->urls(), composer: $this->composer(), today: $today);
	}//end node()

}//end class
