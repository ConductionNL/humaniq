<?php

/**
 * Contract of SurveyService
 *
 * The public surface of SurveyService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of SurveyService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SurveyServiceInterface {
	/**
	 * Open a survey: move it to open and invite every active employee in
	 * scope with a Nextcloud account. Employees without one are counted.
	 *
	 * @param string $surveyId The survey.
	 * @param string $today    The day, `YYYY-MM-DD`.
	 *
	 * @return array{status: int, message: string|null, invited: int, withoutAccount: int}
	 *
	 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-001
	 */
	public function open(string $surveyId, string $today): array;

	/**
	 * Answer a survey as the caller: their open invitation is marked
	 * answered and the answers are stored without anything that names them.
	 *
	 * @param string               $surveyId The survey.
	 * @param string               $uid      The caller.
	 * @param array<string, mixed> $answers  The answers by question key.
	 * @param string               $today    The day, `YYYY-MM-DD`.
	 *
	 * @return array{status: int, message: string|null}
	 *
	 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function respond(string $surveyId, string $uid, array $answers, string $today): array;

	/**
	 * The results of a survey, with the response rate.
	 *
	 * @param string $surveyId The survey.
	 *
	 * @return array<string, mixed>|null Null when the survey does not exist.
	 *
	 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-003
	 */
	public function results(string $surveyId): ?array;
}//end interface
