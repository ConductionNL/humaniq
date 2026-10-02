<?php

/**
 * LoonaangifteYearTest: the year entry names the shipped XSD and the declaration periods of the year.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte
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

namespace OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte;

use DOMDocument;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteYear;
use PHPUnit\Framework\TestCase;

/**
 * The 2026 entry and its periods (Gegevensspecificaties 2026, p37).
 */
class LoonaangifteYearTest extends TestCase {

	/**
	 * The 2026 entry points at an XSD that loads as a schema.
	 *
	 * @return void
	 */
	public function testThe2026XsdLoads(): void {
		$year = LoonaangifteYear::forYear(2026);
		self::assertNotNull($year);
		self::assertSame(['2.0', 'http://xml.belastingdienst.nl/schemas/Loonaangifte/2026/01'], [$year['version'], $year['namespace']]);
		self::assertFileExists($year['xsd']);

		$doc = new DOMDocument();
		$doc->loadXML('<Loonaangifte xmlns="' . $year['namespace'] . '" version="2.0"/>');
		$previous = libxml_use_internal_errors(true);
		self::assertFalse($doc->schemaValidate($year['xsd']), 'An empty return is not valid, so the schema was really read.');
		$messages = array_map(static fn ($e): string => $e->message, libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		self::assertStringContainsString('Bericht', implode(' ', $messages));
		self::assertNull(LoonaangifteYear::forYear(2031));
	}//end testThe2026XsdLoads()

	/**
	 * A month, a four-week period, the year, and what is not a period.
	 *
	 * @return void
	 */
	public function testThePeriods(): void {
		self::assertSame(['2026-06-01', '2026-06-30'], LoonaangifteYear::periodDates('2026-06', 'maand'));
		self::assertSame(['2026-02-01', '2026-02-28'], LoonaangifteYear::periodDates('2026-02', 'maand'));
		self::assertSame(['2026-01-01', '2026-01-25'], LoonaangifteYear::periodDates('2026-P01', 'vierweken'));
		self::assertSame(['2026-11-30', '2026-12-31'], LoonaangifteYear::periodDates('2026-P13', 'vierweken'));
		self::assertSame(['2026-01-01', '2026-12-31'], LoonaangifteYear::periodDates('2026', 'jaar'));
		self::assertNull(LoonaangifteYear::periodDates('2026-P14', 'vierweken'));
		self::assertNull(LoonaangifteYear::periodDates('2026-06', 'kwartaal'));
		self::assertNull(LoonaangifteYear::periodDates('juni', 'maand'));
	}//end testThePeriods()

}//end class
