<?php

/**
 * Golden test for the Offboarding part of RelatedContextIndexer: the planned completion per employee.
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
 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RelatedContextIndexer;
use PHPUnit\Framework\TestCase;

/**
 * The planned completion per employee, from every shape of offboarding case.
 */
class RelatedContextOffboardingGoldenTest extends TestCase {

	/**
	 * Every shape of offboarding case.
	 *
	 * @return void
	 */
	public function testThePlannedCompletionOfEveryEmployee(): void {
		$offboardings = [
			['employeeId' => 'emp-a', 'lastWorkingDay' => '2026-05-31', 'status' => 'gepland'],
			['employeeId' => 'emp-a', 'lastWorkingDay' => '2026-06-30'],
			['employeeId' => 'emp-a', 'lastWorkingDay' => '2026-04-30'],
			['employeeId' => 'emp-a', 'lastWorkingDay' => '2026-12-31', 'status' => 'geannuleerd'],
			['employeeId' => ' emp-b ', 'lastWorkingDay' => ' 2026-03-31 '],
			['employeeId' => 'emp-b', 'lastWorkingDay' => '2026-03-31'],
			['employeeId' => 'emp-c', 'lastWorkingDay' => 'ooit'],
			['employeeId' => 'emp-c', 'lastWorkingDay' => '2026-02-28'],
			['employeeId' => '', 'lastWorkingDay' => '2026-01-31'],
			['employeeId' => 'emp-d', 'lastWorkingDay' => ''],
			['employeeId' => 'emp-e', 'lastWorkingDay' => '2026-07-31'],
			['employeeId' => 'emp-e', 'lastWorkingDay' => '2026-07-31T00:00:00Z'],
		];
		$indexer = new RelatedContextIndexer(static fn (string $schema): array => ($schema === 'Offboarding' ? $offboardings : []));

		$this->assertSame(
			['plannedCompletionByEmployeeId' => ['emp-a' => '2026-06-30', 'emp-b' => '2026-03-31', 'emp-c' => '2026-02-28', 'emp-e' => '2026-07-31']],
			$indexer->build()['Offboarding']
		);
	}//end testThePlannedCompletionOfEveryEmployee()
}//end class
