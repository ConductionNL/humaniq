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
	 * @param LoggerInterface      $logger     Logger.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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

		$employeeUid = trim((string)($employee['nextcloudUserId'] ?? ''));
		foreach ($projects as $project) {
			if ($employeeUid !== '' && in_array($employeeUid, (array)($project['members'] ?? []), true) === true) {
				return $employee;
			}
		}

		$projectIds = array_filter(array_map(fn (array $p): string => (string)($p['id'] ?? ($p['@self']['id'] ?? '')), $projects));
		foreach ($this->gateway->findFiltered('TimeEntry', ['employeeId' => $employeeId]) as $entry) {
			if (in_array((string)($entry['projectId'] ?? ''), $projectIds, true) === true) {
				return $employee;
			}
		}

		return null;
	}//end employeeManagedBy()

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
