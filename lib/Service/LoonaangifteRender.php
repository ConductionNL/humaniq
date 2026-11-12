<?php

/**
 * Humaniq LoonaangifteRender
 *
 * The state of rendering one wage-tax return: the filing, its period and
 * year, the run's lines, the render facts and the carried corrections.
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One filing being rendered.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
final class LoonaangifteRender {

	/**
	 * The LoonaangifteFiling, with its id.
	 *
	 * @var array<string, mixed>
	 */
	public array $filing = [];

	/**
	 * The period, YYYY-MM.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The declaration period's first and last day.
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
	 * The approved run, the administration and the lines of the period.
	 *
	 * @var array<string, mixed>
	 */
	public array $current = [];

	/**
	 * The render facts stored on the filing.
	 *
	 * @var array<string, mixed>
	 */
	public array $stored = [];

	/**
	 * The corrections the return carries.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $corrections = [];
}//end class
