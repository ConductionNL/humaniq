<?php

/**
 * Backlog Report
 *
 * Writes one console line per backlog attempt (failures as errors), then the
 * attempt and failure totals, and turns the outcome into the exit code. Shared
 * by the occ commands that process a docudesk backlog.
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
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports the attempts of one backlog run.
 *
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-006
 */
final class BacklogReport {

	/**
	 * @param OutputInterface $output          Console output.
	 * @param string[] $failureStatuses Outcome statuses that count as a failure.
	 */
	public function __construct(
		private readonly OutputInterface $output,
		private readonly array $failureStatuses,
	) {

	}//end __construct()

	/**
	 * Write every attempt and the totals; 1 when any attempt failed, else 0.
	 *
	 * @param array<int, array<string, mixed>>             $results  The attempts.
	 * @param \Closure(array<string, mixed>, string): string $describe The line for one attempt and its status.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
	 */
	public function exitCode(array $results, \Closure $describe): int {
		$failed = 0;
		foreach ($results as $result) {
			$status  = (string)$result['status'];
			$failed += $this->writeAttempt($status, $describe($result, $status));
		}

		$this->output->writeln(sprintf('  %d poging(en) verwerkt, %d mislukt.', count($results), $failed));

		return $failed > 0 ? 1 : 0;

	}//end exitCode()

	/**
	 * Write one attempt's line; 1 when it is a failure, else 0.
	 *
	 * @param string $status The attempt's status.
	 * @param string $line   The attempt's line.
	 *
	 * @return int
	 */
	private function writeAttempt(string $status, string $line): int {
		if (in_array($status, $this->failureStatuses, true) === true) {
			$this->output->writeln('<error>' . $line . '</error>');
			return 1;
		}

		$this->output->writeln($line);
		return 0;

	}//end writeAttempt()
}//end class
