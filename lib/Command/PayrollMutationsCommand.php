<?php

/**
 * Payroll Mutations Command
 *
 * `occ humaniq:payroll:mutations --to <runId> [--from <runId>] [--persist]` —
 * the run-to-run payroll diff trigger (payroll-mutation-reports design.md
 * D6): prints the per-employee entered/left/changed table with headline
 * component deltas and the run-level roll-up totals. `--from` omitted
 * auto-resolves the prior period of the same administration (design.md D4);
 * `--persist` upserts the idempotent `PayrollMutationReport` (design.md D7).
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
 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\PayrollMutationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that prints (and optionally persists) a run-to-run payroll diff.
 */
class PayrollMutationsCommand extends Command {

	/**
	 * @param PayrollMutationService $service The pure diff service.
	 */
	public function __construct(
		private readonly PayrollMutationService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-004
	 */
	protected function configure(): void {
		$this->setName('humaniq:payroll:mutations')
			->setDescription('Print (and optionally persist) the payroll mutation report between two runs (--from omitted auto-resolves the prior period).')
			->addOption('to', null, InputOption::VALUE_REQUIRED, 'The PayrollRun being reviewed.')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'The baseline PayrollRun (omit to auto-resolve the prior period of the same administration).')
			->addOption('persist', null, InputOption::VALUE_NONE, 'Upsert the idempotent PayrollMutationReport, keyed (fromRunId, toRunId).');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on success, 1 on refusal/failure.
	 *
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-004
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$toRunId = self::toRunIdOption($input);
		if ($toRunId === '') {
			$output->writeln('<error>--to is verplicht (de te beoordelen loonrun-id).</error>');
			return 1;
		}

		$outcome = $this->service->diff($toRunId, CommandOptions::trimmed($input, 'from'));
		if ((string)$outcome['status'] !== 'ok') {
			$output->writeln('<error>' . (string)$outcome['message'] . '</error>');
			return 1;
		}

		$report = $outcome['report'];
		self::writeReport($output, $report);

		return ((bool)$input->getOption('persist') === true) ? $this->persist($output, $report) : 0;
	}//end execute()

	/**
	 * The trimmed --to run id, '' when it is not given.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return string
	 */
	private static function toRunIdOption(InputInterface $input): string {
		$toOption = $input->getOption('to');

		return (is_string($toOption) === true) ? trim($toOption) : '';

	}//end toRunIdOption()

	/**
	 * Write the runs compared, every changed employee line and the totals.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $report The mutation report.
	 *
	 * @return void
	 */
	private static function writeReport(OutputInterface $output, array $report): void {
		$output->writeln('<info>Humaniq payroll mutations</info>');
		$output->writeln(sprintf('  van periode    : %s (%s)', (string)($report['fromPeriod'] ?? '(geen — eerste run)'), (string)($report['fromRunId'] ?? '-')));
		$output->writeln(sprintf('  naar periode   : %s (%s)', (string)$report['toPeriod'], (string)$report['toRunId']));
		$output->writeln(sprintf('  administratie  : %s', (string)$report['administrationId']));
		$output->writeln('');

		foreach ((array)$report['lines'] as $line) {
			if ((string)$line['classification'] !== 'unchanged') {
				self::writeLine($output, $line);
			}
		}

		$output->writeln('');
		self::writeTotals($output, $report);

	}//end writeReport()

	/**
	 * Write one employee's mutation line.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $line   The employee's line.
	 *
	 * @return void
	 */
	private static function writeLine(OutputInterface $output, array $line): void {
		$output->writeln(sprintf(
			'  %-10s %-36s bruto %+.2f  netto %+.2f  loonheffing %+.2f  werkgeverslasten %+.2f',
			(string)$line['classification'],
			(string)$line['employeeId'],
			(float)$line['grossPayDelta'],
			(float)$line['nettoPayDelta'],
			(float)$line['loonheffingDelta'],
			(float)$line['employerCostDelta']
		));

	}//end writeLine()

	/**
	 * Write the head counts and the deltas of the report.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $report The mutation report.
	 *
	 * @return void
	 */
	private static function writeTotals(OutputInterface $output, array $report): void {
		$output->writeln(sprintf('  ingestroomd    : %d', (int)$report['enteredCount']));
		$output->writeln(sprintf('  uitgestroomd   : %d', (int)$report['leftCount']));
		$output->writeln(sprintf('  gewijzigd      : %d', (int)$report['changedCount']));
		$output->writeln(sprintf('  ongewijzigd    : %d', (int)$report['unchangedCount']));
		$output->writeln(sprintf('  brutoΔ         : %+.2f', (float)$report['grossDelta']));
		$output->writeln(sprintf('  nettoΔ         : %+.2f', (float)$report['netDelta']));
		$output->writeln(sprintf('  loonheffingΔ   : %+.2f', (float)$report['loonheffingDelta']));
		$output->writeln(sprintf('  werkgeverslastenΔ: %+.2f', (float)$report['employerCostDelta']));
		$output->writeln(sprintf('  totale loonkostenΔ: %+.2f', (float)$report['totalWageCostDelta']));

	}//end writeTotals()

	/**
	 * Store the report; 1 when the store is refused.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $report The mutation report.
	 *
	 * @return int
	 */
	private function persist(OutputInterface $output, array $report): int {
		$persisted = $this->service->persist($report);
		if ((string)$persisted['status'] !== 'ok') {
			$output->writeln('<error>' . (string)$persisted['message'] . '</error>');
			return 1;
		}

		$output->writeln(sprintf('  opgeslagen als : %s', (string)$persisted['reportId']));
		return 0;

	}//end persist()

}//end class
