<?php

/**
 * JaaropgaafYearJob test
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\BackgroundJob
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
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use OCA\Humaniq\BackgroundJob\JaaropgaafYearJob;
use OCA\Humaniq\Service\HrDocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The queued year batch runs the existing idempotent backlog for its year.
 */
class JaaropgaafYearJobTest extends TestCase {

	/**
	 * The job runs the jaaropgaaf backlog for every employee of its year.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function testTheJobRunsTheBacklogForItsYear(): void {
		$service = $this->createMock(HrDocumentService::class);
		$service->expects($this->once())->method('generateBacklog')
			->with('jaaropgaaf', null, null, '2026')
			->willReturn([['status' => 'generated'], ['status' => 'skipped-idempotent']]);

		$job = new JaaropgaafYearJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class));
		$job->runForYear(['year' => 2026, 'userId' => 'hr-1']);
	}//end testTheJobRunsTheBacklogForItsYear()

	/**
	 * A job without a usable year does nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function testAJobWithoutAYearDoesNothing(): void {
		$service = $this->createMock(HrDocumentService::class);
		$service->expects($this->never())->method('generateBacklog');

		$job = new JaaropgaafYearJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class));
		$job->runForYear(['year' => 'x']);
	}//end testAJobWithoutAYearDoesNothing()

}//end class
