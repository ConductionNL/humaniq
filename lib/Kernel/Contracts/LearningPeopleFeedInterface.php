<?php

/**
 * Contract of LearningPeopleFeed
 *
 * The public surface of LearningPeopleFeed that other components call, so they depend
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
 * Contract of LearningPeopleFeed.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearningPeopleFeedInterface {
	/**
	 * The active employees on a day, optionally only those changed after a moment.
	 *
	 * @param string      $today         The day, `YYYY-MM-DD`.
	 * @param string|null $modifiedSince Only rows whose employee, placement or unit changed after this moment.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
	 */
	public function people(string $today, ?string $modifiedSince=null): array;
}//end interface
