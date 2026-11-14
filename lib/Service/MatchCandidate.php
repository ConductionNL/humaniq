<?php

/**
 * Humaniq MatchCandidate
 *
 * One person matched against a vacancy: an applicant or a colleague, with
 * the education level, years of experience and competence codes the match
 * score reads.
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
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * A candidate for a vacancy.
 *
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
 */
final class MatchCandidate {

	/**
	 * Applicant or employee.
	 *
	 * @var string
	 */
	public string $kind = '';

	/**
	 * The record id.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The person's name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The education level, null when unknown.
	 *
	 * @var int|null
	 */
	public ?int $educationLevel = null;

	/**
	 * The years of experience, null when unknown.
	 *
	 * @var float|null
	 */
	public ?float $experienceYears = null;

	/**
	 * The competence codes held.
	 *
	 * @var list<string>
	 */
	public array $competences = [];
}//end class
