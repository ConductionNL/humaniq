<?php

/**
 * Formation Controller
 *
 * people-formation-positions: `GET /api/formation/occupancy`, the budgeted,
 * filled, vacant and net FTE of one unit's formation places, or of one place,
 * computed on read (design.md D3, D5). The unit or place must resolve under
 * the caller's own RBAC first; unknown and unreadable both answer 404, so
 * existence never leaks. The answer holds FTE figures only, never who fills
 * a place or why they are away.
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
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AbsenceRateService;
use OCA\Humaniq\Service\FormationOccupancyService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads formation occupancy for a unit or a place.
 */
class FormationController extends Controller {

	/**
	 * @param IRequest                  $request   The request.
	 * @param HoursRegisterGateway      $gateway   Loads the records the count needs.
	 * @param FormationOccupancyService $occupancy The pure count.
	 * @param RbacObjectReader          $rbac      Resolves the unit or place under the caller's RBAC.
	 * @param SettingsService           $settings  The net-FTE leave types and sickness threshold.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly FormationOccupancyService $occupancy,
		private readonly RbacObjectReader $rbac,
		private readonly SettingsService $settings,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/formation/occupancy?orgUnitId|formatieplaatsId&from&to`.
	 *
	 * @param string|null $orgUnitId        The unit whose places to count.
	 * @param string|null $formatieplaatsId One place to count instead.
	 * @param string|null $from             First day (ISO date), default today.
	 * @param string|null $to               Last day (ISO date), default `from`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
	 */
	#[NoAdminRequired]
	public function occupancy(
		?string $orgUnitId = null,
		?string $formatieplaatsId = null,
		?string $from = null,
		?string $to = null,
	): JSONResponse {
		$window = $this->window(from: $from, to: $to);
		if ($window === null) {
			return new JSONResponse(['error' => 'from en to moeten geldige datums zijn, met to op of na from.'], Http::STATUS_BAD_REQUEST);
		}

		$places = $this->places(orgUnitId: trim((string)$orgUnitId), placeId: trim((string)$formatieplaatsId));
		if ($places === null) {
			return new JSONResponse(['error' => 'Eenheid of formatieplaats niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$weeks = $this->settings->getFormationLongTermSickWeeks();
		$result = $this->occupancy->occupancy(
			places: $places,
			contracts: $this->gateway->loadAll('EmploymentContract'),
			leaveRequests: $this->gateway->loadAll('LeaveRequest'),
			sickCases: $this->gateway->loadAll('SickLeaveCase'),
			from: $window[0],
			to: $window[1],
			netLeaveTypes: $this->settings->getFormationNetFteLeaveTypes(),
			longTermSickWeeks: $weeks,
			fullTimeHoursWeek: AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK
		);

		return new JSONResponse(
			array_merge(
				$result,
				['from' => $window[0]->format('Y-m-d'), 'to' => $window[1]->format('Y-m-d'), 'longTermSickWeeks' => $weeks]
			)
		);
	}//end occupancy()

	/**
	 * The places to count, or null when the unit or place does not resolve
	 * for this caller. A retired place (`opgeheven`) is left out of a unit.
	 *
	 * @param string $orgUnitId The unit id, or ''.
	 * @param string $placeId   The place id, or ''.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function places(string $orgUnitId, string $placeId): ?array {
		if ($placeId !== '') {
			$place = $this->rbac->findOrNull(id: $placeId, schema: 'Formatieplaats');
			return ($place === null) ? null : [$place];
		}

		if ($orgUnitId === '' || $this->rbac->findOrNull(id: $orgUnitId, schema: 'OrgUnit') === null) {
			return null;
		}

		$places = [];
		foreach ($this->gateway->findFiltered('Formatieplaats', ['orgUnitId' => $orgUnitId]) as $place) {
			if (($place['status'] ?? 'actief') !== 'opgeheven') {
				$places[] = $place;
			}
		}

		return $places;
	}//end places()

	/**
	 * Parse the window, defaulting to today.
	 *
	 * @param string|null $from First day.
	 * @param string|null $to   Last day.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
	 */
	private function window(?string $from, ?string $to): ?array {
		try {
			$start = (new DateTimeImmutable(trim((string)$from) === '' ? 'today' : (string)$from))->setTime(0, 0);
			$end = (trim((string)$to) === '') ? $start : (new DateTimeImmutable((string)$to))->setTime(0, 0);
		} catch (\Exception $e) {
			return null;
		}

		return ($end < $start) ? null : [$start, $end];
	}//end window()

}//end class
