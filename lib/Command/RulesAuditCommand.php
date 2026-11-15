<?php

/**
 * Rules Audit Command
 *
 * `occ humaniq:rules:audit` — runs RuleAuditService against the register's HR/labour
 * objects and prints the compliance posture: rule-corpus coverage, objects
 * checked / compliant, violations by severity, and the most-violated rules.
 * Read-only; it reports whether humaniq complies, it does not change data.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\RuleAuditService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that audits HR/labour data against the rule corpus.
 */
class RulesAuditCommand extends Command {

	/**
	 * @param RuleAuditService $auditService The compliance auditor.
	 */
	public function __construct(
		private readonly RuleAuditService $auditService,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command name, description and CLI options.
	 *
	 * @spec exclude Symfony Console plumbing — declares only this command's name, description and the --jurisdiction option; the audit behaviour that option drives is specified at openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005, cited on execute() below.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:rules:audit')
			->setDescription(
				'Audit HR/labour data against the machine-checkable rule corpus. '
				. 'Exits 1 when any mandatory-severity violation is found, 0 otherwise, '
				. 'so CI and ops can gate on it.'
			)
			->addOption('jurisdiction', null, InputOption::VALUE_REQUIRED, 'Jurisdiction context (ISO alpha-2)', 'NL');

	}//end configure()

	/**
	 * Run the read-only rule audit and print the coverage report.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005
	 *
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->auditService->audit(['jurisdiction' => (string)$input->getOption('jurisdiction')]);

		self::writeCoverage($output, $report);
		self::writeObjects($output, $report);
		self::writeTopViolatedRules($output, $report);

		return self::exitCode($output, $report);
	}//end execute()

	/**
	 * Exit 1, saying so, when any mandatory rule is violated; else 0.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $report The audit report.
	 *
	 * @return int
	 */
	private static function exitCode(OutputInterface $output, array $report): int {
		// The exit code is the point. `RuleEngine::hasMandatory()` exists to say
		// "a guard must block here" and had no production caller at all: its only
		// call sites were two test assertions. Without this the audit is a wall
		// of text nobody can gate on, and a mandatory breach reads the same as a
		// clean run to any script.
		//
		// Only MANDATORY counts. Conditional and recommended are advisory by
		// definition, and failing on them would make the gate unusable and
		// therefore ignored.
		$mandatory = (int)($report['violationsBySeverity']['mandatory'] ?? 0);
		if ($mandatory > 0) {
			$output->writeln('');
			$output->writeln(sprintf(
				'<error>%d mandatory violation(s): exiting 1.</error>',
				$mandatory
			));
			return 1;
		}

		return 0;

	}//end exitCode()

	/**
	 * Write the heading and the catalogue coverage.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $report The audit report.
	 *
	 * @return void
	 */
	private static function writeCoverage(OutputInterface $output, array $report): void {
		$output->writeln('<info>Humaniq rule-compliance audit</info>');
		$output->writeln(sprintf('  catalogue version : %s', $report['catalogueVersion']));
		$output->writeln(sprintf('  corpus rules      : %d (machine-checkable: %d)', $report['corpusTotal'], $report['machineCheckable']));
		$output->writeln(sprintf('  enforceable today : %d (%.1f%% of machine-checkable)', $report['enforceableRules'], $report['coveragePct']));
		$output->writeln('');

	}//end writeCoverage()

	/**
	 * Write the object counts, the violations per severity and one line per object type.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $report The audit report.
	 *
	 * @return void
	 */
	private static function writeObjects(OutputInterface $output, array $report): void {
		$output->writeln(sprintf('  objects checked   : %d', $report['objectsChecked']));
		$output->writeln(sprintf('  compliant         : %d', $report['objectsCompliant']));
		$output->writeln(sprintf('  with violations   : %d', $report['objectsWithViolations']));
		$output->writeln(sprintf(
			'  violations        : %d mandatory / %d conditional / %d recommended',
			$report['violationsBySeverity']['mandatory'] ?? 0,
			$report['violationsBySeverity']['conditional'] ?? 0,
			$report['violationsBySeverity']['recommended'] ?? 0
		));

		self::writeTypes($output, $report);

	}//end writeObjects()

	/**
	 * Write one line per object type.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $report The audit report.
	 *
	 * @return void
	 */
	private static function writeTypes(OutputInterface $output, array $report): void {
		foreach ($report['types'] as $type => $stat) {
			$output->writeln(sprintf(
				'    %-16s checked=%d compliant=%d withViolations=%d',
				$type,
				$stat['checked'],
				$stat['compliant'],
				$stat['withViolations']
			));
		}

	}//end writeTypes()

	/**
	 * Write the most violated rules, when there are any.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $report The audit report.
	 *
	 * @return void
	 */
	private static function writeTopViolatedRules(OutputInterface $output, array $report): void {
		if (empty($report['topViolatedRules']) === true) {
			return;
		}

		$output->writeln('');
		$output->writeln('  top violated rules:');
		foreach ($report['topViolatedRules'] as $row) {
			$output->writeln(sprintf('    %-34s %d', $row['ruleId'], $row['count']));
		}

	}//end writeTopViolatedRules()

}//end class
