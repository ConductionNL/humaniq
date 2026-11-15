<?php

/**
 * Humaniq CandidateScoring
 *
 * The state of scoring one candidate inside VacancyMatchService: the
 * vacancy's requirements, the candidate, the codes held and what is
 * missing so far.
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
 * One candidate being scored.
 *
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
 */
final class CandidateScoring {

	/**
	 * The vacancy's requirements.
	 *
	 * @var array{education: ?int, experience: ?float, required: list<string>, preferred: list<string>}
	 */
	public array $requirements = ['education' => null, 'experience' => null, 'required' => [], 'preferred' => []];

	/**
	 * The candidate.
	 *
	 * @var MatchCandidate
	 */
	public MatchCandidate $candidate;

	/**
	 * The competence codes the candidate holds, cleaned.
	 *
	 * @var list<string>
	 */
	public array $held = [];

	/**
	 * What is missing so far.
	 *
	 * @var list<string>
	 */
	public array $missing = [];
}//end class
