<?php

/**
 * Contract of TeamDigestComposer
 *
 * The public surface of TeamDigestComposer that other components call, so they depend
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
 * Contract of TeamDigestComposer.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TeamDigestComposerInterface {
	/**
	 * Who in a unit is away today and whose birthday it is, and the message.
	 *
	 * @param string $orgUnitId       The unit.
	 * @param bool   $includeChildren Whether the teams under it count.
	 * @param string $today           The day, `YYYY-MM-DD`.
	 *
	 * @return array{unit: string, away: array<int, array{name: string, until: ?string}>, birthdays: array<int, string>, message: string}
	 *
	 * @throws \RuntimeException When the unit does not exist.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function compose(string $orgUnitId, bool $includeChildren, string $today): array;
}//end interface
