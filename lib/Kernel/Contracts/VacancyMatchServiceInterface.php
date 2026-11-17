<?php

/**
 * Contract of VacancyMatchService
 *
 * The public surface of VacancyMatchService that other components call, so they depend
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
 * Contract of VacancyMatchService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VacancyMatchServiceInterface {
	/**
	 * The ranked matches for one vacancy: applicants still in play or in the
	 * talent pool, and colleagues with a current competence.
	 *
	 * @param string $vacancyId The vacancy.
	 * @param string $today     The day competences and retention are read on (Y-m-d).
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	public function matchesFor(string $vacancyId, string $today): array;
}//end interface
