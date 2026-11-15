<?php

/**
 * WagePeriod.
 *
 * A wage period as a calendar month: `YYYY-MM`, or `YYYY-M` with surrounding
 * spaces, read as the same month.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Reads a wage period text as a month, or as no month at all. PHP's date
 * parser reads '', '202605' or 'now' as today and '2026-00' as December of
 * the year before, so a period is checked before it becomes a date.
 *
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
 */
final class WagePeriod {

	/**
	 * The month as `YYYY-MM`, or null when the text is not a month.
	 *
	 * @var string|null
	 */
	private readonly ?string $month;

	/**
	 * @param string $text The period text.
	 *
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 */
	public function __construct(string $text) {
		$parts = [];
		$this->month = (preg_match('/^(\d{4})-(0?[1-9]|1[0-2])$/', trim($text), $parts) === 1 ? sprintf('%s-%02d', $parts[1], (int)$parts[2]) : null);
	}//end __construct()

	/**
	 * The month as `YYYY-MM`, or null when the text is not a month.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 */
	public function month(): ?string {
		return $this->month;
	}//end month()

	/**
	 * The first day of the month, or null when the text is not a month.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 */
	public function firstDay(): ?DateTimeImmutable {
		if ($this->month === null) {
			return null;
		}

		return new DateTimeImmutable($this->month . '-01');
	}//end firstDay()
}//end class
