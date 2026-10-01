<?php

/**
 * LoonaangifteYear
 *
 * The year entry of the wage tax return message: the message version, the
 * XSD the Belastingdienst publishes for that year (shipped in
 * lib/Standards/loonaangifte/), its namespace, and the declaration periods
 * the XSD accepts (Gegevensspecificaties aangifte loonheffingen 2026 v3.0,
 * p37, "Datum einde tijdvak"). A new year is a new entry here and a new XSD
 * beside the old one (filings-wage-tax-message D2).
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;

/**
 * Per-year message version, XSD and declaration periods.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
final class LoonaangifteYear {

	/**
	 * The entries per year.
	 *
	 * The four-weekly periods are the GS 2026 p37 table, in order.
	 *
	 * @var array<int, array{version: string, namespace: string, xsdFile: string, fourWeekly: list<array{0: string, 1: string}>}>
	 */
	private const YEARS = [
		2026 => [
			'version' => '2.0',
			'namespace' => 'http://xml.belastingdienst.nl/schemas/Loonaangifte/2026/01',
			'xsdFile' => 'Loonaangifte2026v2.0.xsd',
			'fourWeekly' => [
				['2026-01-01', '2026-01-25'],
				['2026-01-26', '2026-02-22'],
				['2026-02-23', '2026-03-22'],
				['2026-03-23', '2026-04-19'],
				['2026-04-20', '2026-05-17'],
				['2026-05-18', '2026-06-14'],
				['2026-06-15', '2026-07-12'],
				['2026-07-13', '2026-08-09'],
				['2026-08-10', '2026-09-06'],
				['2026-09-07', '2026-10-04'],
				['2026-10-05', '2026-11-01'],
				['2026-11-02', '2026-11-29'],
				['2026-11-30', '2026-12-31'],
			],
		],
	];

	/**
	 * The entry of a year, or null when humaniq ships no specification for it.
	 *
	 * @param int $year The calendar year.
	 *
	 * @return array{version: string, namespace: string, xsd: string}|null
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	public static function forYear(int $year): ?array {
		$entry = (self::YEARS[$year] ?? null);
		if ($entry === null) {
			return null;
		}

		return [
			'version' => $entry['version'],
			'namespace' => $entry['namespace'],
			'xsd' => dirname(__DIR__, 2) . '/Standards/loonaangifte/' . $entry['xsdFile'],
		];
	}//end forYear()

	/**
	 * The first and last day of a declaration period.
	 *
	 * @param string $period  The period: `YYYY-MM` (month), `YYYY-Pnn` (four weeks) or `YYYY` (year).
	 * @param string $tijdvak The filing frequency: maand, vierweken or jaar.
	 *
	 * @return array{0: string, 1: string}|null Null for an unknown year, period or frequency.
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	public static function periodDates(string $period, string $tijdvak): ?array {
		if (preg_match('/^(\d{4})(?:-(\d{2})|-P(\d{2}))?$/', $period, $m) !== 1 || isset(self::YEARS[(int)$m[1]]) === false) {
			return null;
		}

		$year = (int)$m[1];
		$month = (int)($m[2] ?? 0);
		$fourWeek = (int)($m[3] ?? 0);
		if ($tijdvak === 'maand' && $month >= 1 && $month <= 12) {
			$first = sprintf('%04d-%02d-01', $year, $month);
			return [$first, date('Y-m-t', (int)strtotime($first))];
		}

		if ($tijdvak === 'vierweken' && $fourWeek >= 1) {
			return (self::YEARS[$year]['fourWeekly'][($fourWeek - 1)] ?? null);
		}

		if ($tijdvak === 'jaar' && $month === 0 && $fourWeek === 0) {
			return [$year . '-01-01', $year . '-12-31'];
		}

		return null;
	}//end periodDates()

}//end class
