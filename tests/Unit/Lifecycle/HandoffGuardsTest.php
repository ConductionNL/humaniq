<?php

/**
 * The two guards on a payroll handoff's lifecycle.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\HandoffCloseGuard;
use OCA\Humaniq\Lifecycle\HandoffReleaseGuard;
use PHPUnit\Framework\TestCase;

/**
 * Four eyes before a handoff leaves; no closing over blocking findings.
 */
class HandoffGuardsTest extends TestCase {

	/**
	 * The person who compiled a handoff cannot set it ready; a second
	 * person can; nobody signed in cannot.
	 *
	 * @return void
	 */
	public function testFourEyesBeforeAnythingLeaves(): void {
		$handoff = ['administrationId' => 'ADM-006', 'period' => '2026-05', 'status' => 'concept', 'compiledBy' => 'hr-1'];
		$guard = new HandoffReleaseGuard();

		self::assertFalse($guard->check($handoff, 'klaarzetten', 'hr-1')->isAllowed());
		self::assertFalse($guard->check($handoff, 'klaarzetten', '')->isAllowed());
		self::assertFalse($guard->check(array_merge($handoff, ['compiledBy' => null]), 'klaarzetten', 'hr-2')->isAllowed(), 'an uncompiled handoff has nothing to release');
		self::assertTrue($guard->check($handoff, 'klaarzetten', 'hr-2')->isAllowed());
	}//end testFourEyesBeforeAnythingLeaves()

	/**
	 * A handoff with blocking intake findings cannot be closed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
	 */
	public function testAMissingPayslipBlocksClosing(): void {
		$guard = new HandoffCloseGuard();

		self::assertFalse($guard->check(['status' => 'ontvangen', 'blockingFindings' => 1], 'afsluiten', 'hr-1')->isAllowed());
		self::assertFalse($guard->check(['status' => 'ontvangen', 'blockingFindings' => null], 'afsluiten', 'hr-1')->isAllowed(), 'an unchecked intake cannot be closed');
		self::assertTrue($guard->check(['status' => 'ontvangen', 'blockingFindings' => 0], 'afsluiten', 'hr-1')->isAllowed());
	}//end testAMissingPayslipBlocksClosing()

}//end class
