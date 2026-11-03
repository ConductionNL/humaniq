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
 * works in unit Facilitair. Hanna is HR. Eva left Zorg in 2021, and a shift of
 * hers was left on the roster. Dora works in Zorg without a Nextcloud account.
 * `admin` is a Nextcloud administrator. Zorg sits under unit Organisatie,
 * managed by Olga. Dirk stands in for Mila today; Evert's stand-in ended last
 * year. Gina and Anna also have a placement in a unit nobody registered.
 * Ursula's and Vince's Zorg placements carry a date nobody can read. A
 * placement in Zorg names an employee who does not exist.
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
use OCA\Humaniq\Service\ManagerDeputies;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\RecordStoreInterface;
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
	 * Schemas whose rows a case replaces.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $patch = [];

	/**
	 * Whether availability and capacity run the real services instead of
	 * echoing the employees they were asked for.
	 *
	 * @var bool
	 */
	private bool $realHours = false;

	/**
	 * The organisation and its planned items.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function world(): array {
		return array_replace($this->baseWorld(), $this->patch);
	}//end world()

	/**
	 * The organisation before a case patches it.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function baseWorld(): array {
		$placed = ['startDate' => '2020-01-01'];

		return [
			'Employee' => [
				['id' => 'emp-anna', 'nextcloudUserId' => 'anna'],
				['id' => 'emp-bram', 'nextcloudUserId' => 'bram'],
				['id' => 'emp-mila', 'nextcloudUserId' => 'mila'],
				['id' => 'emp-carl', 'nextcloudUserId' => 'carl'],
				['id' => 'emp-hanna', 'nextcloudUserId' => 'hanna'],
				['id' => 'emp-eva', 'nextcloudUserId' => 'eva'],
				['id' => 'emp-dora', 'nextcloudUserId' => ''],
				['id' => 'emp-olga', 'nextcloudUserId' => 'olga'],
				['id' => 'emp-dirk', 'nextcloudUserId' => 'dirk'],
				['id' => 'emp-evert', 'nextcloudUserId' => 'evert'],
				['id' => 'emp-gina', 'nextcloudUserId' => 'gina'],
				['id' => 'emp-ursula', 'nextcloudUserId' => 'ursula'],
				['id' => 'emp-vince', 'nextcloudUserId' => 'vince'],
			],
			'OrgUnit' => [
				['id' => 'unit-org', 'managerId' => 'emp-olga'],
				['id' => 'unit-loop-a', 'managerId' => 'emp-eva', 'parentUnitId' => 'unit-loop-b'],
				['id' => 'unit-loop-b', 'managerId' => 'emp-eva', 'parentUnitId' => 'unit-loop-a'],
				['id' => 'unit-zorg', 'managerId' => 'emp-mila', 'parentUnitId' => 'unit-org'],
				['id' => 'unit-fac', 'managerId' => 'emp-hanna'],
			],
			'OrgAssignment' => [
				['id' => 'oa-1', 'employeeId' => 'emp-anna', 'orgUnitId' => 'unit-zorg'] + $placed,
				['id' => 'oa-2', 'employeeId' => 'emp-bram', 'orgUnitId' => 'unit-zorg'] + $placed,
				['id' => 'oa-3', 'employeeId' => 'emp-carl', 'orgUnitId' => 'unit-fac'] + $placed,
				['id' => 'oa-4', 'employeeId' => 'emp-hanna', 'orgUnitId' => 'unit-fac'] + $placed,
				['id' => 'oa-5', 'employeeId' => 'emp-eva', 'orgUnitId' => 'unit-zorg', 'startDate' => '2019-01-01', 'endDate' => '2021-01-01'],
				['id' => 'oa-6', 'employeeId' => 'emp-dora', 'orgUnitId' => 'unit-zorg'] + $placed,
				['id' => 'oa-7', 'employeeId' => 'emp-olga', 'orgUnitId' => 'unit-org'] + $placed,
				['id' => 'oa-8', 'employeeId' => 'emp-dirk', 'orgUnitId' => 'unit-fac'] + $placed,
				['id' => 'oa-9', 'employeeId' => 'emp-evert', 'orgUnitId' => 'unit-fac'] + $placed,
				['id' => 'oa-10', 'employeeId' => 'emp-gina', 'orgUnitId' => 'unit-ghost'] + $placed,
				['id' => 'oa-11', 'employeeId' => 'emp-anna', 'orgUnitId' => 'unit-ghost'] + $placed,
				['id' => 'oa-12', 'employeeId' => 'emp-ursula', 'orgUnitId' => 'unit-zorg', 'startDate' => 'sinds kort'],
				['id' => 'oa-13', 'employeeId' => 'emp-vince', 'orgUnitId' => 'unit-zorg', 'startDate' => '2020-01-01', 'endDate' => 'tot nader order'],
				['id' => 'oa-14', 'employeeId' => 'emp-nobody', 'orgUnitId' => 'unit-zorg'] + $placed,
			],
			'ManagerDeputy' => [
				['id' => 'dep-1', 'managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => date('Y-m-d', strtotime('-1 day')), 'until' => date('Y-m-d', strtotime('+1 day'))],
				['id' => 'dep-2', 'managerUserId' => 'mila', 'deputyUserId' => 'evert', 'from' => date('Y-m-d', strtotime('-400 days')), 'until' => date('Y-m-d', strtotime('-300 days'))],
			],
			'RosterAssignment' => [
				['id' => 'ra-anna', 'employeeId' => 'emp-anna', 'date' => '2026-09-01', 'shiftId' => 'shift-1'],
				['id' => 'ra-bram', 'employeeId' => 'emp-bram', 'date' => '2026-09-02', 'shiftId' => 'shift-1'],
				['id' => 'ra-eva', 'employeeId' => 'emp-eva', 'date' => '2026-09-03', 'shiftId' => 'shift-1'],
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
	}//end baseWorld()

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

		$availability = $this->createMock(AvailabilityService::class);
		$availability->method('availability')->willReturnCallback(
			static fn (array $employeeIds): array => array_map(static fn (string $id): array => ['employeeId' => $id], $employeeIds)
		);
		$capacity = $this->createMock(ForwardCapacityService::class);
		$capacity->method('capacity')->willReturnCallback(static fn (array $employeeIds): array => ['employeeIds' => $employeeIds]);

		$roles = new HumaniqRoles($this->groupManager());
		$factories = [
			IRequest::class => static fn (): IRequest => $request,
			IUserSession::class => fn (): IUserSession => $this->session($uid),
			HoursRegisterGateway::class => static fn (): HoursRegisterGateway => $gateway,
			RecordStoreInterface::class => static fn (): HoursRegisterGateway => $gateway,
			AgendaComposer::class => static fn (): AgendaComposer => new AgendaComposer(),
			AvailabilityService::class => fn (): AvailabilityService => ($this->realHours === true ? new AvailabilityService() : $availability),
			ForwardCapacityService::class => fn (): ForwardCapacityService => ($this->realHours === true ? new ForwardCapacityService() : $capacity),
			OrgResolutionService::class => static fn (): OrgResolutionService => new OrgResolutionService(),
			WorkingCalendarReader::class => static fn (): WorkingCalendarReader => $calendar,
			HumaniqRoles::class => static fn (): HumaniqRoles => $roles,
			ManagerDeputies::class => static fn (): ManagerDeputies => new ManagerDeputies($gateway, $roles),
		];
		$factories[AgendaReadAccess::class] = fn (): object => $this->build(AgendaReadAccess::class, $factories);

		return $this->build(AgendaController::class, $factories);
	}//end controller()

	/**
	 * Construct a class with a double per constructor parameter type.
	 *
	 * @param class-string                    $class     The class.
	 * @param array<string, callable():mixed> $factories A double per type.
	 *
	 * @return object
	 */
	private function build(string $class, array $factories): object {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$this->assertArrayHasKey($type->getName(), $factories, 'no double for ' . $type->getName());
			$arguments[] = $factories[$type->getName()]();
		}

		return new $class(...$arguments);
	}//end build()

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

	/**
	 * Read availability or capacity for the first week of September 2026.
	 *
	 * @param string|null $viewer    The acting user.
	 * @param string      $endpoint  `availability` or `capacity`.
	 * @param string      $orgUnitId The unit, or '' for no unit.
	 *
	 * @return JSONResponse
	 */
	private function hours(?string $viewer, string $endpoint, string $orgUnitId): JSONResponse {
		return $this->controller($viewer, ['userId' => 'hanna', 'role' => 'hr'])->{$endpoint}(
			from: '2026-09-01',
			to: '2026-09-07',
			orgUnitId: $orgUnitId
		);
	}//end hours()

	/**
	 * The employee ids an availability or capacity answer covers, sorted.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return list<string>
	 */
	private function covered(JSONResponse $response): array {
		$data = $response->getData();
		$ids = ($data['employeeIds'] ?? array_column(($data['employees'] ?? []), 'employeeId'));
		sort($ids);

		return $ids;
	}//end covered()

	/**
	 * Assert a refused hours read, and that no planned item was read for it.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return void
	 */
	private function assertHoursRefused(JSONResponse $response): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error'], array_keys($response->getData()));
		$this->assertStringStartsWith('Je kunt deze uren niet opvragen.', $response->getData()['error']);
		foreach (['Interview', 'Secondment', 'SickLeaveCase', 'RosterAssignment', 'ResourceBooking'] as $schema) {
			$this->assertNotContains($schema, $this->loaded, $schema . ' was read for a refused hours read');
		}
	}//end assertHoursRefused()

	/**
	 * Data provider: who may open the Zorg agenda and its hours besides its
	 * members and HR: the manager, the manager of the unit above it, and the
	 * manager's deputy of today.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unitManagersAndDeputies(): array {
		return [
			'the unit manager' => ['mila'],
			'the manager of the unit above' => ['olga'],
			'the manager\'s deputy today' => ['dirk'],
		];
	}//end unitManagersAndDeputies()

	/**
	 * A parent-unit manager and a deputy see the whole unit agenda.
	 *
	 * @param string $viewer The acting user.
	 *
	 * @return void
	 *
	 * @dataProvider unitManagersAndDeputies
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testParentManagersAndDeputiesSeeTheWholeUnitAgenda(string $viewer): void {
		$response = $this->read($viewer, 'orgUnit', 'unit-zorg');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertContains('interview:emp-anna', $this->kinds($response));
		$this->assertSame(['emp-anna' => 16.0, 'emp-bram' => 8.0], $this->secondmentHours($response));
	}//end testParentManagersAndDeputiesSeeTheWholeUnitAgenda()

	/**
	 * A deputy whose stand-in has ended is refused the unit agenda.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAnEndedDeputyIsRefusedTheUnitAgenda(): void {
		$this->assertRefused($this->read('evert', 'orgUnit', 'unit-zorg'));
	}//end testAnEndedDeputyIsRefusedTheUnitAgenda()

	/**
	 * A parent-unit manager and a deputy open the unit, not a person: an
	 * employee's agenda stays with the employee's own managers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testParentManagersAndDeputiesDoNotOpenAnEmployeeAgenda(): void {
		$this->assertRefused($this->read('olga', 'employee', 'emp-anna'));
		$this->assertRefused($this->read('dirk', 'employee', 'emp-anna'));
	}//end testParentManagersAndDeputiesDoNotOpenAnEmployeeAgenda()

	/**
	 * A subject without an Employee record is refused, even when a placement
	 * puts it in the viewer's unit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testASubjectWithoutAnEmployeeRecordIsRefused(): void {
		$this->assertRefused($this->read('bram', 'employee', 'emp-nobody'));
	}//end testASubjectWithoutAnEmployeeRecordIsRefused()

	/**
	 * A placement in a unit nobody registered makes nobody a colleague, and
	 * that unit's agenda opens for nobody but HR.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAPlacementInAnUnregisteredUnitOpensNothing(): void {
		$this->assertRefused($this->read('gina', 'employee', 'emp-anna'));
		$this->assertRefused($this->read('gina', 'orgUnit', 'unit-ghost'));
		$this->assertRefused($this->read('anna', 'orgUnit', 'unit-ghost'));
	}//end testAPlacementInAnUnregisteredUnitOpensNothing()

	/**
	 * A placement whose start or end date cannot be read does not count.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAPlacementWithAnUnreadableDateDoesNotCount(): void {
		$this->assertRefused($this->read('ursula', 'employee', 'emp-anna'));
		$this->assertRefused($this->read('vince', 'employee', 'emp-anna'));
		$this->assertRefused($this->read('ursula', 'orgUnit', 'unit-zorg'));
		$this->assertRefused($this->read('bram', 'employee', 'emp-vince'));
	}//end testAPlacementWithAnUnreadableDateDoesNotCount()

	/**
	 * A refused resource agenda says why, like the other refusals.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testARefusedResourceAgendaSaysWhy(): void {
		$this->assertRefused($this->read(null, 'resource', 'room-1'));
	}//end testARefusedResourceAgendaSaysWhy()

	/**
	 * Data provider: the endpoints that answer hours.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function hourEndpoints(): array {
		return [
			'availability' => ['availability'],
			'capacity' => ['capacity'],
		];
	}//end hourEndpoints()

	/**
	 * A unit's hours open for its members, its managers, the managers above it,
	 * a deputy and HR, and cover the unit's members.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider hourEndpoints
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testAUnitsHoursOpenForWhoMayReadItsAgenda(string $endpoint): void {
		foreach (['bram', 'mila', 'olga', 'dirk', 'hanna', 'admin'] as $viewer) {
			$response = $this->hours($viewer, $endpoint, 'unit-zorg');

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $viewer);
			$this->assertContains('emp-anna', $this->covered($response), $viewer);
			$this->assertContains('emp-bram', $this->covered($response), $viewer);
		}
	}//end testAUnitsHoursOpenForWhoMayReadItsAgenda()

	/**
	 * Anyone else is refused a unit's hours before anything is read, whatever
	 * the request claims.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider hourEndpoints
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testAnyoneElseIsRefusedAUnitsHours(string $endpoint): void {
		foreach (['carl', 'eva', 'evert', 'gina', 'ursula', 'stranger', null] as $viewer) {
			$this->loaded = [];
			$this->assertHoursRefused($this->hours($viewer, $endpoint, 'unit-zorg'));
		}

		$this->loaded = [];
		$this->assertHoursRefused($this->hours('gina', $endpoint, 'unit-ghost'));
	}//end testAnyoneElseIsRefusedAUnitsHours()

	/**
	 * Without a unit, HR gets every employee.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider hourEndpoints
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testWithoutAUnitHrGetsEveryEmployee(string $endpoint): void {
		$response = $this->hours('hanna', $endpoint, '');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['emp-anna', 'emp-bram', 'emp-carl', 'emp-dirk', 'emp-dora', 'emp-eva', 'emp-evert', 'emp-gina', 'emp-hanna', 'emp-mila', 'emp-olga', 'emp-ursula', 'emp-vince'],
			$this->covered($response)
		);
	}//end testWithoutAUnitHrGetsEveryEmployee()

	/**
	 * Without a unit, anyone else gets only the people whose agenda they may
	 * read: themself, the people they manage and their colleagues.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider hourEndpoints
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testWithoutAUnitOthersGetOnlyWhoTheyMaySee(string $endpoint): void {
		$this->assertSame(['emp-anna', 'emp-bram', 'emp-dora'], $this->covered($this->hours('bram', $endpoint, '')));
		$this->assertSame(['emp-carl', 'emp-dirk', 'emp-evert', 'emp-hanna'], $this->covered($this->hours('carl', $endpoint, '')));
		$this->assertSame(['emp-gina'], $this->covered($this->hours('gina', $endpoint, '')));
	}//end testWithoutAUnitOthersGetOnlyWhoTheyMaySee()

	/**
	 * Without a unit, a user who may read nobody's agenda is refused.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider hourEndpoints
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testWithoutAUnitSomeoneWhoMaySeeNobodyIsRefused(string $endpoint): void {
		$this->assertHoursRefused($this->hours('stranger', $endpoint, ''));
		$this->loaded = [];
		$this->assertHoursRefused($this->hours(null, $endpoint, ''));
	}//end testWithoutAUnitSomeoneWhoMaySeeNobodyIsRefused()

	/**
	 * A unit tree that loops back on itself ends the walk and refuses.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testALoopInTheUnitTreeEndsTheWalk(): void {
		$this->assertRefused($this->read('carl', 'orgUnit', 'unit-loop-a'));
	}//end testALoopInTheUnitTreeEndsTheWalk()

	/**
	 * Put the employees of Zorg and the unit above it in administrations.
	 *
	 * @param array<string, string> $employees Employee id to administration; the rest get `adm-A`.
	 * @param array<string, string> $units     Unit id to administration; the rest get `adm-A`.
	 *
	 * @return void
	 */
	private function administrations(array $employees, array $units): void {
		$world = $this->baseWorld();
		foreach ($world['Employee'] as $index => $employee) {
			$world['Employee'][$index]['administrationId'] = ($employees[$employee['id']] ?? 'adm-A');
		}

		foreach ($world['OrgUnit'] as $index => $unit) {
			$world['OrgUnit'][$index]['administrationId'] = ($units[$unit['id']] ?? 'adm-A');
		}

		$this->patch = ['Employee' => $world['Employee'], 'OrgUnit' => $world['OrgUnit']];
	}//end administrations()

	/**
	 * The manager of the unit above opens the unit only within its
	 * administration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testTheClimbStopsAtTheAdministrationBoundary(): void {
		$this->administrations(['emp-olga' => 'adm-B'], ['unit-org' => 'adm-B']);
		$this->assertRefused($this->read('olga', 'orgUnit', 'unit-zorg'));

		$this->loaded = [];
		$this->administrations([], ['unit-org' => 'adm-B']);
		$this->assertRefused($this->read('olga', 'orgUnit', 'unit-zorg'));

		$this->loaded = [];
		$this->administrations([], []);
		$this->assertSame(Http::STATUS_OK, $this->read('olga', 'orgUnit', 'unit-zorg')->getStatus());
	}//end testTheClimbStopsAtTheAdministrationBoundary()

	/**
	 * A manager whose own Employee record lies in another administration
	 * opens neither the unit nor anyone in it, and nor does their deputy.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAManagerOutsideTheAdministrationOpensNothing(): void {
		$this->administrations(['emp-mila' => 'adm-B'], []);
		$this->assertRefused($this->read('mila', 'orgUnit', 'unit-zorg'));
		$this->assertRefused($this->read('mila', 'employee', 'emp-anna'));
		$this->assertRefused($this->read('dirk', 'orgUnit', 'unit-zorg'));
	}//end testAManagerOutsideTheAdministrationOpensNothing()

	/**
	 * Data provider: the endpoints that answer hours, with the field that
	 * carries an employee's planned hours.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function plannedHourFields(): array {
		return [
			'availability' => ['availability', 'committedHours'],
			'capacity' => ['capacity', 'plannedHours'],
		];
	}//end plannedHourFields()

	/**
	 * Planned hours per employee from the real availability or capacity
	 * answer, for one viewer and one unit, over one window.
	 *
	 * @param string|null $viewer   The acting user.
	 * @param string      $endpoint `availability` or `capacity`.
	 * @param string      $field    The planned-hours field.
	 * @param string      $from     First day.
	 * @param string      $to       Last day.
	 * @param string      $orgUnitId The unit, or '' for none.
	 *
	 * @return array<string, float>
	 */
	private function plannedHours(?string $viewer, string $endpoint, string $field, string $from, string $to, string $orgUnitId = 'unit-zorg'): array {
		$this->realHours = true;
		$response = $this->controller($viewer)->{$endpoint}(from: $from, to: $to, orgUnitId: $orgUnitId);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$planned = [];
		foreach ($response->getData()['employees'] as $row) {
			$planned[$row['employeeId']] = (float)$row[$field];
		}

		return $planned;
	}//end plannedHours()

	/**
	 * A full working week for one employee.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed>
	 */
	private function fullWeek(string $employeeId): array {
		return ['id' => 'wp-' . $employeeId, 'employeeId' => $employeeId, 'validFrom' => '2026-01-01', 'hoursMonday' => 8, 'hoursTuesday' => 8, 'hoursWednesday' => 8, 'hoursThursday' => 8, 'hoursFriday' => 8];
	}//end fullWeek()

	/**
	 * A colleague's view of the hours leaves a secondment out, so its hours
	 * per week cannot be read off the planned hours.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $field    The planned-hours field.
	 *
	 * @return void
	 *
	 * @dataProvider plannedHourFields
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testAColleagueCannotReadSecondmentHoursOffPlannedHours(string $endpoint, string $field): void {
		$this->patch = [
			'WorkingPattern' => [$this->fullWeek('emp-anna'), $this->fullWeek('emp-bram')],
			'Interview' => [],
			'SickLeaveCase' => [],
			'RosterAssignment' => [],
			'ResourceBooking' => [],
		];

		$colleague = $this->plannedHours('bram', $endpoint, $field, '2026-09-07', '2026-09-11');
		$this->assertEqualsWithDelta(0.0, $colleague['emp-anna'], 0.01);
		$this->assertEqualsWithDelta(8.0, $colleague['emp-bram'], 0.01, 'his own secondment still counts');

		$manager = $this->plannedHours('mila', $endpoint, $field, '2026-09-07', '2026-09-11');
		$this->assertEqualsWithDelta(16.0, $manager['emp-anna'], 0.01);

		$unscoped = $this->plannedHours('bram', $endpoint, $field, '2026-09-07', '2026-09-11', '');
		$this->assertEqualsWithDelta(0.0, $unscoped['emp-anna'], 0.01);
		$this->assertEqualsWithDelta(8.0, $unscoped['emp-bram'], 0.01);
		$this->assertEqualsWithDelta(16.0, $this->plannedHours('hanna', $endpoint, $field, '2026-09-07', '2026-09-11', '')['emp-anna'], 0.01);
	}//end testAColleagueCannotReadSecondmentHoursOffPlannedHours()

	/**
	 * A colleague's view of the hours leaves an interview out, so its date
	 * and length cannot be read off the planned hours.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $field    The planned-hours field.
	 *
	 * @return void
	 *
	 * @dataProvider plannedHourFields
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testAColleagueCannotReadAnInterviewOffPlannedHours(string $endpoint, string $field): void {
		$this->patch = [
			'WorkingPattern' => [$this->fullWeek('emp-anna'), $this->fullWeek('emp-bram')],
			'Secondment' => [],
			'SickLeaveCase' => [],
			'RosterAssignment' => [],
			'ResourceBooking' => [],
			'Interview' => [
				['id' => 'int-x', 'employeeId' => 'emp-anna', 'interviewerIds' => ['emp-bram'], 'scheduledStart' => '2026-09-08T10:00:00', 'scheduledEnd' => '2026-09-08T11:30:00'],
				['id' => 'int-y', 'employeeId' => 'emp-bram', 'interviewerIds' => ['emp-anna'], 'scheduledStart' => '2026-09-08T14:00:00', 'scheduledEnd' => '2026-09-08T15:00:00'],
			],
		];

		$colleague = $this->plannedHours('bram', $endpoint, $field, '2026-09-08', '2026-09-08');
		$this->assertEqualsWithDelta(0.0, $colleague['emp-anna'], 0.01);
		$this->assertEqualsWithDelta(2.5, $colleague['emp-bram'], 0.01, 'his own interviews still count');

		$this->assertEqualsWithDelta(2.5, $this->plannedHours('hanna', $endpoint, $field, '2026-09-08', '2026-09-08')['emp-anna'], 0.01);
	}//end testAColleagueCannotReadAnInterviewOffPlannedHours()

	/**
	 * A window that starts years ago does not bring back former members for
	 * anyone but HR, and neither does a placement with an unreadable date.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testAnEarlyWindowDoesNotBringBackFormerMembers(): void {
		$members = fn (?string $viewer): array => $this->covered($this->controller($viewer)->availability(from: '2020-06-01', to: '2026-09-07', orgUnitId: 'unit-zorg'));

		$this->assertSame(['emp-anna', 'emp-bram', 'emp-dora', 'emp-nobody'], $members('bram'));
		$this->assertSame(['emp-anna', 'emp-bram', 'emp-dora', 'emp-nobody'], $members('mila'));
		$this->assertContains('emp-eva', $members('hanna'));

		$unit = $this->controller('mila')->agenda(subjectType: 'orgUnit', subjectId: 'unit-zorg', from: '2020-06-01', to: '2026-09-07');
		$this->assertNotContains('shift:emp-eva', $this->kinds($unit));
	}//end testAnEarlyWindowDoesNotBringBackFormerMembers()

	/**
	 * A unit without an administration takes its boundary from its members:
	 * the manager above it in another administration does not open it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAnUnlabelledUnitTakesItsMembersAdministration(): void {
		$this->administrations(['emp-olga' => 'adm-B'], ['unit-org' => 'adm-B']);
		$this->patch['OrgUnit'] = array_map(
			static fn (array $unit): array => ($unit['id'] === 'unit-zorg' ? array_diff_key($unit, ['administrationId' => true]) : $unit),
			$this->patch['OrgUnit']
		);

		$this->assertRefused($this->read('olga', 'orgUnit', 'unit-zorg'));
		$this->loaded = [];
		$this->assertHoursRefused($this->hours('olga', 'availability', 'unit-zorg'));
	}//end testAnUnlabelledUnitTakesItsMembersAdministration()

	/**
	 * The manager of a unit without an administration, whose own record lies
	 * in another administration than the members', does not open it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAnUnlabelledUnitsManagerOutsideTheMembersAdministrationIsRefused(): void {
		$world = $this->baseWorld();
		foreach ($world['Employee'] as $index => $employee) {
			$world['Employee'][$index]['administrationId'] = ($employee['id'] === 'emp-mila' ? 'adm-B' : 'adm-A');
		}

		$this->patch = ['Employee' => $world['Employee']];
		$this->assertRefused($this->read('mila', 'orgUnit', 'unit-zorg'));
		$this->assertRefused($this->read('mila', 'employee', 'emp-anna'));
	}//end testAnUnlabelledUnitsManagerOutsideTheMembersAdministrationIsRefused()

	/**
	 * The manager of a unit without an administration, in the same
	 * administration as its members, still opens it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAnUnlabelledUnitsManagerInTheMembersAdministrationOpensIt(): void {
		$world = $this->baseWorld();
		foreach ($world['Employee'] as $index => $employee) {
			$world['Employee'][$index]['administrationId'] = 'adm-A';
		}

		$this->patch = ['Employee' => $world['Employee']];
		$response = $this->read('mila', 'orgUnit', 'unit-zorg');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertContains('interview:emp-anna', $this->kinds($response));
	}//end testAnUnlabelledUnitsManagerInTheMembersAdministrationOpensIt()

	/**
	 * A unit without an administration whose members belong to different
	 * administrations has no boundary to climb within: only its own members
	 * and HR open it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAnUnlabelledUnitWithMixedMembersOpensForNoManager(): void {
		$world = $this->baseWorld();
		foreach ($world['Employee'] as $index => $employee) {
			$world['Employee'][$index]['administrationId'] = ($employee['id'] === 'emp-bram' ? 'adm-B' : 'adm-A');
		}

		$this->patch = ['Employee' => $world['Employee']];
		$this->assertRefused($this->read('mila', 'orgUnit', 'unit-zorg'));
		$this->assertRefused($this->read('olga', 'orgUnit', 'unit-zorg'));
		$this->assertSame(Http::STATUS_OK, $this->read('bram', 'orgUnit', 'unit-zorg')->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->read('hanna', 'orgUnit', 'unit-zorg')->getStatus());
	}//end testAnUnlabelledUnitWithMixedMembersOpensForNoManager()

	/**
	 * Data provider: the interview fields that name who leads or joins it.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function interviewRoles(): array {
		return [
			'leads it' => ['interviewerId'],
			'joins it' => ['participantId'],
		];
	}//end interviewRoles()

	/**
	 * A colleague who leads or joins someone else's interview has it counted
	 * as their own, and the interviewee's planned hours keep it out.
	 *
	 * @param string $field The field naming the colleague.
	 *
	 * @return void
	 *
	 * @dataProvider interviewRoles
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function testAnInterviewLedOrJoinedByAColleagueCountsOnlyForThem(string $field): void {
		$this->patch = [
			'WorkingPattern' => [$this->fullWeek('emp-anna'), $this->fullWeek('emp-bram')],
			'Secondment' => [],
			'SickLeaveCase' => [],
			'RosterAssignment' => [],
			'ResourceBooking' => [],
			'Interview' => [
				['id' => 'int-1', 'employeeId' => 'emp-bram', $field => 'emp-anna', 'scheduledStart' => '2026-09-08T10:00:00', 'scheduledEnd' => '2026-09-08T12:00:00'],
			],
		];

		$colleague = $this->plannedHours('bram', 'availability', 'committedHours', '2026-09-08', '2026-09-08');
		$this->assertEqualsWithDelta(0.0, $colleague['emp-anna'], 0.01);
		$this->assertEqualsWithDelta(2.0, $colleague['emp-bram'], 0.01);
		$this->assertEqualsWithDelta(2.0, $this->plannedHours('hanna', 'availability', 'committedHours', '2026-09-08', '2026-09-08')['emp-anna'], 0.01);
	}//end testAnInterviewLedOrJoinedByAColleagueCountsOnlyForThem()

	/**
	 * A placement that starts today, or ends today, makes a colleague today.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function testAPlacementOnItsFirstOrLastDayCounts(): void {
		$today = date('Y-m-d');
		foreach ([['startDate' => $today], ['startDate' => '2020-01-01', 'endDate' => $today]] as $dates) {
			$world = $this->baseWorld();
			$world['OrgAssignment'][] = ['id' => 'oa-carl-zorg', 'employeeId' => 'emp-carl', 'orgUnitId' => 'unit-zorg'] + $dates;
			$this->patch = ['OrgAssignment' => $world['OrgAssignment']];
			$this->assertSame(Http::STATUS_OK, $this->read('carl', 'employee', 'emp-anna')->getStatus(), json_encode($dates));
		}

		$world = $this->baseWorld();
		$world['OrgAssignment'][] = ['id' => 'oa-carl-zorg', 'employeeId' => 'emp-carl', 'orgUnitId' => 'unit-zorg', 'startDate' => '2020-01-01', 'endDate' => date('Y-m-d', strtotime('-1 day'))];
		$this->patch = ['OrgAssignment' => $world['OrgAssignment']];
		$this->loaded = [];
		$this->assertRefused($this->read('carl', 'employee', 'emp-anna'));
	}//end testAPlacementOnItsFirstOrLastDayCounts()

}//end class
