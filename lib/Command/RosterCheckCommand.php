<?php

/**
 * Roster Check Command
 *
 * `occ humaniq:roster:check --roster ID | --period YYYY-Www [--administration ADM]`
 * — the on-demand corpus audit over one roster's RosterAssignments (rostering
 * MVP design D5): resolves the roster(s) + their assignments through
 * `RosterCheckService` and runs the RuleEngine over exactly that set —
 * regardless of publish status, so a `concept` roster can be validated
 * BEFORE publishing — printing every violation and exiting non-zero on any
 * MANDATORY violation, 0 otherwise (the `humaniq:rules:audit` exit-code
 * convention).
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\RosterCheckService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that audits a roster (by id or period) against the ATW corpus.
 */
class RosterCheckCommand extends Command {

	/**
	 * @param RosterCheckService $rosterCheckService The on-demand roster ATW auditor.
	 */
	public function __construct(
		private readonly RosterCheckService $rosterCheckService,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
	 */
	protected function configure(): void {
		$this->setName('humaniq:roster:check')
			->setDescription('Audit one Roster (by id or period) and its RosterAssignments against the Arbeidstijdenwet corpus checks, regardless of publish status.')
			->addOption('roster', null, InputOption::VALUE_REQUIRED, 'Roster id.')
			->addOption('period', null, InputOption::VALUE_REQUIRED, 'Planning period (YYYY-Www or YYYY-MM).')
			->addOption('administration', null, InputOption::VALUE_REQUIRED, 'Only rosters of this administration (with --period).')
			->addOption('jurisdiction', null, InputOption::VALUE_REQUIRED, 'Jurisdiction context (ISO alpha-2)', 'NL');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no mandatory violation exists, 1 otherwise (or on invalid input / no roster found).
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rosterId = self::trimmedOption(input: $input, name: 'roster');
		$period = self::trimmedOption(input: $input, name: 'period');

		if ($rosterId === '' && $period === '') {
			$output->writeln('<error>--roster of --period is verplicht.</error>');
			return 1;
		}

		$jurisdiction = ['jurisdiction' => (string)$input->getOption('jurisdiction')];

		$report = $this->runCheck($input, $rosterId, $period, $jurisdiction);

		$output->writeln('<info>Humaniq roster check</info>');

		return $this->printReport(output: $output, report: $report);
	}//end execute()

	/**
	 * A string option, trimmed; '' when it is absent or not a string.
	 *
	 * @param InputInterface $input Console input.
	 * @param string $name The option name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
	 */
	private static function trimmedOption(InputInterface $input, string $name): string {
		$value = $input->getOption($name);

		return (is_string($value) === true) ? trim($value) : '';
	}//end trimmedOption()

	/**
	 * Print a check report and answer the exit code: 1 when nothing could be
	 * checked or a mandatory rule is broken, 0 otherwise.
	 *
	 * @param OutputInterface $output Console output.
	 * @param array<string, mixed> $report The check report.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
	 */
	private function printReport(OutputInterface $output, array $report): int {
		// A check that could not read anything is NOT a check that found
		// nothing. Without this branch both print "rosters gecontroleerd: 0"
		// and the operator reads a compliant estate off a register that is not
		// on the instance (ConductionNL/openregister#3579).
		if (($report['registerResolved'] ?? true) === false) {
			$output->writeln('  <error>' . (string)($report['error'] ?? 'Het humaniq-register is niet gevonden op deze instance.') . '</error>');
			$output->writeln('  <error>Er is NIETS gecontroleerd; dit is geen goedkeuring.</error>');
			return 1;
		}

		$output->writeln(sprintf('  rosters gecontroleerd    : %d', $report['rostersChecked']));
		$output->writeln(sprintf('  assignments gecontroleerd: %d', $report['assignmentsChecked']));

		if ($report['rostersChecked'] === 0) {
			$output->writeln('  <comment>geen roster gevonden.</comment>');
			return 1;
		}

		if ($report['violations'] === []) {
			$output->writeln('  <info>geen overtredingen — de roster voldoet aan het ATW-corpus.</info>');
			return 0;
		}

		foreach ($report['violations'] as $violation) {
			self::printViolation(output: $output, violation: $violation);
		}

		$output->writeln(sprintf('  %d overtreding(en), waarvan %d verplicht.', count($report['violations']), $report['mandatoryViolations']));

		return $report['mandatoryViolations'] > 0 ? 1 : 0;
	}//end printReport()

	/**
	 * Print one violation, mandatory ones as errors.
	 *
	 * @param OutputInterface $output Console output.
	 * @param array<string, mixed> $violation The violation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
	 */
	private static function printViolation(OutputInterface $output, array $violation): void {
		$severity = (string)$violation['severity'];
		$line = sprintf('    %s %s [%s] %s: %s', (string)$violation['objectType'], (string)$violation['objectId'], $severity, (string)$violation['ruleId'], (string)$violation['statement']);
		if ($severity === 'mandatory') {
			$output->writeln('<error>' . $line . '</error>');
			return;
		}

		$output->writeln('<comment>' . $line . '</comment>');
	}//end printViolation()

	/**
	 * Run the roster check for whichever selector the caller supplied.
	 *
	 * A non-empty `--roster` wins; otherwise the check runs over `--period`,
	 * optionally narrowed to a single administration.
	 *
	 * @param InputInterface $input Console input.
	 * @param string $rosterId Trimmed `--roster` value ('' when absent).
	 * @param string $period Trimmed `--period` value ('' when absent).
	 * @param array<string, string> $jurisdiction Jurisdiction context passed to the service.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
	 */
	private function runCheck(InputInterface $input, string $rosterId, string $period, array $jurisdiction): array {
		if ($rosterId !== '') {
			return $this->rosterCheckService->checkRoster($rosterId, $jurisdiction);
		}

		$administrationOption = $input->getOption('administration');
		$administration = (is_string($administrationOption) === true && trim($administrationOption) !== '') ? trim($administrationOption) : null;

		return $this->rosterCheckService->checkPeriod($period, $administration, $jurisdiction);
	}//end runCheck()

}//end class
