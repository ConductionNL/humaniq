<?php

/**
 * Wage Tax Remit Command
 *
 * occ humaniq:wagetax:remit [--period YYYY-MM]: hands the confirmed wage tax
 * return of each payable payroll run into shillinq as a draft payable to the
 * Belastingdienst (payroll-wage-tax-remittance-shillinq D7).
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\WageTaxRemittanceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command for the wage tax remittance hand-off.
 *
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */
class WageTaxRemitCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param WageTaxRemittanceService $service The hand-off.
	 */
	public function __construct(
		private readonly WageTaxRemittanceService $service,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the name and the period option.
	 *
	 * @spec exclude Symfony Console plumbing: declares the name and option only; the behaviour is specified on execute() below.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:wagetax:remit')
			->setDescription('Put the confirmed wage tax return of each payable payroll run into shillinq as a draft payable to the Belastingdienst.')
			->addOption('period', null, InputOption::VALUE_REQUIRED, 'Only runs of this wage period (YYYY-MM).');
	}//end configure()

	/**
	 * Run the hand-off and print one line per run.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 1 when any hand-off failed, else 0.
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$option = $input->getOption('period');
		$period = null;
		if (is_string($option) === true && trim($option) !== '') {
			$period = trim($option);
		}

		$failed = 0;
		foreach ($this->service->processPayableRuns(period: $period) as $outcome) {
			$line = sprintf('  run %s: %s, %s', (string)$outcome['runId'], (string)$outcome['status'], (string)$outcome['message']);
			if ($outcome['status'] === 'failed') {
				$failed++;
				$output->writeln('<error>' . $line . '</error>');
				continue;
			}

			$output->writeln($line);
		}

		if ($failed > 0) {
			return 1;
		}

		return 0;
	}//end execute()
}//end class
