<?php

/**
 * Payroll Year Transition Command
 *
 * `occ humaniq:payroll:year-transition --year YYYY` -- the annual-roll preflight
 * (design.md D6): there is deliberately no mutable "active tax year" global to
 * repoint -- `PayrollRunService` derives each run's tax-year table from its
 * own period (`nl-{substr(period, 0, 4)}`), and a generated run's
 * `engineVersion`/`calculatedAt` stamp together with the non-`draft` recompute
 * refusal already make that stamp immutable (payroll-core-engine). Rolling to
 * a new year is therefore DATA-ONLY: ship `lib/Standards/tables/nl-YYYY.json`
 * and runs for `YYYY-MM` periods pick it up automatically. This command
 * changes no engine state -- it only asserts the new table exists (failing
 * loudly otherwise) and reports the safe, data-only procedure.
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
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-006
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\YearTransitionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command: the year-transition preflight (data-only roll, no engine-state change).
 */
class PayrollYearTransitionCommand extends Command {

	/**
	 * @param YearTransitionService $yearTransition The year resolution the Payroll packs page shows too.
	 */
	public function __construct(
		private readonly YearTransitionService $yearTransition,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-006
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
	 */
	protected function configure(): void {
		$this->setName('humaniq:payroll:year-transition')
			->setDescription('Preflight for the annual tax-year roll: which pack and tables the year resolves to, where each came from, and whether the pack passes its own golden vectors.')
			->addOption('year', null, InputOption::VALUE_REQUIRED, 'The tax year being rolled to (YYYY).')
			->addOption('jurisdiction', null, InputOption::VALUE_REQUIRED, 'The jurisdiction (ISO 3166-1 alpha-2).', 'NL');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when the year resolves and the self-test passes, 1 otherwise or when --year is invalid.
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-006
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$year = trim((string)$input->getOption('year'));
		if (preg_match('/^\d{4}$/', $year) !== 1) {
			$output->writeln('<error>--year is verplicht (JJJJ).</error>');
			return 1;
		}

		$answer = $this->yearTransition->resolution((string)$input->getOption('jurisdiction'), (int)$year);
		if ($answer['resolves'] !== true) {
			$output->writeln('<error>Jaarovergang-preflight FAILED: ' . (string)$answer['message'] . ' Upload a pack with its tables on the Payroll packs page first.</error>');
			return 1;
		}

		$output->writeln('<info>Humaniq jaarovergang-preflight</info>');
		$output->writeln(sprintf('  jaar              : %s', $year));
		$output->writeln(sprintf('  pack              : %s (%s)', (string)$answer['engineVersion'], (string)$answer['packOrigin']));
		$output->writeln(sprintf('  tables            : %s (%s)', (string)$answer['tablesId'], (string)$answer['tablesOrigin']));
		$output->writeln(sprintf('  self-test         : %s', $answer['selfTest']['passed'] === true ? 'passed' : 'FAILED: ' . (string)$answer['selfTest']['message']));
		foreach ((array)$answer['provenance'] as $leaf) {
			$output->writeln(sprintf('  unconfirmed       : %s', (string)$leaf));
		}

		$output->writeln('  immutable-stamp   : een reeds berekende run (engineVersion gestempeld, status != draft) wordt NOOIT herberekend of naar dit jaar herwezen.');

		return ($answer['selfTest']['passed'] === true ? 0 : 1);
	}//end execute()

}//end class
