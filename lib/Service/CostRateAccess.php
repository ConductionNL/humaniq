<?php

/**
 * Who may be given an employee's cost rate besides those who can read the salary.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://humaniq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\App\IAppManager;
use OCP\IUserSession;
use RuntimeException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The project-manager path to a cost rate, and the choice of the contract a rate is costed on.
 *
 * Ruben decided (29 Sep 2026) that a project manager of a project the employee
 * works on gets the DERIVED PERSONAL rate while the salary stays hidden. humaniq
 * models no project: planninq does (register `planninq`, schema `project`), its
 * `owner` is the user who runs the project and `members` the users on it, and a
 * humaniq `TimeEntry.projectId` names such a project. The reads here are system
 * reads because planninq lets only members read a project and the salary is
 * hidden from the manager; nothing read here leaves this class except the
 * employee and contract handed to the cost-rate service.
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
 */
class CostRateAccess {

	/**
	 * The app, register and schema that own projects.
	 */
	public const PLANNINQ_APP_ID = 'planninq';
	public const PLANNINQ_REGISTER = 'planninq';
	public const PLANNINQ_SCHEMA = 'project';

	/**
	 * Upper bound on the projects one manager owns that are read.
	 */
	private const PROJECT_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppManager          $appManager Whether planninq is installed.
	 * @param ContainerInterface   $container  Resolves OpenRegister's ObjectService.
	 * @param HoursRegisterGateway $gateway    System reads of humaniq objects.
	 * @param SettingsService      $settings   Register slug and OpenRegister availability.
	 * @param IUserSession         $userSession The caller.
	 * @param LoggerInterface      $logger     Logger.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly HoursRegisterGateway $gateway,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look the employee up under the caller's ambient RBAC.
	 *
	 * NAMED AS A LOOKUP, NOT A GUARD, BECAUSE THAT IS WHAT IT IS. This method
	 * makes no authorization decision — `ObjectService` does, by resolving (or
	 * refusing to resolve) the id under the caller's own RBAC. Calling it
	 * `authorizeEmployee` claimed a decision it does not make, and gate-8
	 * flagged the resulting `catch (\Throwable) { return null; }` in an
	 * auth-named method as a possible fail-open resolver.
	 *
	 * That gate is right to be suspicious of the shape. The defect it exists
	 * for is decidesk's `getAuthorizationService()`, which returned null on
	 * Throwable while its caller wrote `if ($auth !== null) { check }` — so an
	 * unavailable service silently meant NO CHECK.
	 *
	 * ⚠️ THE CONTRACT HERE IS THE OPPOSITE, AND CALLERS MUST KEEP IT THAT WAY:
	 * null means DENY. The only caller answers 404 on null, before any
	 * salary-derived figure is produced. A future caller that treats null as
	 * "skip the lookup and carry on" would turn this into the very fail-open
	 * the gate is named after.
	 *
	 * @param string $employeeId The Employee object id.
	 *
	 * @return array<string, mixed>|null The employee, or null when absent OR unauthorised — the two are deliberately indistinguishable.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	public function employeeForCaller(string $employeeId): ?array {
		try {
			$employee = $this->objectService()->find(
				id: $employeeId,
				register: $this->settings->getRegisterSlug(),
				schema: 'Employee'
			);
		} catch (\Throwable $e) {
			$this->logger->info('humaniq cost rate: employee ' . $employeeId . ' not retrievable: ' . $e->getMessage());
			return null;
		}

		if ($employee === null) {
			return null;
		}

		return $this->toArray(row: $employee);
	}//end employeeForCaller()

	/**
	 * Find the employee's EmploymentContract that runs in the period, under the
	 * caller's RBAC, if any.
	 *
	 * Returns null rather than throwing when none is found: the service then
	 * falls back to a reasoned override, and answers null itself if there is
	 * no wage base at all. Resolving the contract HERE rather than letting the
	 * service pick one is deliberate — the service's own docblock notes that
	 * taking the contract from the caller stops it silently costing against a
	 * different contract than the caller believes it is using.
	 *
	 * The lookup uses OpenRegister's real `findAll(array $config, ...)`
	 * signature. It used to pass named `register`/`schema`/`filters`
	 * arguments that method does not have, and filtered on `employee` and
	 * `status`, which the contract schema does not carry: the error was caught
	 * and no contract-derived rate was ever produced.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param string $period Costing period `YYYY-MM`.
	 *
	 * @return array<string, mixed>|null The contract, or null.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	public function contractForCaller(array $employee, string $period): ?array {
		$employeeId = (string)($employee['id'] ?? '');
		if ($employeeId === '') {
			return null;
		}

		try {
			$found = $this->objectService()
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('EmploymentContract')
				->findAll(['limit' => 100, 'filters' => ['employeeId' => $employeeId]]);
		} catch (\Throwable $e) {
			$this->logger->info('humaniq cost rate: no contract for ' . $employeeId . ': ' . $e->getMessage());
			return null;
		}

		$rows = array_map(fn (mixed $row): array => $this->toArray(row: $row), (is_array($found) === true ? $found : []));

		return $this->pickActive(rows: array_values($rows), employeeId: $employeeId, period: $period);
	}//end contractForCaller()

	/**
	 * The OpenRegister ObjectService, under the caller's ambient RBAC.
	 *
	 * @return mixed The ObjectService.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settings->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * The employee, read without the caller's field access, when the signed-in
	 * caller runs a project the employee works on; otherwise null.
	 *
	 * @param string $employeeId The Employee object id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
	 */
	public function employeeManagedByCaller(string $employeeId): ?array {
		return $this->employeeManagedBy(
			uid: (string)($this->userSession->getUser()?->getUID() ?? ''),
			employeeId: $employeeId
		);
	}//end employeeManagedByCaller()

	/**
	 * The employee, read without the caller's field access, when the caller
	 * runs a project the employee works on; otherwise null.
	 *
	 * @param string $uid        The caller's user id.
	 * @param string $employeeId The Employee object id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
	 */
	public function employeeManagedBy(string $uid, string $employeeId): ?array {
		if (trim($uid) === '' || trim($employeeId) === '') {
			return null;
		}

		$projects = $this->projectsOwnedBy(uid: $uid);
		if ($projects === []) {
			return null;
		}

		$employee = $this->gateway->findObjectData($employeeId, 'Employee');
		if ($employee === null) {
			return null;
		}

		if ($this->worksOn(employee: $employee, employeeId: $employeeId, projects: $projects) === true) {
			return $employee;
		}

		return null;
	}//end employeeManagedBy()

	/**
	 * Whether the employee works on one of the projects: their user is a
	 * member, or one of their time entries names the project.
	 *
	 * @param array<string, mixed>             $employee   The employee.
	 * @param string                           $employeeId The Employee object id.
	 * @param array<int, array<string, mixed>> $projects   The projects.
	 *
	 * @return bool
	 */
	private function worksOn(array $employee, string $employeeId, array $projects): bool {
		$employeeUid = trim((string)($employee['nextcloudUserId'] ?? ''));
		$projectIds = [];
		foreach ($projects as $project) {
			if ($employeeUid !== '' && in_array($employeeUid, (array)($project['members'] ?? []), true) === true) {
				return true;
			}

			$projectIds[] = (string)($project['id'] ?? ($project['@self']['id'] ?? ''));
		}

		$projectIds = array_filter($projectIds);
		foreach ($this->gateway->findFiltered('TimeEntry', ['employeeId' => $employeeId]) as $entry) {
			if (in_array((string)($entry['projectId'] ?? ''), $projectIds, true) === true) {
				return true;
			}
		}

		return false;
	}//end worksOn()

	/**
	 * The employee's contract for the period, read without the caller's field access.
	 *
	 * @param string $employeeId The Employee object id.
	 * @param string $period     The costing period, `YYYY-MM`.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
	 */
	public function contractFor(string $employeeId, string $period): ?array {
		return $this->pickActive(
			rows: $this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]),
			employeeId: $employeeId,
			period: $period
		);
	}//end contractFor()

	/**
	 * The contract of this employee that runs during the period: it starts on or
	 * before the period's last day and has not ended before its first day. The
	 * latest start wins when two overlap.
	 *
	 * @param array<int, array<string, mixed>> $rows       Candidate contracts.
	 * @param string                           $employeeId The Employee object id.
	 * @param string                           $period     The costing period, `YYYY-MM`.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	public function pickActive(array $rows, string $employeeId, string $period): ?array {
		$first = $period . '-01';
		$last = date('Y-m-t', (int)strtotime($first));
		$picked = null;
		foreach ($rows as $row) {
			$start = substr(trim((string)($row['startDate'] ?? '')), 0, 10);
			$end = substr(trim((string)($row['endDate'] ?? '')), 0, 10);
			$runs = (string)($row['employeeId'] ?? '') === $employeeId
				&& ($start === '' || $start <= $last)
				&& ($end === '' || $end >= $first);
			if ($runs === true && ($picked === null || $start > (string)($picked['startDate'] ?? ''))) {
				$picked = $row;
			}
		}

		return $picked;
	}//end pickActive()

	/**
	 * The planninq projects this user owns; none when planninq is absent.
	 *
	 * @param string $uid The user id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function projectsOwnedBy(string $uid): array {
		if ($this->appManager->isInstalled(self::PLANNINQ_APP_ID) === false) {
			return [];
		}

		try {
			$rows = $this->container->get('OCA\OpenRegister\Service\ObjectService')
				->setRegister(self::PLANNINQ_REGISTER)
				->setSchema(self::PLANNINQ_SCHEMA)
				->findAll(['limit' => self::PROJECT_LIMIT, 'filters' => ['owner' => $uid]], false, false);
		} catch (\Throwable $e) {
			$this->logger->info('humaniq: could not read planninq projects: ' . $e->getMessage());
			return [];
		}

		$owned = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$data = $this->toArray(row: $row);
			if ((string)($data['owner'] ?? '') === $uid) {
				$owned[] = $data;
			}
		}

		return $owned;
	}//end projectsOwnedBy()

	/**
	 * Normalise an ObjectService row to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end toArray()
}//end class
