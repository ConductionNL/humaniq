<?php

/**
 * Comp Effectuate Command
 *
 * `occ hrmq:comp:effectuate --cycle CYCLE [--date YYYY-MM-DD] [--dry-run]` —
 * the batch-effectuation trigger for compensation review cycles
 * (comp-cycles design.md D5): effectuates every approved, due CompAdjustment
 * in one CompReviewCycle, printing one outcome line per adjustment plus a
 * summary. `--date` overrides "today" for the due-date check (support/testing
 * use); `--dry-run` evaluates and reports without writing anything.
 *
 * @category Command
 * @package  OCA\Hrmq\Command
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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\CompAdjustmentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that batch-effectuates a CompReviewCycle's due, approved
 * CompAdjustments.
 */
class CompEffectuateCommand extends Command {

	/**
	 * @param CompAdjustmentService $service The effectuation service.
	 */
	public function __construct(
		private readonly CompAdjustmentService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('hrmq:comp:effectuate')
			->setDescription('Effectuate every approved, due CompAdjustment in one compensation review cycle.')
			->addOption('cycle', null, InputOption::VALUE_REQUIRED, 'The CompReviewCycle id.')
			->addOption('date', null, InputOption::VALUE_REQUIRED, 'Evaluate "due" against this date (YYYY-MM-DD) instead of today.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Evaluate and report without writing anything.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every selected adjustment ends applied/skipped, 1 when any ends failed.
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$cycleOption = $input->getOption('cycle');
		$cycle = is_string($cycleOption) === true ? trim($cycleOption) : '';
		if ($cycle === '') {
			$output->writeln('<error>--cycle is verplicht.</error>');
			return 1;
		}

		$dateOption = $input->getOption('date');
		$date = (is_string($dateOption) === true && trim($dateOption) !== '') ? trim($dateOption) : null;
		$dryRun = ((bool)$input->getOption('dry-run')) === true;

		$results = $this->service->effectuateCycle($cycle, $date, $dryRun);

		$output->writeln('<info>Hrmq compensation-cycle effectuation</info>' . ($dryRun === true ? ' <comment>(dry-run)</comment>' : ''));

		if ($results === []) {
			$output->writeln('  geen aanpassingen gevonden voor cyclus ' . $cycle . '.');
			return 0;
		}

		$failed = 0;
		foreach ($results as $result) {
			$status = (string)$result['status'];
			$line = sprintf('  aanpassing %s: %s — %s', (string)($result['adjustmentId'] ?? 'onbekend'), $status, (string)$result['message']);

			if ($status === 'failed') {
				$failed++;
				$output->writeln('<error>' . $line . '</error>');
				continue;
			}

			$output->writeln($line);
		}

		$output->writeln(sprintf('  %d aanpassing(en) verwerkt, %d mislukt.', count($results), $failed));

		return $failed > 0 ? 1 : 0;
	}//end execute()

}//end class
