<?php

/**
 * Payroll Run Command
 *
 * `occ humaniq:payroll:run --period YYYY-MM [--administration ADM]
 * [--recalculate]` — the payroll-core-engine MVP trigger (design.md D4):
 * creates (or, with --recalculate, regenerates) the draft PayrollRun for the
 * period, generates one engine Payslip per active NL employee whose contract
 * covers the period, and prints the per-employee outcome — every computed
 * payslip AND every skipped employee with its reason (a run is never silently
 * partial). Approval stays a human act; this command never writes any status
 * but the initial `draft`.
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\PayrollRunService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that creates/recalculates draft payroll runs via the engine.
 */
class PayrollRunCommand extends Command {

	/**
	 * @param PayrollRunService $service The draft-run generation service.
	 */
	public function __construct(
		private readonly PayrollRunService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	protected function configure(): void {
		$this->setName('humaniq:payroll:run')
			->setDescription('Create (or with --recalculate regenerate) the draft PayrollRun + engine Payslips for a wage period.')
			->addOption('period', null, InputOption::VALUE_REQUIRED, 'Wage period (YYYY-MM).')
			->addOption('administration', null, InputOption::VALUE_REQUIRED, 'Administration id (defaults to the seed convention ADM-001).')
			->addOption('recalculate', null, InputOption::VALUE_NONE, 'Regenerate an existing draft run in place (draft-only).');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when the run was calculated or already exists, 1 on refusal/failure.
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$periodOption = $input->getOption('period');
		$period = (is_string($periodOption) === true) ? trim($periodOption) : '';
		if ($period === '') {
			$output->writeln('<error>--period is verplicht (JJJJ-MM).</error>');
			return 1;
		}

		$result = $this->service->runFor($period, CommandOptions::trimmed($input, 'administration'), (bool)$input->getOption('recalculate'));

		self::writeRun($output, $result);
		self::writeTotals($output, $result['totals']);

		return in_array((string)$result['status'], ['calculated', 'exists'], true) === true ? 0 : 1;
	}//end execute()

	/**
	 * Write the run's heading, status and one line per computed or skipped employee.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $result The runFor() outcome.
	 *
	 * @return void
	 */
	private static function writeRun(OutputInterface $output, array $result): void {
		$output->writeln('<info>Humaniq payroll run</info>');
		$output->writeln(sprintf('  periode        : %s', (string)$result['period']));
		$output->writeln(sprintf('  administratie  : %s', (string)$result['administrationId']));
		$output->writeln(sprintf('  run            : %s', (string)($result['runId'] ?? 'geen')));
		$output->writeln(sprintf('  status         : %s — %s', (string)$result['status'], (string)$result['message']));

		foreach ((array)$result['computed'] as $line) {
			$output->writeln(sprintf('    berekend     : %s (loonstrook %s)', (string)$line['employee'], (string)$line['payslipId']));
		}

		foreach ((array)$result['skipped'] as $line) {
			$output->writeln(sprintf('    overgeslagen : %s — %s', (string)$line['employee'], (string)$line['reason']));
		}

	}//end writeRun()

	/**
	 * Write the run totals, when the run has them.
	 *
	 * @param OutputInterface $output Console output.
	 * @param mixed           $totals The run totals, or anything else when there are none.
	 *
	 * @return void
	 */
	private static function writeTotals(OutputInterface $output, mixed $totals): void {
		if (is_array($totals) === false) {
			return;
		}

		$output->writeln(sprintf(
			'  totalen        : bruto %.2f / loonheffing %.2f / werkgeverslasten %.2f / netto %.2f',
			(float)$totals['totalGross'],
			(float)$totals['totalLoonheffing'],
			(float)$totals['totalEmployerCharges'],
			(float)$totals['totalNet']
		));

	}//end writeTotals()

}//end class
