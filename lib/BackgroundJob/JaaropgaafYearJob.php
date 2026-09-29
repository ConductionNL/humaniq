<?php

/**
 * Jaaropgaaf year job
 *
 * Runs the annual statements of one finished year for every employee with
 * payslips in it, queued from the Jaaropgaven page
 * (payroll-annual-statement-action D2). The backlog it runs is idempotent per
 * statement, so a second queued run for the same year renders nothing new.
 *
 * @category BackgroundJob
 * @package  OCA\Humaniq\BackgroundJob
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

namespace OCA\Humaniq\BackgroundJob;

use OCA\Humaniq\Service\HrDocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One queued run of a year's annual statements.
 *
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
 */
class JaaropgaafYearJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time              Job time factory.
	 * @param HrDocumentService $hrDocumentService Aggregates and renders the statements.
	 * @param LoggerInterface   $logger            PSR-3 logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly HrDocumentService $hrDocumentService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}//end __construct()

	/**
	 * Run the queued year.
	 *
	 * @param mixed $argument {year, userId}.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	protected function run($argument): void {
		$this->runForYear($argument);
	}//end run()

	/**
	 * Render every statement of the argument's year; a missing year does nothing.
	 *
	 * @param mixed $argument {year, userId}.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function runForYear(mixed $argument): void {
		$year = is_array($argument) === true ? ($argument['year'] ?? null) : null;
		if (is_int($year) === false && (is_string($year) === false || ctype_digit($year) === false)) {
			$this->logger->warning('humaniq: annual statement job without a year, nothing generated');
			return;
		}

		$outcomes = $this->hrDocumentService->generateBacklog('jaaropgaaf', null, null, (string)$year);
		$counts = array_count_values(array_map(static fn (array $outcome): string => (string)($outcome['status'] ?? 'unknown'), $outcomes));
		$this->logger->info(
			sprintf('humaniq: annual statements %s generated for %d employees', (string)$year, count($outcomes)),
			['statuses' => $counts, 'queuedBy' => is_array($argument) === true ? ($argument['userId'] ?? null) : null]
		);
	}//end runForYear()
}//end class
