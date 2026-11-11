<?php

/**
 * Humaniq SurveyResultsRun
 *
 * The state of computing one survey's results inside SurveyResults: its
 * questions, the minimum group size, the department names, whether free text
 * is returned, and the department results so far.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One survey's results being computed.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
 */
final class SurveyResultsRun {

	/**
	 * The questions that have a key.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $questions = [];

	/**
	 * The minimum group size.
	 *
	 * @var int
	 */
	public int $min = SurveyResults::DEFAULT_MIN;

	/**
	 * Department names by id.
	 *
	 * @var array<string, string>
	 */
	public array $unitNames = [];

	/**
	 * Whether the summaries being made return free text (overall only).
	 *
	 * @var bool
	 */
	public bool $withText = false;

	/**
	 * The department results so far.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $units = [];
}//end class
