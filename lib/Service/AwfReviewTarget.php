<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One payslip an AWF review recomputes, and the kind of review that names it.
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
 * A payslip under review and why it is reviewed.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */
final class AwfReviewTarget {

	/**
	 * Constructor.
	 *
	 * @param string               $kind `earlyEnd` or `extraHours`.
	 * @param array<string, mixed> $slip The payslip.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly array $slip,
	) {
	}//end __construct()
}//end class
