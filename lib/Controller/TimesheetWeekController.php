<?php

/**
 * Humaniq TimesheetWeekController
 *
 * `GET /api/timesheets/week?id=<timesheet>`: the week view of one timesheet,
 * the booked entries beside read-only leave and sickness lines, with the
 * totals per kind and the contract hours (time-non-project-activities D2, D3).
 * The view is composed with system reads, so the caller is first required to
 * be able to read the timesheet itself; a timesheet the caller may not see
 * answers 404, the same as one that does not exist.
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
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\TimesheetWeekService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Serves the week view of a timesheet the caller may read.
 *
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */
class TimesheetWeekController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request     The request object.
	 * @param IUserSession         $userSession The calling user.
	 * @param RecordStoreInterface $store       The register reads.
	 * @param TimesheetWeekService $weeks       Composes the view.
	 * @param LoggerInterface      $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly RecordStoreInterface $store,
		private readonly TimesheetWeekService $weeks,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);

	}//end __construct()

	/**
	 * The week view of one timesheet.
	 *
	 * @param string|null $id The timesheet uuid.
	 *
	 * @return JSONResponse The view; 400 without an id, 401 without a session,
	 *                      404 for a timesheet that is gone or not the caller's to read.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	#[NoAdminRequired]
	public function week(?string $id = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || trim((string)$user->getUID()) === '') {
			return new JSONResponse(['error' => 'Log in om de urenstaat te bekijken.'], Http::STATUS_UNAUTHORIZED);
		}

		$id = trim((string)$id);
		if ($id === '') {
			return new JSONResponse(['error' => 'Geef de urenstaat op.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$view = ($this->callerMayRead(id: $id) === true) ? $this->weeks->weekFor($id) : null;
		} catch (\Throwable $e) {
			$this->logger->error('TimesheetWeekController: the week view could not be composed.', ['exceptionClass' => $e::class]);

			return new JSONResponse(['error' => 'De urenstaat kon niet worden gelezen.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($view === null) {
			return new JSONResponse(['error' => 'Deze urenstaat bestaat niet of u mag hem niet zien.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($view);
	}//end week()

	/**
	 * Whether the caller's own access rules return this timesheet.
	 *
	 * @param string $id The timesheet uuid.
	 *
	 * @return bool
	 */
	private function callerMayRead(string $id): bool {
		$stored = $this->store->findObjectData($id, 'Timesheet');
		if ($stored === null) {
			return false;
		}

		$filters = [
			'employeeId' => trim((string)($stored['employeeId'] ?? '')),
			'period' => trim((string)($stored['period'] ?? '')),
		];
		foreach ($this->store->findFiltered('Timesheet', $filters, true) as $row) {
			if (trim((string)($row['id'] ?? ($row['@self']['id'] ?? ''))) === $id) {
				return true;
			}
		}

		return false;
	}//end callerMayRead()

}//end class
