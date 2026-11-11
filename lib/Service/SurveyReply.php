<?php

/**
 * Humaniq SurveyReply
 *
 * One caller answering one survey: the survey, the caller's employee
 * record and invitation, and the day.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One answer to a survey as it is checked and stored.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
 */
final class SurveyReply {

	/**
	 * The survey id.
	 *
	 * @var string
	 */
	public string $surveyId = '';

	/**
	 * The day, `YYYY-MM-DD`.
	 *
	 * @var string
	 */
	public string $today = '';

	/**
	 * The stored survey, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $survey = null;

	/**
	 * The caller's employee record, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $employee = null;

	/**
	 * The caller's invitation, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $invitation = null;
}//end class
