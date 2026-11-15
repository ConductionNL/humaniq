<?php

/**
 * Humaniq CorrectionComparison
 *
 * One comparison of the received loonaangifte stand with the current
 * relationships: what is still unmatched, and the correction lines, initial
 * relationships and withdrawals found so far.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;

/**
 * One comparison of the received stand with the current relationships.
 *
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */
final class CorrectionComparison {

	/**
	 * The received relationships not yet matched, by key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $received = [];

	/**
	 * The correction lines.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $lines = [];

	/**
	 * The relationship trees to send as initial.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $initial = [];

	/**
	 * The withdrawals.
	 *
	 * @var list<array<string, string>>
	 */
	public array $withdrawn = [];
}//end class
