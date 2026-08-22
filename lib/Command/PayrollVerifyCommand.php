<?php

/**
 * Payroll Verify Command
 *
 * `occ humaniq:payroll:verify --period YYYY-MM [--administration ADM]` — the
 * run-scoped corpus audit (payroll-core-engine design.md D7): resolves the
 * period's PayrollRun(s) + their engine payslips and runs the RuleEngine over
 * exactly that object set, printing every violation and exiting non-zero on
 * any MANDATORY violation, 0 otherwise (the `humaniq:rules:audit` exit-code
 * convention). A computed run is audited by the same corpus that audits
 * hand-entered data — the engine has no private truth.
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\RuleAuditService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that audits one period's payroll run(s) against the corpus.
 */
class PayrollVerifyCommand extends Command {

	/**
	 * @param RuleAuditService $auditService The run-scoped compliance auditor.
	 */
	public function __construct(
		private readonly RuleAuditService $auditService,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	protected function configure(): void {
		$this->setName('humaniq:payroll:verify')
			->setDescription('Audit one wage period\'s PayrollRun(s) + their payslips against the machine-checkable rule corpus (run-scoped).')
			->addOption('period', null, InputOption::VALUE_REQUIRED, 'Wage period (YYYY-MM).')
			->addOption('administration', null, InputOption::VALUE_REQUIRED, 'Only runs of this administration.')
			->addOption('jurisdiction', null, InputOption::VALUE_REQUIRED, 'Jurisdiction context (ISO alpha-2)', 'NL');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no mandatory violation exists, 1 otherwise.
	 *
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$periodOption = $input->getOption('period');
		$period = (is_string($periodOption) === true) ? trim($periodOption) : '';
		if ($period === '') {
			$output->writeln('<error>--period is verplicht (JJJJ-MM).</error>');
			return 1;
		}

		$administrationOption = $input->getOption('administration');
		$administration = (is_string($administrationOption) === true && trim($administrationOption) !== '') ? trim($administrationOption) : null;

		$report = $this->auditService->auditPayrollRunScope(
			$period,
			$administration,
			['jurisdiction' => (string)$input->getOption('jurisdiction')]
		);

		$output->writeln('<info>Humaniq payroll verify</info>');
		$output->writeln(sprintf('  periode           : %s', $period));
		$output->writeln(sprintf('  runs gecontroleerd: %d', $report['runsChecked']));
		$output->writeln(sprintf('  loonstroken       : %d', $report['payslipsChecked']));

		if ($report['runsChecked'] === 0) {
			$output->writeln('  <comment>geen loonruns gevonden voor deze periode.</comment>');
			return 1;
		}

		if ($report['violations'] === []) {
			$output->writeln('  <info>geen overtredingen — de run voldoet aan het corpus.</info>');
			return 0;
		}

		foreach ($report['violations'] as $violation) {
			$line = sprintf(
				'    %s %s [%s] %s: %s',
				(string)$violation['objectType'],
				(string)$violation['objectId'],
				(string)$violation['severity'],
				(string)$violation['ruleId'],
				(string)$violation['statement']
			);

			if ((string)$violation['severity'] === 'mandatory') {
				$output->writeln('<error>' . $line . '</error>');
				continue;
			}

			$output->writeln('<comment>' . $line . '</comment>');
		}

		$output->writeln(sprintf('  %d overtreding(en), waarvan %d verplicht.', count($report['violations']), $report['mandatoryViolations']));

		return $report['mandatoryViolations'] > 0 ? 1 : 0;
	}//end execute()

}//end class
