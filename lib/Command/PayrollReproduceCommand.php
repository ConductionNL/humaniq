<?php

/**
 * Payroll Reproduce Command
 *
 * `occ hrmq:payroll:reproduce --payslip <uuid>` (audit-trail-payroll,
 * fixing hrmq#98) — the reproducibility verifier: recomputes a sealed
 * Payslip from its own stored `engineInputSnapshot` (never the live
 * Employee/EmploymentContract state) and reports byte-identical match or
 * names the first mismatching component. Exits 0 only on a full match; any
 * mismatch, or nothing-to-reproduce (no snapshot), exits non-zero — never a
 * silent pass.
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
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\PayrollReproduceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that recomputes a sealed Payslip from its stored snapshot and
 * compares it cents-exact against the archived figures.
 */
class PayrollReproduceCommand extends Command
{


    /**
     * @param PayrollReproduceService $service The recompute-and-compare service.
     */
    public function __construct(
        private readonly PayrollReproduceService $service,
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    protected function configure(): void
    {
        $this->setName('hrmq:payroll:reproduce')
            ->setDescription('Recompute a sealed Payslip from its stored engineInputSnapshot and compare cents-exact against the archived figures.')
            ->addOption('payslip', null, InputOption::VALUE_REQUIRED, 'The Payslip id (uuid) to reproduce.');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 when every component reproduces cents-exact, 1 otherwise.
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payslipOption = $input->getOption('payslip');
        $payslipId     = (is_string($payslipOption) === true) ? trim($payslipOption) : '';
        if ($payslipId === '') {
            $output->writeln('<error>--payslip is verplicht (uuid).</error>');
            return 1;
        }

        $result = $this->service->reproduce($payslipId);

        $output->writeln('<info>Hrmq payroll reproduce</info>');
        $output->writeln(sprintf('  loonstrook: %s', $result['payslipId']));
        $output->writeln(sprintf('  status    : %s', $result['status']));
        $output->writeln(sprintf('  bericht   : %s', $result['message']));

        if ($result['status'] === 'mismatch') {
            foreach ($result['mismatches'] as $mismatch) {
                $output->writeln(
                    sprintf(
                        '    <error>%s: gearchiveerd=%s, herberekend=%s</error>',
                        (string) $mismatch['component'],
                        json_encode($mismatch['stored']),
                        json_encode($mismatch['recomputed'])
                    )
                );
            }
        }

        return ($result['status'] === 'reproduced') ? 0 : 1;

    }//end execute()


}//end class
