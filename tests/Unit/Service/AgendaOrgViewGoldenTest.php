<?php

/**
 * Golden test for AgendaOrgView::isManagedBy: who manages which unit, up the tree and within the administration.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AgendaOrgView;
use PHPUnit\Framework\TestCase;

/**
 * Who manages which unit, allowed and refused.
 */
class AgendaOrgViewGoldenTest extends TestCase {

	/**
	 * Every unit against every account set.
	 *
	 * @return void
	 */
	public function testWhoManagesEveryUnit(): void {
		$a = ['administrationId' => 'ADM-A'];
		$rows = [
			'Employee' => [
				['id' => 'e-top'] + $a + ['nextcloudUserId' => 'top'],
				['id' => 'e-mid'] + $a + ['nextcloudUserId' => ' mid '],
				['id' => 'e-other', 'administrationId' => 'ADM-B', 'nextcloudUserId' => 'other'],
				['id' => 'e-placed'] + $a + ['nextcloudUserId' => 'placed'],
				['id' => 'e-none', 'nextcloudUserId' => ''],
			],
			'OrgUnit' => [
				['id' => 'top', 'managerId' => 'e-top'] + $a,
				['id' => 'mid', 'managerId' => 'e-mid', 'parentUnitId' => 'top'] + $a,
				['id' => 'leaf', 'managerId' => '', 'parentUnitId' => ' mid '] + $a,
				['id' => 'foreign', 'managerId' => 'e-other', 'administrationId' => 'ADM-B'],
				['id' => 'under-foreign', 'managerId' => '', 'parentUnitId' => 'foreign'] + $a,
				['id' => 'loop-1', 'managerId' => '', 'parentUnitId' => 'loop-2'] + $a,
				['id' => 'loop-2', 'managerId' => '', 'parentUnitId' => 'loop-1'] + $a,
				['id' => 'no-admin', 'managerId' => '', 'parentUnitId' => 'top'],
				['id' => 'no-admin-empty', 'managerId' => 'e-other'],
				['id' => 'dangling', 'managerId' => '', 'parentUnitId' => 'gone'] + $a,
				['id' => 'foreign-mid', 'managerId' => '', 'parentUnitId' => 'top', 'administrationId' => 'ADM-B'],
				['id' => 'below-foreign-mid', 'managerId' => '', 'parentUnitId' => 'foreign-mid'] + $a,
			],
			'OrgAssignment' => [
				['employeeId' => 'e-placed', 'orgUnitId' => 'no-admin', 'startDate' => '2020-01-01'],
			],
		];
		$view = new AgendaOrgView('top', $rows);
		$got = [];
		foreach (['top', 'mid', 'leaf', 'foreign', 'under-foreign', 'loop-1', 'no-admin', 'no-admin-empty', 'dangling', 'below-foreign-mid', 'unknown'] as $unit) {
			foreach (['top' => ['top'], 'mid' => ['mid'], 'other' => ['other'], 'both' => ['other', 'top'], 'nobody' => ['nobody'], 'none' => []] as $who => $accounts) {
				$got[$unit . ':' . $who] = $view->isManagedBy($unit, $accounts);
			}
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/agenda-org-view-managed-by.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
	}//end testWhoManagesEveryUnit()
}//end class
