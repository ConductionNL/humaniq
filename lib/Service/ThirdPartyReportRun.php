<?php

/**
 * Humaniq ThirdPartyReportRun
 *
 * The state of assembling one yearly third-party report: the administration,
 * the year, the totals per payee, the lines and the findings so far.
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
 * One report being assembled.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */
final class ThirdPartyReportRun {

	/**
	 * The administration.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The year whose payments are reported.
	 *
	 * @var int
	 */
	public int $year = 0;

	/**
	 * The account assembling it.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * The year's stored report, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $current = null;

	/**
	 * The hrAdministration.
	 *
	 * @var array<string, mixed>
	 */
	public array $administration = [];

	/**
	 * The year's totals per payee.
	 *
	 * @var array<string, array{cents: int, first: string, last: string}>
	 */
	public array $totals = [];

	/**
	 * The reported lines.
	 *
	 * @var list<array<string, string|int>>
	 */
	public array $lines = [];

	/**
	 * What stops or warns about the report.
	 *
	 * @var list<array<string, string>>
	 */
	public array $findings = [];
}//end class
