<?php

/**
 * Humaniq SurveyOpening
 *
 * The state of opening one survey: the survey, the day and the counts of
 * invited employees and employees without an account.
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
 * One survey being opened.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */
final class SurveyOpening {

	/**
	 * The survey id.
	 *
	 * @var string
	 */
	public string $surveyId = '';

	/**
	 * The stored survey.
	 *
	 * @var array<string, mixed>
	 */
	public array $survey = [];

	/**
	 * The day, `YYYY-MM-DD`.
	 *
	 * @var string
	 */
	public string $today = '';

	/**
	 * Employees invited so far.
	 *
	 * @var int
	 */
	public int $invited = 0;

	/**
	 * Employees in scope without a Nextcloud account.
	 *
	 * @var int
	 */
	public int $withoutAccount = 0;
}//end class
