<?php

/**
 * GL Post Run Command
 *
 * `occ humaniq:glpost:run [--period YYYY-MM]` — the MVP trigger for
 * payroll-glpost-shillinq (design.md D5): posts every approved-but-unposted
 * PayrollRun as a balanced loonjournaalpost into shillinq's JournalEntry
 * register (optionally filtered to one wage period), printing one outcome
 * line per run plus a summary. No automatic lifecycle hook exists yet —
 * PayrollRun transitions are plain data edits today, so this command is run
 * on operator demand until `humaniq-rule-compliance-enforcement` wires
 * guards/events.
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
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\PayrollGLPostService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that posts approved payroll runs into shillinq's GL journal.
 */
class GlPostRunCommand extends Command {

	/**
	 * @param PayrollGLPostService $service The GL-posting service.
	 */
	public function __construct(
		private readonly PayrollGLPostService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:glpost:run')
			->setDescription('Post each approved PayrollRun as a balanced loonjournaalpost into shillinq (MVP trigger; run on operator demand).')
			->addOption('period', null, InputOption::VALUE_REQUIRED, 'Only post runs for this wage period (YYYY-MM).');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every selected run ends posted/skipped-no-shillinq, 1 when any ends failed.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$periodOption = $input->getOption('period');
		$period = (is_string($periodOption) === true && trim($periodOption) !== '') ? trim($periodOption) : null;

		$results = $this->service->postApprovedRuns($period);

		$output->writeln('<info>Humaniq payroll GL-post</info>');

		if ($results === []) {
			$output->writeln('  geen goedgekeurde loonruns geselecteerd' . ($period !== null ? ' voor periode ' . $period : '') . '.');
			return 0;
		}

		$failed = 0;
		foreach ($results as $result) {
			$status = (string)$result['status'];
			$line = sprintf('  run %s: %s — %s', (string)$result['runId'], $status, (string)$result['message']);

			if ($status === 'failed') {
				$failed++;
				$output->writeln('<error>' . $line . '</error>');
				continue;
			}

			$output->writeln($line);
		}

		$output->writeln(sprintf('  %d run(s) verwerkt, %d mislukt.', count($results), $failed));

		return $failed > 0 ? 1 : 0;
	}//end execute()

}//end class
