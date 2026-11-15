<?php

/**
 * Humaniq HandoffCompilation
 *
 * The state of compiling one external bureau handoff inside
 * SalaryBureauExchangeService: the administration, period and account
 * compiling it, the administration's handoffs, the handoff of the period
 * and its id once cleared.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One handoff being compiled.
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */
final class HandoffCompilation {

	/**
	 * The administration.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The wage period, YYYY-MM.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The account compiling it.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * The administration's handoffs.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $handoffs = [];

	/**
	 * The handoff of the period, or null when there is none yet.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $current = null;

	/**
	 * The id of the period's concept handoff once cleared, or '' for a new one.
	 *
	 * @var string
	 */
	public string $handoffId = '';
}//end class
