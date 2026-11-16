<?php

/**
 * Contract of ExitInterviewService
 *
 * The public surface of ExitInterviewService that other components call, so they depend
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
 * Contract of ExitInterviewService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExitInterviewServiceInterface {
	/**
	 * What a new interview takes from its case: the leaver, the administration
	 * and the leaver's department on the day it was held.
	 *
	 * @param array<string, mixed> $interview The interview being created.
	 *
	 * @return array<string, mixed> The fields to set; empty when there is nothing to add.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function fillIn(array $interview): array;

	/**
	 * Stamp the case's exit interview date with the day the interview was held.
	 *
	 * @param array<string, mixed> $interview The interview that was created.
	 *
	 * @return bool Whether the case was written.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function stampCase(array $interview): bool;

	/**
	 * The leavers' own reasons counted over the months before today, most
	 * named first. Anonymised interviews count too.
	 *
	 * @param string $today  The day, `YYYY-MM-DD`.
	 * @param int    $months How many months back.
	 *
	 * @return array{from: string, until: string, total: int, reasons: array<int, array{reason: string, label: string, count: int}>}
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function reasonCounts(string $today, int $months=12): array;

	/**
	 * Clear the person link and free text of every interview held more than
	 * the given days ago and not anonymised yet.
	 *
	 * @param string $today     The day, `YYYY-MM-DD`.
	 * @param string $now       The moment, ISO 8601.
	 * @param int    $afterDays After how many days.
	 *
	 * @return array<int, string> The interviews anonymised.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function anonymiseDue(string $today, string $now, int $afterDays=90): array;
}//end interface
