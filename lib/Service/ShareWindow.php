<?php

/**
 * Humaniq ShareWindow
 *
 * The whole days a membership share is measured over inside UnitMembership:
 * the first and the last day, both at midnight.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * A window of whole days.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
final class ShareWindow {

	/**
	 * The first day, at midnight.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $start;

	/**
	 * The last day, at midnight.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $end;
}//end class
