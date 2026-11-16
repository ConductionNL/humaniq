<?php

/**
 * Contract of RetentionExpiryService
 *
 * The public surface of RetentionExpiryService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of RetentionExpiryService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RetentionExpiryServiceInterface {
	/**
	 * Release lapsed floor holds and mark expired records, or, with the
	 * switch off, count what would be released and marked.
	 *
	 * @param DateTimeImmutable $today The day the walk runs.
	 *
	 * @return array{enabled: bool, released: int, marked: int, wouldRelease: int, wouldMark: int}
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function run(DateTimeImmutable $today): array;
}//end interface
