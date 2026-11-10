<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The state of one AWF review run: the settlement period and run, the
 * payslips already reviewed, and the outcome so far.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The settlement target, the reviewed payslips and the outcome of one review run.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */
final class AwfReviewRun {

	/**
	 * The period whose run settles the reviews.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The payroll run that settles the reviews.
	 *
	 * @var string
	 */
	public string $runId = '';

	/**
	 * Payslip ids already reviewed, as keys.
	 *
	 * @var array<string, bool>
	 */
	public array $done = [];

	/**
	 * The counts per review kind and the payslips that were skipped.
	 *
	 * @var array{earlyEnd: int, extraHours: int, skipped: array<int, array{payslipId: string, reason: string}>}
	 */
	public array $outcome = ['earlyEnd' => 0, 'extraHours' => 0, 'skipped' => []];
}//end class
