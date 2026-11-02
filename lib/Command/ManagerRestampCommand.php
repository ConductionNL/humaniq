<?php

/**
 * Humaniq Manager Restamp Command
 *
 * `occ humaniq:manager:restamp [--dry-run]`: rewrites an outdated
 * `managerUserId` stamp on open records, the same pass the
 * RestampOpenManagerRecords repair step runs. With `--dry-run` it only
 * counts what it would change. Prints counts per schema.
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
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\ManagerStampRepair;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that restamps the manager on open records on demand.
 *
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */
class ManagerRestampCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param ManagerStampRepair $repair The restamp pass.
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	public function __construct(
		private readonly ManagerStampRepair $repair,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Name, description and the dry-run option.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	protected function configure(): void {
		$this->setName('humaniq:manager:restamp')
			->setDescription('Rewrite an outdated manager stamp on open records (idempotent).')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the records that would change, without writing.');

	}//end configure()

	/**
	 * Run the pass and print the counts per schema.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ($input->getOption('dry-run') === true);
		$report = $this->repair->run($dryRun);

		$output->writeln($dryRun === true ? '<info>Manager restamp (dry run, nothing written)</info>' : '<info>Manager restamp</info>');
		$label = $dryRun === true ? 'wouldRestamp' : 'restamped';
		foreach ($report as $schema => $counts) {
			$output->writeln(sprintf('  %-22s inspected=%d open=%d %s=%d', $schema, $counts['inspected'], $counts['open'], $label, $counts['changed']));
		}

		return 0;
	}//end execute()

}//end class
