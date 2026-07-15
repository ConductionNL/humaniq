<?php

/**
 * Roster Check Command
 *
 * `occ hrmq:roster:check --roster ID | --period YYYY-Www [--administration ADM]`
 * — the on-demand corpus audit over one roster's RosterAssignments (rostering
 * MVP design D5): resolves the roster(s) + their assignments through
 * `RosterCheckService` and runs the RuleEngine over exactly that set —
 * regardless of publish status, so a `concept` roster can be validated
 * BEFORE publishing — printing every violation and exiting non-zero on any
 * MANDATORY violation, 0 otherwise (the `hrmq:rules:audit` exit-code
 * convention).
 *
 * @category Command
 * @package  OCA\Hrmq\Command
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
 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\RosterCheckService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that audits a roster (by id or period) against the ATW corpus.
 */
class RosterCheckCommand extends Command
{


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
     * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
     */
    protected function configure(): void
    {
        $this->setName('hrmq:roster:check')
            ->setDescription('Audit one Roster (by id or period) and its RosterAssignments against the Arbeidstijdenwet corpus checks, regardless of publish status.')
            ->addOption('roster', null, InputOption::VALUE_REQUIRED, 'Roster id.')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Planning period (YYYY-Www or YYYY-MM).')
            ->addOption('administration', null, InputOption::VALUE_REQUIRED, 'Only rosters of this administration (with --period).')
            ->addOption('jurisdiction', null, InputOption::VALUE_REQUIRED, 'Jurisdiction context (ISO alpha-2)', 'NL');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 when no mandatory violation exists, 1 otherwise (or on invalid input / no roster found).
     *
     * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rosterOption = $input->getOption('roster');
        $rosterId     = (is_string($rosterOption) === true) ? trim($rosterOption) : '';

        $periodOption = $input->getOption('period');
        $period       = (is_string($periodOption) === true) ? trim($periodOption) : '';

        if ($rosterId === '' && $period === '') {
            $output->writeln('<error>--roster of --period is verplicht.</error>');
            return 1;
        }

        $jurisdiction = ['jurisdiction' => (string) $input->getOption('jurisdiction')];

        if ($rosterId !== '') {
            $report = $this->rosterCheckService->checkRoster($rosterId, $jurisdiction);
        } else {
            $administrationOption = $input->getOption('administration');
            $administration       = (is_string($administrationOption) === true && trim($administrationOption) !== '') ? trim($administrationOption) : null;
            $report               = $this->rosterCheckService->checkPeriod($period, $administration, $jurisdiction);
        }

        $output->writeln('<info>Hrmq roster check</info>');
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
            $line = sprintf(
                '    %s %s [%s] %s: %s',
                (string) $violation['objectType'],
                (string) $violation['objectId'],
                (string) $violation['severity'],
                (string) $violation['ruleId'],
                (string) $violation['statement']
            );

            if ((string) $violation['severity'] === 'mandatory') {
                $output->writeln('<error>'.$line.'</error>');
                continue;
            }

            $output->writeln('<comment>'.$line.'</comment>');
        }

        $output->writeln(sprintf('  %d overtreding(en), waarvan %d verplicht.', count($report['violations']), $report['mandatoryViolations']));

        return $report['mandatoryViolations'] > 0 ? 1 : 0;

    }//end execute()


}//end class
