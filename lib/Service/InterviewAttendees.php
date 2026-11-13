<?php

/**
 * Humaniq InterviewAttendees: the organiser and attendees of an interview's calendar event.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IUserManager;

/**
 * Turns an Interview's interviewer user ids into calendar addresses.
 *
 * Only Nextcloud users are ever named: the candidate has no account in the
 * list and the Application's personal fields are never read here, so the
 * candidate cannot become an attendee (REQ-INTV-005). An interviewer without
 * an email address is still named in the description but cannot be invited.
 *
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
 */
class InterviewAttendees {

	/**
	 * The principal prefix of a Nextcloud user.
	 *
	 * @var string
	 */
	private const USER_PRINCIPAL = 'principals/users/';

	/**
	 * Constructor.
	 *
	 * @param IUserManager $users The Nextcloud users.
	 */
	public function __construct(
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * The user ids an Interview names, trimmed and once each.
	 *
	 * @param mixed $interviewers The stored value: a list of user ids.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
	 */
	public static function ids(mixed $interviewers): array {
		if (is_array($interviewers) === false) {
			return [];
		}

		$ids = [];
		foreach ($interviewers as $id) {
			$id = (is_scalar($id) === true) ? trim((string)$id) : '';
			if ($id !== '' && in_array($id, $ids, true) === false) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end ids()

	/**
	 * The ids among them that are not Nextcloud users.
	 *
	 * @param string[] $ids The user ids.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
	 */
	public function unknown(array $ids): array {
		return array_values(array_filter($ids, fn (string $id): bool => $this->users->userExists($id) === false));
	}//end unknown()

	/**
	 * The event's organiser, attendees and description line.
	 *
	 * @param mixed  $interviewers The Interview's stored interviewers.
	 * @param string $principal    The interview calendar's principal, e.g. principals/users/hr-agenda.
	 *
	 * @return array{organizer: string, attendees: array<int, array{name: string, email: string}>, description: string}
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
	 */
	public function forEvent(mixed $interviewers, string $principal): array {
		if (is_string($interviewers) === true) {
			// A value stored before interviewers became user ids: names only.
			return ['organizer' => '', 'attendees' => [], 'description' => trim($interviewers)];
		}

		$attendees = [];
		$names = [];
		foreach (self::ids(interviewers: $interviewers) as $id) {
			$user = $this->users->get($id);
			$name = (($user?->getDisplayName() ?? '') !== '') ? (string)$user?->getDisplayName() : $id;
			$names[] = $name;
			$email = trim((string)($user?->getEMailAddress() ?? ''));
			if ($email !== '') {
				$attendees[] = ['name' => $name, 'email' => $email];
			}
		}

		return ['organizer' => $this->organizer(principal: $principal), 'attendees' => $attendees, 'description' => implode(', ', $names)];
	}//end forEvent()

	/**
	 * The calendar address of the interview calendar's owner, or '' when it has none.
	 *
	 * @param string $principal The calendar principal.
	 *
	 * @return string A mailto: address, or ''.
	 */
	private function organizer(string $principal): string {
		if (str_starts_with($principal, self::USER_PRINCIPAL) === false) {
			return '';
		}

		$email = trim((string)($this->users->get(substr($principal, strlen(self::USER_PRINCIPAL)))?->getEMailAddress() ?? ''));

		return ($email === '') ? '' : 'mailto:'.$email;
	}//end organizer()
}//end class
