<?php

/**
 * Humaniq ReportedPayee
 *
 * One payee of a third-party report: its id and its stored record.
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * A payee as the report reads it.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */
final class ReportedPayee {

	/**
	 * @param string                    $payeeId The ThirdPartyPayee id.
	 * @param array<string, mixed>|null $row     The stored payee, or null when it does not resolve.
	 */
	public function __construct(
		public readonly string $payeeId,
		public readonly ?array $row,
	) {

	}//end __construct()

	/**
	 * The payee's initials and last name, or the id when it has neither.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public function label(): string {
		$name = trim((string)($this->row['initials'] ?? '') . ' ' . (string)($this->row['lastName'] ?? ''));

		return ($name === '' ? $this->payeeId : $name);
	}//end label()
}//end class
