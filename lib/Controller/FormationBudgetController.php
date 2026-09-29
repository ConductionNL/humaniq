<?php

/**
 * Humaniq FormationBudgetController
 *
 * Next year's personnel budget of a formation scenario, and the comparison of
 * two scenarios with the baseline (reporting-personnel-budget-and-scenarios
 * D3, D4). Salary figures are for HR and accountants only: a caller without
 * the hr or accountant role in the active administration is refused, and a
 * scenario of another administration is not found.
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
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\PersonnelBudgetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the personnel budget.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */
class FormationBudgetController extends Controller {

	/**
	 * The rows the budget reads, by the key PersonnelBudgetService takes.
	 */
	private const SOURCES = [
		'places' => 'Formatieplaats',
		'contracts' => 'EmploymentContract',
		'employees' => 'Employee',
		'normfuncties' => 'Normfunctie',
		'orgUnits' => 'OrgUnit',
		'compAdjustments' => 'CompAdjustment',
		'mutations' => 'ScenarioMutation',
		'runs' => 'PayrollRun',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param HoursRegisterGateway $gateway Register reads, past RBAC: the budget needs every salary.
	 * @param PersonnelBudgetService $budget The budget arithmetic.
	 * @param AnalyticsAccess $access Whether the caller is HR or an accountant, and where.
	 * @param IUserSession $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly PersonnelBudgetService $budget,
		private readonly AnalyticsAccess $access,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/formation/budget?scenarioId&year: one scenario's budget.
	 *
	 * @param string $scenarioId The scenario.
	 * @param int|null $year The budget year (default the scenario's).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
	 */
	#[NoAdminRequired]
	public function budget(string $scenarioId, ?int $year = null): JSONResponse {
		$administrationId = $this->readerAdministration();
		if ($administrationId === null) {
			return new JSONResponse(['message' => 'Only HR and accountants can read the personnel budget.'], Http::STATUS_FORBIDDEN);
		}

		$scenario = $this->scenario(id: $scenarioId, administrationId: $administrationId);
		if ($scenario === null) {
			return new JSONResponse(['message' => 'Scenario not found.'], Http::STATUS_NOT_FOUND);
		}

		$budget = $this->budget->budget(scenario: $scenario, rows: $this->rows(administrationId: $administrationId), year: ($year ?? (int)($scenario['year'] ?? 0)));

		return new JSONResponse(array_merge(['scenarioName' => (string)($scenario['name'] ?? '')], $budget));
	}//end budget()

	/**
	 * GET /api/formation/compare?a&b&year: two scenarios and the baseline, per unit.
	 *
	 * @param string $a The first scenario.
	 * @param string $b The second scenario.
	 * @param int|null $year The budget year (default the first scenario's).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
	 */
	#[NoAdminRequired]
	public function compare(string $a, string $b, ?int $year = null): JSONResponse {
		$administrationId = $this->readerAdministration();
		if ($administrationId === null) {
			return new JSONResponse(['message' => 'Only HR and accountants can read the personnel budget.'], Http::STATUS_FORBIDDEN);
		}

		$first = $this->scenario(id: $a, administrationId: $administrationId);
		$second = $this->scenario(id: $b, administrationId: $administrationId);
		if ($first === null || $second === null) {
			return new JSONResponse(['message' => 'Scenario not found.'], Http::STATUS_NOT_FOUND);
		}

		$compare = $this->budget->compare(a: $first, b: $second, rows: $this->rows(administrationId: $administrationId), year: ($year ?? (int)($first['year'] ?? 0)));
		$compare['unitRows'] = array_map(
			static fn (array $unit): array => [
				'id' => $unit['orgUnitId'],
				'name' => $unit['name'],
				'baselineCost' => $unit['baseline']['cost'],
				'aCost' => $unit['a']['cost'],
				'bCost' => $unit['b']['cost'],
				'diffACost' => $unit['diffA']['cost'],
				'diffBCost' => $unit['diffB']['cost'],
				'baselineFteDecember' => ($unit['baseline']['fteByMonth'][11] ?? 0.0),
				'aFteDecember' => ($unit['a']['fteByMonth'][11] ?? 0.0),
				'bFteDecember' => ($unit['b']['fteByMonth'][11] ?? 0.0),
			],
			array_values($compare['units'])
		);

		return new JSONResponse($compare);
	}//end compare()

	/**
	 * The caller's administration when they hold the hr or accountant role there.
	 *
	 * @return string|null
	 */
	private function readerAdministration(): ?string {
		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId === '') {
			return null;
		}

		return $this->access->fullReaderAdministration($userId);
	}//end readerAdministration()

	/**
	 * A scenario of the administration, with its id.
	 *
	 * @param string $id The scenario id.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null
	 */
	private function scenario(string $id, string $administrationId): ?array {
		foreach ($this->inAdministration(schema: 'FormationScenario', administrationId: $administrationId) as $scenario) {
			if ((string)($scenario['id'] ?? '') === $id) {
				return $scenario;
			}
		}

		return null;
	}//end scenario()

	/**
	 * Every source the budget reads, limited to the administration.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function rows(string $administrationId): array {
		$rows = [];
		foreach (self::SOURCES as $key => $schema) {
			$rows[$key] = $this->inAdministration(schema: $schema, administrationId: $administrationId);
		}

		return $rows;
	}//end rows()

	/**
	 * The rows of a schema that belong to the administration or to none.
	 *
	 * @param string $schema The schema.
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function inAdministration(string $schema, string $administrationId): array {
		return array_values(
			array_filter(
				$this->gateway->loadAll($schema),
				static fn (array $row): bool => in_array((string)($row['administrationId'] ?? ''), ['', $administrationId], true)
			)
		);
	}//end inAdministration()

}//end class
