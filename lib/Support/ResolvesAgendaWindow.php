<?php

/**
 * ResolvesAgendaWindow.
 *
 * Reads the requested date window of an agenda style endpoint.
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude helper shared by services and checks, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

use DateTimeImmutable;

/**
 * Reads the requested date window of an agenda style endpoint.
 */
trait ResolvesAgendaWindow {
	/**
	 * The period asked about, or null when it is unusable.
	 *
	 * @param string|null $from First day (ISO date).
	 * @param string|null $to Last day (ISO date).
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null The window.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function window(?string $from, ?string $to): ?array {
		try {
			$start = new DateTimeImmutable(substr(trim((string)$from), 0, 10));
			$end = new DateTimeImmutable(substr(trim((string)$to), 0, 10));
		} catch (\Throwable $e) {
			return null;
		}

		if ($end < $start) {
			return null;
		}

		return [$start, $end];
	}//end window()
}//end class
