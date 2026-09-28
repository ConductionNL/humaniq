<?php

/**
 * Employee History Controller
 *
 * people-employment-history: `GET /api/employees/{id}/history` and
 * `GET /api/employees/{id}/employments`. The employee must resolve under the
 * caller's own RBAC first; unknown and unreadable both answer 404 and carry
 * no event (design.md D2). Every source row is then kept only when the
 * caller may read it, so a scoped manager sees in the history exactly what
 * they already see on the lists.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
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
 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AbsenceRateService;
use OCA\Humaniq\Service\EmployeeHistoryService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads one employee's history and concurrent employments.
 */
class EmployeeHistoryController extends Controller {

	/**
	 * @param IRequest               $request The request.
	 * @param HoursRegisterGateway   $gateway Loads the employee's rows per schema.
	 * @param EmployeeHistoryService $history Maps and orders the rows.
	 * @param RbacObjectReader       $rbac    Reads under the caller's own RBAC.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly EmployeeHistoryService $history,
		private readonly RbacObjectReader $rbac,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/employees/{id}/history`, newest first.
	 *
	 * @param string $id The employee.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
	 */
	#[NoAdminRequired]
	public function history(string $id): JSONResponse {
		if ($this->rbac->findOrNull(id: $id, schema: 'Employee') === null) {
			return $this->notFound();
		}

		$rows = [];
		foreach (array_keys(EmployeeHistoryService::SOURCES) as $schema) {
			$rows[$schema] = $this->readable(schema: $schema, employeeId: $id);
		}

		return new JSONResponse(['employeeId' => $id, 'events' => $this->history->historyFor(employeeId: $id, rowsBySchema: $rows)]);
	}//end history()

	/**
	 * `GET /api/employees/{id}/employments?date=YYYY-MM-DD` (default today),
	 * with the year's leave balances shown once.
	 *
	 * @param string      $id   The employee.
	 * @param string|null $date The day.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-003
	 */
	#[NoAdminRequired]
	public function employments(string $id, ?string $date = null): JSONResponse {
		if ($this->rbac->findOrNull(id: $id, schema: 'Employee') === null) {
			return $this->notFound();
		}

		try {
			$day = new DateTimeImmutable(trim((string)$date) === '' ? 'today' : (string)$date);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'date moet een geldige datum zijn.'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->history->activeEmploymentsOn(
			employeeId: $id,
			contracts: $this->readable(schema: 'EmploymentContract', employeeId: $id),
			date: $day,
			fullTimeHoursWeek: AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK
		);
		$year = (int)$day->format('Y');
		$balances = array_values(
			array_filter(
				$this->readable(schema: 'LeaveBalance', employeeId: $id),
				static fn (array $row): bool => (int)($row['year'] ?? 0) === $year
			)
		);

		return new JSONResponse(
			array_merge(
				$result,
				[
					'fullTimeHoursPerWeek' => AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK,
					'normSource' => 'AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK',
					'leaveBalances' => $balances,
				]
			)
		);
	}//end employments()

	/**
	 * The employee's rows of one schema the caller may read.
	 *
	 * @param string $schema     The schema.
	 * @param string $employeeId The employee.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function readable(string $schema, string $employeeId): array {
		$out = [];
		foreach ($this->gateway->findFiltered($schema, ['employeeId' => $employeeId]) as $row) {
			$rowId = (string)($row['id'] ?? '');
			if ($rowId !== '' && $this->rbac->findOrNull(id: $rowId, schema: $schema) !== null) {
				$out[] = $row;
			}
		}

		return $out;
	}//end readable()

	/**
	 * The 404 that unknown and unreadable share.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Medewerker niet gevonden.'], Http::STATUS_NOT_FOUND);
	}//end notFound()

}//end class
