<?php

/**
 * Humaniq CorrectionRender
 *
 * The state of making one wage-tax correction: the correction filing, its
 * period, dates and year, the period's current lines and the facts stored.
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
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One correction being made.
 *
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */
final class CorrectionRender {

	/**
	 * The correction filing, with its id.
	 *
	 * @var array<string, mixed>
	 */
	public array $correction = [];

	/**
	 * The corrected period.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The period's first and last day.
	 *
	 * @var array{0: string, 1: string}
	 */
	public array $dates = ['', ''];

	/**
	 * The year's message version, namespace and XSD.
	 *
	 * @var array<string, string>
	 */
	public array $year = [];

	/**
	 * The period's approved run, administration and lines.
	 *
	 * @var array<string, mixed>
	 */
	public array $current = [];

	/**
	 * The facts stored on the correction.
	 *
	 * @var array<string, mixed>
	 */
	public array $stored = [];
}//end class
