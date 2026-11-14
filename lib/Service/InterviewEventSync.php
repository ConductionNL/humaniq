<?php

/**
 * Interview Event Sync
 *
 * One Interview being synced to the calendar inside InterviewSyncEngine: the
 * Interview and its id, its compact start and end once validated, and the
 * prepared event once rendered.
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
 *
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The calendar sync of one Interview.
 *
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */
final class InterviewEventSync {

	/**
	 * The Interview object (pre-write).
	 *
	 * @var array<string, mixed>
	 */
	public array $interview = [];

	/**
	 * The Interview id.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The compact DTSTART, once validated.
	 *
	 * @var string|null
	 */
	public ?string $dtstart = null;

	/**
	 * The compact DTEND, once validated.
	 *
	 * @var string|null
	 */
	public ?string $dtend = null;

	/**
	 * The rendered event and its UID identity, once prepared.
	 *
	 * @var PreparedInterviewEvent|null
	 */
	public ?PreparedInterviewEvent $prepared = null;

	/**
	 * The stored event's text, read before an update; null when there is none or it could not be read.
	 *
	 * @var string|null
	 */
	public ?string $storedIcs = null;
}//end class
