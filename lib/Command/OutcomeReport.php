<?php

/**
 * Outcome Report
 *
 * Writes one console line per processed item (an item whose status counts as
 * a failure as an error), then the item and failure totals in the command's
 * own wording, and turns the outcome into the exit code. Shared by the occ
 * commands that process a batch and report per item.
 *
 * @category Command
 * @package  OCA\Humaniq\Command
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
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-006
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports the per-item outcomes of one batch command run.
 *
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-006
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-007
 */
final class OutcomeReport {

	/**
	 * Outcome statuses that count as a failure.
	 *
	 * @var string[]
	 */
	private array $failureStatuses = ['failed'];

	/**
	 * @param OutputInterface $output  Console output.
	 * @param string          $summary The totals line, a sprintf format taking the item count and the failure count.
	 */
	public function __construct(
		private readonly OutputInterface $output,
		private readonly string $summary,
	) {

	}//end __construct()

	/**
	 * The same report, counting other statuses as failures.
	 *
	 * @param string[] $statuses Outcome statuses that count as a failure.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-006
	 */
	public function failingOn(array $statuses): self {
		$report = clone $this;
		$report->failureStatuses = $statuses;

		return $report;

	}//end failingOn()

	/**
	 * Write every outcome and the totals; 1 when any outcome failed, else 0.
	 *
	 * @param array<int, array<string, mixed>>               $outcomes The outcomes.
	 * @param \Closure(array<string, mixed>, string): string $describe The line for one outcome and its status.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-006
	 */
	public function exitCode(array $outcomes, \Closure $describe): int {
		$failures = 0;
		foreach ($outcomes as $outcome) {
			$failures += $this->writeOutcome($outcome, $describe);
		}

		$this->output->writeln(sprintf($this->summary, count($outcomes), $failures));

		return $failures > 0 ? 1 : 0;

	}//end exitCode()

	/**
	 * Write one outcome's line; 1 when it is a failure, else 0.
	 *
	 * @param array<string, mixed>                           $outcome  The outcome.
	 * @param \Closure(array<string, mixed>, string): string $describe The line for one outcome and its status.
	 *
	 * @return int
	 */
	private function writeOutcome(array $outcome, \Closure $describe): int {
		$status = (string)$outcome['status'];
		$line   = $describe($outcome, $status);
		if (in_array($status, $this->failureStatuses, true) === false) {
			$this->output->writeln($line);
			return 0;
		}

		$this->output->writeln('<error>' . $line . '</error>');
		return 1;

	}//end writeOutcome()
}//end class
