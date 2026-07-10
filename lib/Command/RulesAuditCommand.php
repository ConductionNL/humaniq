<?php

/**
 * Rules Audit Command
 *
 * `occ hrmq:rules:audit` — runs RuleAuditService against the register's HR/labour
 * objects and prints the compliance posture: rule-corpus coverage, objects
 * checked / compliant, violations by severity, and the most-violated rules.
 * Read-only; it reports whether hrmq complies, it does not change data.
 *
 * @category Command
 * @package  OCA\Hrmq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hrm-rule-audit/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\RuleAuditService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that audits HR/labour data against the rule corpus.
 */
class RulesAuditCommand extends Command
{


    /**
     * @param RuleAuditService $auditService The compliance auditor.
     */
    public function __construct(
        private readonly RuleAuditService $auditService,
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('hrmq:rules:audit')
            ->setDescription(
                'Audit HR/labour data against the machine-checkable rule corpus. '
                . 'Exits non-zero when any mandatory-severity violation is found, '
                . 'so this command can be wired into CI/ops as an actionable gate.'
            )
            ->addOption('jurisdiction', null, InputOption::VALUE_REQUIRED, 'Jurisdiction context (ISO alpha-2)', 'NL');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->auditService->audit(['jurisdiction' => (string) $input->getOption('jurisdiction')]);

        $output->writeln('<info>Hrmq rule-compliance audit</info>');
        $output->writeln(sprintf('  catalogue version : %s', $report['catalogueVersion']));
        $output->writeln(sprintf('  corpus rules      : %d (machine-checkable: %d)', $report['corpusTotal'], $report['machineCheckable']));
        $output->writeln(sprintf('  enforceable today : %d (%.1f%% of machine-checkable)', $report['enforceableRules'], $report['coveragePct']));
        $output->writeln('');
        $output->writeln(sprintf('  objects checked   : %d', $report['objectsChecked']));
        $output->writeln(sprintf('  compliant         : %d', $report['objectsCompliant']));
        $output->writeln(sprintf('  with violations   : %d', $report['objectsWithViolations']));
        $output->writeln(sprintf(
            '  violations        : %d mandatory / %d conditional / %d recommended',
            $report['violationsBySeverity']['mandatory'] ?? 0,
            $report['violationsBySeverity']['conditional'] ?? 0,
            $report['violationsBySeverity']['recommended'] ?? 0
        ));

        foreach ($report['types'] as $type => $stat) {
            $output->writeln(sprintf(
                '    %-16s checked=%d compliant=%d withViolations=%d',
                $type,
                $stat['checked'],
                $stat['compliant'],
                $stat['withViolations']
            ));
        }

        if (empty($report['topViolatedRules']) === false) {
            $output->writeln('');
            $output->writeln('  top violated rules:');
            foreach ($report['topViolatedRules'] as $row) {
                $output->writeln(sprintf('    %-34s %d', $row['ruleId'], $row['count']));
            }
        }

        $mandatoryViolations = $report['violationsBySeverity']['mandatory'] ?? 0;
        if ($mandatoryViolations > 0) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<error>%d mandatory violation(s) found — exiting non-zero.</error>',
                $mandatoryViolations
            ));

            return 1;
        }

        return 0;

    }//end execute()


}//end class
