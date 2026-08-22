<?php

/**
 * WKR Assess Command
 *
 * `occ humaniq:wkr:assess --administration ADM --year YYYY [--all]` — the WKR
 * administration-level roll-up trigger (wkr-administration design.md D6):
 * computes/persists the idempotent WkrAssessment for one (administration,
 * year) pair and prints the outcome (fiscale loonsom, vrije ruimte, used,
 * remaining, excess, eindheffing due). `--all` iterates every distinct
 * (administrationId, year) pair found across the payslips instead.
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
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\WkrService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that computes and persists the WKR vrije-ruimte assessment.
 */
class WkrAssessCommand extends Command {

	/**
	 * @param WkrService $service The idempotent cross-object roll-up service.
	 */
	public function __construct(
		private readonly WkrService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
	 */
	protected function configure(): void {
		$this->setName('humaniq:wkr:assess')
			->setDescription('Compute and persist the werkkostenregeling (WKR) vrije-ruimte assessment for one administration/year, or every distinct pair found across the payslips with --all.')
			->addOption('administration', null, InputOption::VALUE_REQUIRED, 'The administration to assess.')
			->addOption('year', null, InputOption::VALUE_REQUIRED, 'The fiscal year to assess.')
			->addOption('all', null, InputOption::VALUE_NONE, 'Assess every distinct (administration, year) pair found across the payslips instead of a single pair.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on success, 1 on failure.
	 *
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ((bool)$input->getOption('all') === true) {
			$outcomes = $this->service->assessAll();
			if ($outcomes === []) {
				$output->writeln('<comment>Geen (administratie, jaar)-paren gevonden in de loonstroken.</comment>');
				return 0;
			}

			$failed = 0;
			foreach ($outcomes as $outcome) {
				if ($this->printOutcome($output, $outcome) === false) {
					$failed++;
				}
			}

			return ($failed > 0) ? 1 : 0;
		}//end if

		$administrationOption = $input->getOption('administration');
		$administrationId = (is_string($administrationOption) === true) ? trim($administrationOption) : '';
		if ($administrationId === '') {
			$output->writeln('<error>--administration is verplicht (of gebruik --all).</error>');
			return 1;
		}

		$yearOption = $input->getOption('year');
		$year = (is_numeric($yearOption) === true) ? (int)$yearOption : 0;
		if ($year <= 0) {
			$output->writeln('<error>--year is verplicht en moet een geldig jaartal zijn.</error>');
			return 1;
		}

		$outcome = $this->service->assess($administrationId, $year);

		return ($this->printOutcome($output, $outcome) === true) ? 0 : 1;
	}//end execute()

	/**
	 * Print one assessment outcome, returning whether it succeeded.
	 *
	 * @param OutputInterface $output Console output.
	 * @param array<string, mixed> $outcome The service outcome ({status, message, assessment}).
	 *
	 * @return bool
	 */
	private function printOutcome(OutputInterface $output, array $outcome): bool {
		if ((string)$outcome['status'] !== 'ok') {
			$output->writeln('<error>' . (string)$outcome['message'] . '</error>');
			return false;
		}

		$assessment = (array)$outcome['assessment'];

		$output->writeln('<info>Humaniq WKR-beoordeling</info>');
		$output->writeln(sprintf('  administratie      : %s', (string)$assessment['administrationId']));
		$output->writeln(sprintf('  jaar               : %d', (int)$assessment['year']));
		$output->writeln(sprintf('  fiscale loonsom    : %.2f', (float)$assessment['fiscaleLoonsom']));
		$output->writeln(sprintf('  vrije ruimte       : %.2f', (float)$assessment['vrijeRuimte']));
		$output->writeln(sprintf('  vrije ruimte gebruikt: %.2f', (float)$assessment['vrijeRuimteUsed']));
		$output->writeln(sprintf('  vrije ruimte resterend: %.2f', (float)$assessment['vrijeRuimteRemaining']));
		$output->writeln(sprintf('  overschrijding      : %.2f', (float)$assessment['excess']));
		$output->writeln(sprintf('  eindheffing verschuldigd: %.2f', (float)$assessment['eindheffingDue']));
		$output->writeln(sprintf('  status             : %s', (string)$assessment['status']));
		$output->writeln('');

		return true;
	}//end printOutcome()

}//end class
