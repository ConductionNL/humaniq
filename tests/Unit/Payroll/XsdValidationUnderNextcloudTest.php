<?php

/**
 * XsdValidationUnderNextcloudTest: the Belastingdienst messages validate inside a booted Nextcloud.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessage;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteYear;
use OCA\Humaniq\Service\UbdMessage;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's OC::boot() sets a libxml external entity loader that returns
 * null, so libxml can open no file at all. A schema read from a path then
 * fails and every message reads as invalid. These tests install that same
 * loader and validate the shipped golden messages.
 */
class XsdValidationUnderNextcloudTest extends TestCase {

	/**
	 * Install the loader Nextcloud installs at boot.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		libxml_set_external_entity_loader(static function () {
			return null;
		});
	}//end setUp()

	/**
	 * Restore libxml's default loader.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		libxml_set_external_entity_loader(null);
		parent::tearDown();
	}//end tearDown()

	/**
	 * The golden wage tax return is valid against the 2026 XSD under Nextcloud.
	 *
	 * @return void
	 */
	public function testTheWageTaxReturnValidatesUnderNextcloud(): void {
		$year = LoonaangifteYear::forYear(2026);
		self::assertNotNull($year);
		$xml = (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/loonaangifte/loonaangifte-2026-06-adm-001.xml');

		self::assertSame([], LoonaangifteMessage::errors($xml, $year['xsd']));
	}//end testTheWageTaxReturnValidatesUnderNextcloud()

	/**
	 * An invalid return still reports the schema's own error, so the schema was read.
	 *
	 * @return void
	 */
	public function testAnInvalidReturnStillNamesTheSchemaError(): void {
		$year = LoonaangifteYear::forYear(2026);
		self::assertNotNull($year);
		$xml = '<Loonaangifte xmlns="' . $year['namespace'] . '" version="2.0"/>';

		self::assertStringContainsString('Bericht', implode(' ', LoonaangifteMessage::errors($xml, $year['xsd'])));
	}//end testAnInvalidReturnStillNamesTheSchemaError()

	/**
	 * The golden IB 47 report is valid against the UBD XSD under Nextcloud.
	 *
	 * @return void
	 */
	public function testTheThirdPartyReportValidatesUnderNextcloud(): void {
		$xml = (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/ubd/ubd-2026-adm-001.xml');

		self::assertSame([], UbdMessage::errors($xml));
	}//end testTheThirdPartyReportValidatesUnderNextcloud()
}//end class
