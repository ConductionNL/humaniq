<?php

/**
 * Survey Rules
 *
 * The rules a survey runs on besides its storage: whether a placement is
 * active on a day (who is invited), how answers are checked against the
 * questions, and how responses fold into results.
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
 * The placement, answer and result rules of {@see SurveyService}.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */
final class SurveyRules {

	/**
	 * @param OrgResolutionService $orgResolution Whether a placement is active on a day.
	 * @param SurveyResults $results The folded results.
	 * @param SurveyAnswers $answers Checks answers against the questions.
	 */
	public function __construct(
		public readonly OrgResolutionService $orgResolution,
		public readonly SurveyResults $results,
		public readonly SurveyAnswers $answers,
	) {

	}//end __construct()
}//end class
