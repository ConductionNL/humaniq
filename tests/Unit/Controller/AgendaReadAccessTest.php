<?php

/**
 * Who may read an agenda, through `GET /api/agenda`
 *
 * Every case runs the real controller, composer, org resolution and role check
 * over one small organisation. Only the register, the session and the group
 * manager are doubled, and each answers for the acting user alone, so a check
 * that ran for somebody else, or for a name taken from the request, fails here.
 *
 * The organisation: Anna and Bram work in unit Zorg, managed by Mila. Carl
 * works in unit Facilitair. Hanna is HR. Eva left Zorg in 2021. `admin` is a
 * Nextcloud administrator.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\AgendaController;
use OCA\Humaniq\Service\AgendaComposer;
use OCA\Humaniq\Service\AgendaReadAccess;
use OCA\Humaniq\Service\AvailabilityService;
use OCA\Humaniq\Service\ForwardCapacityService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\WorkingCalendarReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Each viewer against each subject.
 */
class AgendaReadAccessTest extends TestCase {

	/**
	 * Schemas the register was asked for, in order.
	 *
	 * @var list<string>
	 */
	private array $loaded = [];

	/**
	 * The organisation and its planned items.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function world(): array {
		$placed = ['startDate' => '2020-01-01'];

		return [
			'Employee' => [
				['id' => 'emp-anna', 'nextcloudUserId' => 'anna'],
				['id' => 'emp-bram', 'nextcloudUserId' => 'bram'],
				['id' => 'emp-mila', 'nextcloudUserId' => 'mila'],
				['id' => 'emp-carl', 'nextcloudUserId' => 'carl'],
				['id' => 'emp-hanna', 'nextcloudUserId' => 'hanna'],
				['id' => 'emp-eva', 'nextcloudUserId' => 'eva'],
			],
			'OrgUnit' => [
				['id' => 'unit-zorg', 'managerId' => 'emp-mila'],
				['id' => 'unit-fac', 'managerId' => 'emp-hanna'],
			],
			'OrgAssignment' => [
				['id' => 'oa-1', 'employeeId' => 'emp-anna', 'orgUnitId' => 'unit-zorg'] + $placed,
				['id' => 'oa-2', 'employeeId' => 'emp-bram', 'orgUnitId' => 'unit-zorg'] + $placed,
				['id' => 'oa-3', 'employeeId' => 'emp-carl', 'orgUnitId' => 'unit-fac'] + $placed,
				['id' => 'oa-4', 'employeeId' => 'emp-hanna', 'orgUnitId' => 'unit-fac'] + $placed,
				['id' => 'oa-5', 'employeeId' => 'emp-eva', 'orgUnitId' => 'unit-zorg', 'startDate' => '2019-01-01', 'endDate' => '2021-01-01'],
			],
			'RosterAssignment' => [
				['id' => 'ra-anna', 'employeeId' => 'emp-anna', 'date' => '2026-09-01', 'shiftId' => 'shift-1'],
				['id' => 'ra-bram', 'employeeId' => 'emp-bram', 'date' => '2026-09-02', 'shiftId' => 'shift-1'],
			],
			'Shift' => [['id' => 'shift-1', 'name' => 'Vroeg']],
			'SickLeaveCase' => [
				['id' => 'sick-anna', 'employeeId' => 'emp-anna', 'firstSickDay' => '2026-09-03', 'reason' => 'griep'],
			],
			'Interview' => [
				['id' => 'int-anna', 'employeeId' => 'emp-anna', 'scheduledStart' => '2026-09-04T10:00:00', 'scheduledEnd' => '2026-09-04T11:00:00'],
				['id' => 'int-bram', 'employeeId' => 'emp-bram', 'scheduledStart' => '2026-09-05T10:00:00', 'scheduledEnd' => '2026-09-05T11:00:00'],
			],
			'Secondment' => [
				['id' => 'sec-anna', 'employeeId' => 'emp-anna', 'status' => 'actief', 'startDate' => '2026-01-01', 'receivingOrganisation' => 'Gemeente Zuid', 'hoursPerWeek' => 16],
				['id' => 'sec-bram', 'employeeId' => 'emp-bram', 'status' => 'actief', 'startDate' => '2026-01-01', 'receivingOrganisation' => 'Gemeente Noord', 'hoursPerWeek' => 8],
			],
			'ResourceBooking' => [
				['id' => 'book-1', 'resourceId' => 'room-1', 'employeeId' => 'emp-anna', 'start' => '2026-09-02T09:00:00', 'end' => '2026-09-02T10:00:00', 'purpose' => 'Overleg'],
			],
		];
	}//end world()

	/**
	 * The controller, built by constructor type, with the given acting user.
	 *
	 * @param string|null          $uid    The session user, or null for none.
	 * @param array<string, mixed> $params What the request claims, for forged-parameter cases.
	 *
	 * @return AgendaController
	 */
	private function controller(?string $uid, array $params = []): AgendaController {
		$world = $this->world();
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(
			function (string $schema) use ($world): array {
				$this->loaded[] = $schema;

				return ($world[$schema] ?? []);
			}
		);
		$gateway->method('findFiltered')->willReturnCallback(
			function (string $schema, array $filters) use ($world): array {
				$this->loaded[] = $schema;

				return array_values(
					array_filter(
						($world[$schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);

		$calendar = $this->createMock(WorkingCalendarReader::class);
		$calendar->method('nonWorkingDates')->willReturn(['dates' => null, 'resolved' => false, 'reason' => 'none']);

		$factories = [
			IRequest::class => static fn (): IRequest => $request,
			HoursRegisterGateway::class => static fn (): HoursRegisterGateway => $gateway,
			AgendaComposer::class => static fn (): AgendaComposer => new AgendaComposer(),
			AvailabilityService::class => fn (): AvailabilityService => $this->createMock(AvailabilityService::class),
			ForwardCapacityService::class => fn (): ForwardCapacityService => $this->createMock(ForwardCapacityService::class),
			OrgResolutionService::class => static fn (): OrgResolutionService => new OrgResolutionService(),
			WorkingCalendarReader::class => static fn (): WorkingCalendarReader => $calendar,
			AgendaReadAccess::class => fn (): object => new AgendaReadAccess(
				$this->session($uid),
				$gateway,
				new OrgResolutionService(),
				new HumaniqRoles($this->groupManager())
			),
		];

		$arguments = [];
		foreach ((new ReflectionClass(AgendaController::class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$this->assertArrayHasKey($type->getName(), $factories, 'no double for ' . $type->getName());
			$arguments[] = $factories[$type->getName()]();
		}

		return new AgendaController(...$arguments);
	}//end controller()

	/**
	 * A session holding one user, or nobody.
	 *
	 * @param string|null $uid The user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);

			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * Hanna is in the HR group and `admin` is an administrator; nobody else
	 * holds anything.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($uid === 'hanna' && $group === HumaniqRoles::HR_GROUP)
		);

		return $groups;
	}//end groupManager()

	/**
	 * Read one agenda for the first week of September 2026.
	 *
	 * @param string|null          $viewer  The acting user.
	 * @param string               $type    The subject kind.
	 * @param string               $subject The subject id.
	 * @param array<string, mixed> $params  Extra request parameters.
	 *
	 * @return JSONResponse
	 */
	private function read(?string $viewer, string $type, string $subject, array $params = []): JSONResponse {
		return $this->controller($viewer, $params)->agenda(
			subjectType: $type,
			subjectId: $subject,
			from: '2026-09-01',
			to: '2026-09-07'
		);
	}//end read()

	/**
	 * The entry kinds per employee, as `kind:employee` strings, sorted.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return list<string>
	 */
	private function kinds(JSONResponse $response): array {
		$kinds = [];
		foreach ($response->getData()['entries'] as $entry) {
			$kinds[] = $entry['kind'] . ':' . $entry['subjectId'];
		}

		sort($kinds);

		return $kinds;
	}//end kinds()

	/**
	 * The secondment hours per employee, null where they were left out.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return array<string, float|null>
	 */
	private function secondmentHours(JSONResponse $response): array {
		$hours = [];
		foreach ($response->getData()['entries'] as $entry) {
			if ($entry['kind'] === 'secondment') {
				$hours[$entry['subjectId']] = ($entry['hoursPerWeek'] ?? null);
			}
		}

		ksort($hours);

		return $hours;
	}//end secondmentHours()

	/**
	 * Assert a refusal, and that no planned item was read for it.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return void
	 */
	private function assertRefused(JSONResponse $response): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error'], array_keys($response->getData()));
		$this->assertStringStartsWith('Je kunt deze agenda niet openen.', $response->getData()['error']);
		foreach (['Interview', 'Secondment', 'SickLeaveCase', 'RosterAssignment', 'ResourceBooking'] as $schema) {
			$this->assertNotContains($schema, $this->loaded, $schema . ' was read for a refused agenda');
		}
	}//end assertRefused()

	/**
	 * Data provider: the viewers who see Anna's agenda in full.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function fullEmployeeViewers(): array {
		return [
			'Anna herself' => ['anna'],
			'her manager Mila' => ['mila'],
			'HR' => ['hanna'],
			'an administrator' => ['admin'],
		];
	}//end fullEmployeeViewers()

	/**
	 * Self, manager and HR see every entry, interview and secondment hours included.
	 *
	 * @param string $viewer The acting user.
	 *
	 * @return void
	 *
	 * @dataProvider fullEmployeeViewers
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testSelfManagerAndHrSeeTheWholeAgenda(string $viewer): void {
		$response = $this->read($viewer, 'employee', 'emp-anna');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['absent:emp-anna', 'booking:emp-anna', 'interview:emp-anna', 'secondment:emp-anna', 'shift:emp-anna'],
			$this->kinds($response)
		);
		$this->assertSame(['emp-anna' => 16.0], $this->secondmentHours($response));
	}//end testSelfManagerAndHrSeeTheWholeAgenda()

	/**
	 * A colleague in the same unit sees the agenda without the interview and
	 * without the secondment hours.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAColleagueSeesTheAgendaWithoutInterviewsOrSecondmentHours(): void {
		$response = $this->read('bram', 'employee', 'emp-anna');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['absent:emp-anna', 'booking:emp-anna', 'secondment:emp-anna', 'shift:emp-anna'], $this->kinds($response));
		$this->assertSame(['emp-anna' => null], $this->secondmentHours($response));
		foreach ($response->getData()['entries'] as $entry) {
			$this->assertArrayNotHasKey('hoursPerWeek', $entry);
		}
	}//end testAColleagueSeesTheAgendaWithoutInterviewsOrSecondmentHours()

	/**
	 * Data provider: who may not open Anna's agenda.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function refusedEmployeeViewers(): array {
		return [
			'someone in another unit' => ['carl'],
			'a former colleague' => ['eva'],
			'a user without an employee record' => ['stranger'],
			'no session' => [null],
		];
	}//end refusedEmployeeViewers()

	/**
	 * Everyone else is refused before any planned item is read.
	 *
	 * @param string|null $viewer The acting user.
	 *
	 * @return void
	 *
	 * @dataProvider refusedEmployeeViewers
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAnyoneElseIsRefusedAnEmployeeAgenda(?string $viewer): void {
		$this->assertRefused($this->read($viewer, 'employee', 'emp-anna'));
	}//end testAnyoneElseIsRefusedAnEmployeeAgenda()

	/**
	 * A name in the request does not stand in for the session user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testForgedRequestParametersDoNotOpenAnAgenda(): void {
		$forged = [
			'userId' => 'anna',
			'uid' => 'mila',
			'viewer' => 'hanna',
			'role' => 'hr',
			'employeeId' => 'emp-anna',
			'orgUnitId' => 'unit-zorg',
			'managerUserId' => 'carl',
		];

		$this->assertRefused($this->read('carl', 'employee', 'emp-anna', $forged));
		$this->assertRefused($this->read('carl', 'orgUnit', 'unit-zorg', $forged));
	}//end testForgedRequestParametersDoNotOpenAnAgenda()

	/**
	 * A forged parameter does not widen what a colleague sees either.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testForgedRequestParametersDoNotWidenAColleaguesView(): void {
		$response = $this->read('bram', 'employee', 'emp-anna', ['userId' => 'anna', 'role' => 'hr', 'managerUserId' => 'bram']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotContains('interview:emp-anna', $this->kinds($response));
		$this->assertSame(['emp-anna' => null], $this->secondmentHours($response));
	}//end testForgedRequestParametersDoNotWidenAColleaguesView()

	/**
	 * Data provider: who sees the whole Zorg agenda.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function fullUnitViewers(): array {
		return [
			'the unit manager' => ['mila'],
			'HR' => ['hanna'],
			'an administrator' => ['admin'],
		];
	}//end fullUnitViewers()

	/**
	 * The unit's manager and HR see every member's entries in full.
	 *
	 * @param string $viewer The acting user.
	 *
	 * @return void
	 *
	 * @dataProvider fullUnitViewers
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testTheUnitManagerAndHrSeeTheWholeUnitAgenda(string $viewer): void {
		$response = $this->read($viewer, 'orgUnit', 'unit-zorg');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'absent:emp-anna',
				'booking:emp-anna',
				'interview:emp-anna',
				'interview:emp-bram',
				'secondment:emp-anna',
				'secondment:emp-bram',
				'shift:emp-anna',
				'shift:emp-bram',
			],
			$this->kinds($response)
		);
		$this->assertSame(['emp-anna' => 16.0, 'emp-bram' => 8.0], $this->secondmentHours($response));
	}//end testTheUnitManagerAndHrSeeTheWholeUnitAgenda()

	/**
	 * A member sees the unit agenda: their own entries in full, a colleague's
	 * without the interview and the secondment hours.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAMemberSeesTheUnitAgendaWithOnlyTheirOwnInterviewsAndHours(): void {
		$response = $this->read('bram', 'orgUnit', 'unit-zorg');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'absent:emp-anna',
				'booking:emp-anna',
				'interview:emp-bram',
				'secondment:emp-anna',
				'secondment:emp-bram',
				'shift:emp-anna',
				'shift:emp-bram',
			],
			$this->kinds($response)
		);
		$this->assertSame(['emp-anna' => null, 'emp-bram' => 8.0], $this->secondmentHours($response));
	}//end testAMemberSeesTheUnitAgendaWithOnlyTheirOwnInterviewsAndHours()

	/**
	 * Data provider: who may not open the Zorg agenda.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function refusedUnitViewers(): array {
		return [
			'a member of another unit' => ['carl'],
			'a former member' => ['eva'],
			'a user without an employee record' => ['stranger'],
			'no session' => [null],
		];
	}//end refusedUnitViewers()

	/**
	 * Anyone outside the unit, its manager and HR is refused.
	 *
	 * @param string|null $viewer The acting user.
	 *
	 * @return void
	 *
	 * @dataProvider refusedUnitViewers
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAnyoneElseIsRefusedAUnitAgenda(?string $viewer): void {
		$this->assertRefused($this->read($viewer, 'orgUnit', 'unit-zorg'));
	}//end testAnyoneElseIsRefusedAUnitAgenda()

	/**
	 * A unit that does not exist is refused like one the viewer may not see.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAnUnknownUnitIsRefused(): void {
		$this->assertRefused($this->read('bram', 'orgUnit', 'unit-unknown'));
	}//end testAnUnknownUnitIsRefused()

	/**
	 * A manager of one unit does not manage the members of another.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAManagerOfAnotherUnitIsRefusedItsMembers(): void {
		$this->assertRefused($this->read('mila', 'employee', 'emp-carl'));
		$this->assertRefused($this->read('mila', 'orgUnit', 'unit-fac'));
	}//end testAManagerOfAnotherUnitIsRefusedItsMembers()

	/**
	 * A room's agenda stays readable by anyone signed in, as before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAResourceAgendaStaysOpenToEverySignedInUser(): void {
		$response = $this->read('carl', 'resource', 'room-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['booking:room-1'], $this->kinds($response));
	}//end testAResourceAgendaStaysOpenToEverySignedInUser()

	/**
	 * A bad request still answers 400, before any access lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function testABadRequestIsStillABadRequest(): void {
		$response = $this->controller('carl')->agenda(subjectType: 'employee', subjectId: 'emp-anna', from: 'nope', to: '2026-09-07');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testABadRequestIsStillABadRequest()

}//end class
