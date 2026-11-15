<?php

/**
 * The deadline and identification rules of the third-party payments report.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlThirdPartyChecks;
use PHPUnit\Framework\TestCase;

/**
 * Rules nl-ubd-deadline (on ThirdPartyPayment) and
 * nl-ubd-payee-identification (on ThirdPartyPayee).
 */
class NlThirdPartyChecksTest extends TestCase {

	/**
	 * February without a report: a 2026 payment with no 2026 report sent
	 * is a violation once 31 January 2027 has passed, and not before; a
	 * sent report clears it.
	 *
	 * @return void
	 */
	public function testFebruaryWithoutAReport(): void {
		$check = NlThirdPartyChecks::checks()['ThirdPartyPayment']['nl-ubd-deadline'];
		$payment = ['payeeId' => 'p-1', 'paidOn' => '2026-03-10', 'amount' => 450.0, 'administrationId' => 'ADM-001'];

		self::assertFalse($check($payment, ['ubd' => ['sent' => []], 'today' => '2027-02-01']));
		self::assertTrue($check($payment, ['ubd' => ['sent' => []], 'today' => '2027-01-31']), 'the deadline day itself is still on time');
		self::assertTrue($check($payment, ['ubd' => ['sent' => ['ADM-001|2026' => true]], 'today' => '2027-02-01']));
		self::assertFalse($check($payment, ['ubd' => ['sent' => ['ADM-002|2026' => true]], 'today' => '2027-02-01']), 'another administration\'s report does not count');
		self::assertTrue($check(['paidOn' => 'not a date'], ['today' => '2027-02-01']), 'an unreadable date is not this rule\'s finding');
	}//end testFebruaryWithoutAReport()

	/**
	 * A payee with payments and no BSN or date of birth is a violation; a
	 * payee nobody paid is not.
	 *
	 * @return void
	 */
	public function testAPaidPayeeNeedsBsnAndDateOfBirth(): void {
		$check = NlThirdPartyChecks::checks()['ThirdPartyPayee']['nl-ubd-payee-identification'];
		$context = ['ubd' => ['paidPayees' => ['p-1' => true]]];

		self::assertTrue($check(['id' => 'p-1', 'bsn' => '111222333', 'dateOfBirth' => '1971-05-14'], $context));
		self::assertFalse($check(['id' => 'p-1', 'dateOfBirth' => '1971-05-14'], $context));
		self::assertFalse($check(['id' => 'p-1', 'bsn' => '111222333', 'dateOfBirth' => ''], $context));
		self::assertTrue($check(['id' => 'p-2'], $context), 'nobody paid this payee');
	}//end testAPaidPayeeNeedsBsnAndDateOfBirth()

	/**
	 * Both rules are declared mandatory in the payroll corpus.
	 *
	 * @return void
	 */
	public function testBothRulesAreDeclaredMandatory(): void {
		$rules = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Standards/rules/payroll.json'), true)['rules'];
		$byId = array_column($rules, null, 'id');

		foreach (['nl-ubd-deadline', 'nl-ubd-payee-identification'] as $id) {
			self::assertSame('mandatory', ($byId[$id]['severity'] ?? null), $id);
			self::assertTrue(($byId[$id]['machineCheckable'] ?? false), $id);
		}

	}//end testBothRulesAreDeclaredMandatory()

}//end class
