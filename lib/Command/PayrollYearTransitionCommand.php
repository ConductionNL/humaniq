<?php

/**
 * Payroll Year Transition Command
 *
 * `occ hrmq:payroll:year-transition --year YYYY` -- the annual-roll preflight
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
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Payroll\TaxTables;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command: the year-transition preflight (data-only roll, no engine-state change).
 */
class PayrollYearTransitionCommand extends Command
{


    /**
     * @return void
     *
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-006
     */
    protected function configure(): void
    {
        $this->setName('hrmq:payroll:year-transition')
            ->setDescription('Preflight for the annual tax-year roll: asserts the new nl-YYYY.json table exists and reports the data-only, period-derived design.')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'The tax year being rolled to (YYYY).');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 when the new table exists, 1 when it is missing or --year is invalid.
     *
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-006
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $year = trim((string) $input->getOption('year'));
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            $output->writeln('<error>--year is verplicht (JJJJ).</error>');
            return 1;
        }

        $tableId = 'nl-'.$year;

        if (in_array($tableId, TaxTables::availableIds(), true) === false) {
            $output->writeln('<error>Jaarovergang-preflight FAILED: '.$tableId.'.json ontbreekt onder lib/Standards/tables/ -- de rol naar '.$year.' mag pas plaatsvinden nadat dit tabelbestand is aangeleverd.</error>');
            return 1;
        }

        $output->writeln('<info>Hrmq jaarovergang-preflight</info>');
        $output->writeln(sprintf('  jaar              : %s', $year));
        $output->writeln(sprintf('  tabelbestand      : %s.json — aanwezig', $tableId));
        $output->writeln('  ontwerp           : geen mutabele "actief belastingjaar"-instelling -- elke loonrun leidt zijn tabel-id af uit zijn eigen periode (nl-{jaar van periode}).');
        $output->writeln('  immutable-stamp   : een reeds berekende run (engineVersion/calculatedAt gestempeld, status != draft) wordt NOOIT herberekend of naar dit nieuwe jaar herwezen.');
        $output->writeln('  resultaat         : de rol is data-only -- runs voor '.$year.'-MM periodes gebruiken '.$tableId.'.json automatisch; er is geen engine-status gewijzigd door dit commando.');

        return 0;

    }//end execute()


}//end class
