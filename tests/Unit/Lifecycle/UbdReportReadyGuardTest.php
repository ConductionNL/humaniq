<?php

/**
 * The readiness guard on a third-party payments report.
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\UbdReportReadyGuard;
use PHPUnit\Framework\TestCase;

/**
 * A report is made ready only with a validated message and no blocking finding.
 */
class UbdReportReadyGuardTest extends TestCase {

	/**
	 * A report with a payee without a BSN refuses with the finding; an
	 * unassembled report refuses; an assembled clean one is allowed.
	 *
	 * @return void
	 */
	public function testAPayeeWithoutABsnRefusesWithTheFinding(): void {
		$guard = new UbdReportReadyGuard();
		$blocked = ['status' => 'concept', 'year' => 2026, 'blockingFindings' => 1, 'findings' => [['kind' => 'payee-without-bsn', 'severity' => 'blocking', 'payeeId' => 'p-1', 'message' => 'A. Bos heeft geen BSN.']]];

		$result = $guard->check($blocked, 'klaarzetten', 'payroll-1');
		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('A. Bos heeft geen BSN.', (string)$result->getMessage());

		self::assertFalse($guard->check(['status' => 'concept', 'year' => 2026], 'klaarzetten', 'payroll-1')->isAllowed(), 'never assembled');
		self::assertFalse($guard->check(['status' => 'concept', 'blockingFindings' => 0, 'messageXml' => ''], 'klaarzetten', 'payroll-1')->isAllowed(), 'no message');
		self::assertTrue($guard->check(['status' => 'concept', 'blockingFindings' => 0, 'warningFindings' => 1, 'messageXml' => '<UBD/>'], 'klaarzetten', 'payroll-1')->isAllowed(), 'a warning does not block');
	}//end testAPayeeWithoutABsnRefusesWithTheFinding()

}//end class
