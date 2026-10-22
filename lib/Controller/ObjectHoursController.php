<?php
/**
 * Humaniq ObjectHoursController
 *
 * The hours booked on one object in one week, per person, for the hours
 * surface a host app places on that object.
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
 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\ObjectWeekHours;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Answers a signed-in caller the week's hours on an object, per person.
 *
 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */
class ObjectHoursController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request object.
	 * @param IUserSession    $userSession The calling user.
	 * @param ObjectWeekHours $weekHours   The week's hours per person.
	 * @param LoggerInterface $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectWeekHours $weekHours,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/time-entries/week` — the week holding `date` (today without one) on one object.
	 *
	 * The session is checked before anything is read, and the entries are read
	 * as the caller, so a booking the caller may not see is not counted. A
	 * failed read answers 500, never a zero.
	 *
	 * @param string|null $domainObjectType The `<app>:<schema>` literal of the object.
	 * @param string|null $domainObjectRef  The object's uuid.
	 * @param string|null $date             Any day of the week, `YYYY-MM-DD`.
	 *
	 * @return JSONResponse The week, 400 on a missing object or a bad day, 401 without a session.
	 *
	 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
	 */
	#[NoAdminRequired]
	public function week(?string $domainObjectType = null, ?string $domainObjectRef = null, ?string $date = null): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Log in om de uren te bekijken.'], Http::STATUS_UNAUTHORIZED);
		}

		$domainObjectType = trim((string)$domainObjectType);
		$domainObjectRef  = trim((string)$domainObjectRef);
		if ($domainObjectType === '' || $domainObjectRef === '') {
			return new JSONResponse(['error' => 'domainObjectType en domainObjectRef zijn verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		$day = $this->dayOf(date: $date);
		if ($day === null) {
			return new JSONResponse(['error' => 'Geef de dag als JJJJ-MM-DD.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$week = $this->weekHours->forObject($domainObjectType, $domainObjectRef, $day);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ObjectHoursController: the week of ' . $domainObjectType . ' ' . $domainObjectRef . ' could not be read: ' . $e->getMessage()
			);

			return new JSONResponse(['error' => 'De uren konden niet worden gelezen.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($week);
	}//end week()

	/**
	 * The day asked for, today when none is given, or null when it is not a day.
	 *
	 * @param string|null $date The day, `YYYY-MM-DD`.
	 *
	 * @return DateTimeImmutable|null The day.
	 */
	private function dayOf(?string $date): ?DateTimeImmutable {
		$date = trim((string)$date);
		if ($date === '') {
			return new DateTimeImmutable('today');
		}

		$parts = [];
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1
			|| checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false
		) {
			return null;
		}

		return new DateTimeImmutable($date);
	}//end dayOf()
}//end class
