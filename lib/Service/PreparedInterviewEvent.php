<?php

/**
 * Prepared Interview Event
 *
 * The rendered-and-identified state of one Interview's VEVENT (design.md
 * D3), bundled so `InterviewSyncEngine`'s upsert/update methods take one
 * parameter instead of eight.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

/**
 * Immutable bundle of one Interview's rendered ICS + the UID identity it was rendered under.
 */
final class PreparedInterviewEvent
{


    /**
     * @param string $storedUid The Interview's PRE-EXISTING `calendarEventUid` ('' when never synced).
     * @param string $uid       The UID this render used (either `$storedUid` or a freshly-derived one).
     * @param string $objectUri The deterministic object URI (`{uid}.ics`).
     * @param string $ics       The rendered RFC 5545 VCALENDAR/VEVENT text.
     * @param string $dtstart   Compact UTC DATE-TIME (YYYYMMDDTHHMMSSZ), for diffing.
     * @param string $dtend     Compact UTC DATE-TIME (YYYYMMDDTHHMMSSZ), for diffing.
     * @param string $summary   The AVG-safe SUMMARY text (unescaped), for diffing.
     * @param string $location  The LOCATION text (unescaped, '' means none), for diffing.
     */
    public function __construct(
        public readonly string $storedUid,
        public readonly string $uid,
        public readonly string $objectUri,
        public readonly string $ics,
        public readonly string $dtstart,
        public readonly string $dtend,
        public readonly string $summary,
        public readonly string $location,
    ) {

    }//end __construct()


}//end class
