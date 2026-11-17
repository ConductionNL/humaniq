<?php

/**
 * Attendance clock controller
 *
 * `POST /api/attendance/clock-in` and `POST /api/attendance/clock-out`: the
 * signed-in employee opens and closes their own clock day from
 * `My attendance` (REQ-TA-008). Who clocks is the caller, never a parameter.
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AttendanceClockService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Clock in and clock out for the caller.
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
 */
class AttendanceClockController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     The request.
	 * @param AttendanceClockService $clock       Opens and closes the clock day.
	 * @param IUserSession           $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly AttendanceClockService $clock,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `POST /api/attendance/clock-in`: open a clock day for the caller now.
	 *
	 * @return JSONResponse 201 {record}, 401 when signed out, 404 without a linked employee, 409 when a day is already open.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
	 */
	#[NoAdminRequired]
	public function clockIn(): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return new JSONResponse(['error' => 'Sign in to clock in.'], Http::STATUS_UNAUTHORIZED);
		}

		$answer = $this->clock->clockIn(uid: $uid);

		return new JSONResponse($answer['body'], $answer['status']);
	}//end clockIn()

	/**
	 * `POST /api/attendance/clock-out`: close the caller's open clock day now.
	 *
	 * @param int|null $breakMinutes The break taken, or null to keep the record's.
	 *
	 * @return JSONResponse 200 {record}, 400 for a negative break, 401 when signed out, 409 when no day is open.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
	 */
	#[NoAdminRequired]
	public function clockOut(?int $breakMinutes = null): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return new JSONResponse(['error' => 'Sign in to clock out.'], Http::STATUS_UNAUTHORIZED);
		}

		$answer = $this->clock->clockOut(uid: $uid, breakMinutes: $breakMinutes);

		return new JSONResponse($answer['body'], $answer['status']);
	}//end clockOut()
}//end class
