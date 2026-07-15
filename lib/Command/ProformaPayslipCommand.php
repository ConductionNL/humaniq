<?php

/**
 * Proforma Payslip Command
 *
 * `occ hrmq:payroll:proforma --gross 3800 [--table wit|groen]
 * [--date-of-birth YYYY-MM-DD] [--parttime 1.0] [--bijzonder 0]
 * [--period YYYY-MM] [--aof laag|hoog] [--whk 1.52]` — the support-facing
 * mirror of `POST /api/payroll/proforma` (proforma-payslip design.md D5):
 * calls the same `ProformaPayslipService::simulate()` and prints the full
 * gross-to-net breakdown. Persists nothing; gives support a way to reproduce
 * a net figure with no browser and no DB access.
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
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\ProformaPayslipService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that runs the persist-nothing pro-forma simulation from CLI flags.
 */
class ProformaPayslipCommand extends Command
{


    /**
     * @param ProformaPayslipService $service The stateless pro-forma simulation service.
     */
    public function __construct(
        private readonly ProformaPayslipService $service,
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     *
     * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-003
     */
    protected function configure(): void
    {
        $this->setName('hrmq:payroll:proforma')
            ->setDescription('Simuleer een loonstrook (bruto-naar-netto) zonder iets op te slaan.')
            ->addOption('gross', null, InputOption::VALUE_REQUIRED, 'Bruto maandsalaris (euro).')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Loonheffingstabel: wit of groen (default wit).')
            ->addOption('date-of-birth', null, InputOption::VALUE_REQUIRED, 'Geboortedatum (JJJJ-MM-DD); onbekend = onder AOW-leeftijd.')
            ->addOption('parttime', null, InputOption::VALUE_REQUIRED, 'Deeltijdfactor, bv. 0.8 (default 1.0).')
            ->addOption('bijzonder', null, InputOption::VALUE_REQUIRED, 'Eenmalige bijzondere beloning (euro, default 0).')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Loonperiode (JJJJ-MM, default huidige maand).')
            ->addOption('aof', null, InputOption::VALUE_REQUIRED, 'Aof-tariefklasse: laag of hoog (default werkgeversinstelling).')
            ->addOption('whk', null, InputOption::VALUE_REQUIRED, 'Whk-percentage (default werkgeversinstelling).');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 on a successful simulation, 1 on malformed input.
     *
     * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-003
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $breakdown = $this->service->simulate(
                [
                    'gross'               => $input->getOption('gross'),
                    'table'               => $input->getOption('table'),
                    'loonheffingskorting' => true,
                    'dateOfBirth'         => $input->getOption('date-of-birth'),
                    'period'              => $input->getOption('period'),
                    'parttime'            => $input->getOption('parttime'),
                    'bijzonder'           => $input->getOption('bijzonder'),
                    'aof'                 => $input->getOption('aof'),
                    'whk'                 => $input->getOption('whk'),
                ]
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return 1;
        }

        $output->writeln('<info>Hrmq pro-forma loonstrook (niets opgeslagen)</info>');
        $output->writeln(sprintf('  periode                 : %s', (string) $breakdown['input']['period']));
        $output->writeln(sprintf('  tabel                   : %s', (string) $breakdown['input']['table']));
        $output->writeln(sprintf('  bruto                   : %.2f', (float) $breakdown['grossPay']));
        $output->writeln(sprintf('  loonheffing             : %.2f', (float) $breakdown['loonheffing']));
        $output->writeln(sprintf('  arbeidskorting          : %.2f', (float) $breakdown['arbeidskorting']));
        $output->writeln(sprintf('  volksverzekeringen      : %.2f', (float) $breakdown['volksverzekeringen']));
        $output->writeln(sprintf('  zvw                     : %.2f', (float) $breakdown['zvw']));
        $output->writeln(sprintf('  werknemersverzekeringen : %.2f', (float) $breakdown['werknemersverzekeringen']));
        $output->writeln(sprintf('  werkgeverslasten        : %.2f', (float) $breakdown['employerCharges']));
        $output->writeln(sprintf('  vakantiegeldreservering : %.2f', (float) $breakdown['vakantiegeldReserved']));
        $output->writeln(sprintf('  netto                   : %.2f', (float) $breakdown['nettoPay']));
        $output->writeln(sprintf('  toegepast tarief        : %.2f%%', (float) $breakdown['appliedTaxRate']));

        if ((float) $breakdown['input']['bijzonder'] > 0.0) {
            $output->writeln('  <comment>let op: bijzondere beloning is een combinedLoon-schatting, geen wettelijk bijzonder tarief.</comment>');
        }

        return 0;

    }//end execute()


}//end class
