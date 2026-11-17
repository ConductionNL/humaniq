<?php

/**
 * Contract of TrainingCompetenceWriter
 *
 * The public surface of TrainingCompetenceWriter that other components call, so they depend
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
 * Contract of TrainingCompetenceWriter.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrainingCompetenceWriterInterface {
	/**
	 * The fields to fill in before a training record is saved: the
	 * administration from the employee and, on an attended record, the
	 * completion date (the planned day, or today) and the validity
	 * (completion date plus `validityMonths`).
	 *
	 * @param array<string, mixed> $record The record as it will be saved.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
	 */
	public function stamps(array $record): array;

	/**
	 * Grant or extend the competence of one training record.
	 *
	 * @param array<string, mixed> $record The record as saved.
	 *
	 * @return string|null The id of the competence written, or null when nothing was written.
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
	 */
	public function apply(array $record): ?string;
}//end interface
