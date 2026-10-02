<?php

/**
 * LoonaangifteMessageGuardTest: a Dutch wage tax filing is made ready only with a validated message and no blocking finding.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\LoonaangifteMessageGuard;
use PHPUnit\Framework\TestCase;

/**
 * A Dutch wage tax filing is made ready only with a validated message.
 */
class LoonaangifteMessageGuardTest extends TestCase {

	/**
	 * A filing whose last render listed an employee without a BSN refuses with
	 * that finding; a filing never rendered refuses; a clean one is allowed.
	 *
	 * @return void
	 */
	public function testAMissingBsnKeepsTheFilingInConcept(): void {
		$guard = new LoonaangifteMessageGuard();
		$base = ['jurisdiction' => 'NL', 'filingType' => 'loonaangifte', 'status' => 'concept', 'period' => '2026-06'];
		$blocked = array_merge($base, ['blockingFindings' => 1, 'messageFindings' => [['kind' => 'employee-without-bsn', 'severity' => 'blocking', 'employeeId' => 'emp-bakker', 'element' => 'SofiNr', 'problem' => 'Kees Bakker heeft geen burgerservicenummer.']]]);

		$result = $guard->check($blocked, 'klaarzetten', 'payroll-1');
		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('Kees Bakker heeft geen burgerservicenummer.', (string)$result->getMessage());

		self::assertFalse($guard->check($base, 'klaarzetten', 'payroll-1')->isAllowed(), 'never rendered');
		self::assertFalse($guard->check(array_merge($base, ['blockingFindings' => 0, 'messageXml' => '']), 'klaarzetten', 'payroll-1')->isAllowed(), 'no message');
		self::assertTrue($guard->check(array_merge($base, ['blockingFindings' => 0, 'warningFindings' => 1, 'messageXml' => '<Loonaangifte/>']), 'klaarzetten', 'payroll-1')->isAllowed(), 'a warning does not block');
	}//end testAMissingBsnKeepsTheFilingInConcept()

	/**
	 * Filings of other jurisdictions or kinds pass unchanged.
	 *
	 * @return void
	 */
	public function testOtherFilingsPass(): void {
		$guard = new LoonaangifteMessageGuard();
		self::assertTrue($guard->check(['jurisdiction' => 'DE', 'filingType' => 'lohnsteuer-anmeldung', 'status' => 'concept'], 'klaarzetten', 'u')->isAllowed());
		self::assertTrue($guard->check(['jurisdiction' => 'NL', 'filingType' => 'deposit', 'status' => 'concept'], 'klaarzetten', 'u')->isAllowed());
	}//end testOtherFilingsPass()

}//end class
