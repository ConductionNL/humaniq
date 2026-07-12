<?php

/**
 * Net Pay Run Command
 *
 * `occ hrmq:netpay:run [--period YYYY-MM]` — the MVP trigger for
 * payroll-sepa-netpay-shillinq (design.md D8): hands off every payable
 * (approved/posted) PayrollRun's payslips as a draft SEPA PaymentRun into
 * shillinq's PaymentRun register (optionally filtered to one wage period),
 * printing one outcome line per run plus a summary. No automatic lifecycle
 * hook exists yet — PayrollRun transitions are plain data edits today, so
 * this command is run on operator demand until
 * `hrmq-rule-compliance-enforcement` wires guards/events.
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
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\PayrollNetPayService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that hands payable payroll runs off to shillinq as draft SEPA
 * PaymentRuns.
 */
class NetPayRunCommand extends Command
{


    /**
     * @param PayrollNetPayService $service The net-pay service.
     */
    public function __construct(
        private readonly PayrollNetPayService $service,
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('hrmq:netpay:run')
            ->setDescription('Hand off each payable PayrollRun\'s payslips as a draft SEPA PaymentRun into shillinq (MVP trigger; run on operator demand).')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Only process runs for this wage period (YYYY-MM).');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 when every selected run ends created/skipped-no-shillinq, 1 when any ends failed.
     *
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-007
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $periodOption = $input->getOption('period');
        $period       = (is_string($periodOption) === true && trim($periodOption) !== '') ? trim($periodOption) : null;

        $results = $this->service->processPayableRuns($period);

        $output->writeln('<info>Hrmq payroll net pay</info>');

        if ($results === []) {
            $output->writeln('  geen betaalbare loonruns geselecteerd'.($period !== null ? ' voor periode '.$period : '').'.');
            return 0;
        }

        $failed = 0;
        foreach ($results as $result) {
            $status = (string) $result['status'];
            $line   = sprintf('  run %s: %s — %s', (string) $result['runId'], $status, (string) $result['message']);

            if ($status === 'failed') {
                $failed++;
                $output->writeln('<error>'.$line.'</error>');
                continue;
            }

            $output->writeln($line);
        }

        $output->writeln(sprintf('  %d run(s) verwerkt, %d mislukt.', count($results), $failed));

        return $failed > 0 ? 1 : 0;

    }//end execute()


}//end class
