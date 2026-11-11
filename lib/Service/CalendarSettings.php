<?php

/**
 * Humaniq Calendar Settings
 *
 * The shared calendars the one-way leave and interview syncs project their
 * events onto: the owning CalDAV principal and the calendar URI of each.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads the leave and interview sync target calendars from app config.
 *
 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-001
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-002
 */
final class CalendarSettings {

	/**
	 * @param IAppConfig $appConfig The app config interface.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The CalDAV principal that owns the target shared calendar
	 * (leave-calendar-nc design.md D6), e.g. `principals/users/hr`,
	 * configurable via app config key `leave_calendar_principal`. Empty
	 * (the default) means the sync is not configured and every run ends
	 * `skipped-no-calendar`. The configured calendar SHOULD be one the
	 * owning account shares with the team — the sync is a one-way
	 * projection, so events manually edited on the calendar are overwritten
	 * by the next `occ humaniq:calendar:sync`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-001
	 */
	public function getLeaveCalendarPrincipal(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'leave_calendar_principal', '');
	}//end getLeaveCalendarPrincipal()

	/**
	 * The CalDAV calendar URI (on the principal above) the sync writes
	 * approved-leave/sickness events into (leave-calendar-nc design.md D6),
	 * as shown in the Calendar app's link/edit dialog, configurable via app
	 * config key `leave_calendar_uri`. Empty (the default) means the sync is
	 * not configured and every run ends `skipped-no-calendar`. One-way
	 * projection caveat: manual edits to events on this calendar are
	 * overwritten by the next sync.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-001
	 */
	public function getLeaveCalendarUri(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'leave_calendar_uri', '');
	}//end getLeaveCalendarUri()

	/**
	 * The CalDAV principal that owns the target shared calendar interview
	 * events are projected onto (interview-scheduling design.md D6), e.g.
	 * `principals/users/recruiting`, configurable via app config key
	 * `interview_calendar_principal`. Empty (the default) means the sync is
	 * not configured and every run ends `skipped-no-calendar`. Deliberately
	 * a SEPARATE key from `leave_calendar_principal` — an org may want
	 * interviews on the recruiting team's own shared calendar, distinct from
	 * the company-wide absence calendar. The configured calendar SHOULD be
	 * one the owning account shares with the interview panel — the sync is
	 * a one-way projection, so events manually edited on the calendar are
	 * overwritten by the next `occ humaniq:interview:sync`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-002
	 */
	public function getInterviewCalendarPrincipal(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'interview_calendar_principal', '');
	}//end getInterviewCalendarPrincipal()

	/**
	 * The CalDAV calendar URI (on the principal above) the interview sync
	 * writes VEVENTs into (interview-scheduling design.md D6), as shown in
	 * the Calendar app's link/edit dialog, configurable via app config key
	 * `interview_calendar_uri`. Empty (the default) means the sync is not
	 * configured and every run ends `skipped-no-calendar`. Deliberately a
	 * SEPARATE key from `leave_calendar_uri` (design.md D6). One-way
	 * projection caveat: manual edits to events on this calendar are
	 * overwritten by the next sync.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-002
	 */
	public function getInterviewCalendarUri(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'interview_calendar_uri', '');
	}//end getInterviewCalendarUri()

}//end class
