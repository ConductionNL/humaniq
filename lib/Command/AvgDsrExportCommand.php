<?php

/**
 * AVG DSR Export Command
 *
 * `occ hrmq:avg:export --employee <id> --as-user <admin-uid> --right
 * inzage|portabiliteit [--dsr-request-id <id>]` -- the CLI mirror of Art 15
 * inzage / Art 20 portabiliteit (avg-dsr design.md D2/D3): `--as-user`
 * establishes the privileged session `DsarService::assertPrivileged()`
 * requires BEFORE any `AvgDsrService`/`DsarService` call
 * (`PrivilegedSessionResolver`, design.md D3), then
 * `AvgDsrService::exportForSubject()` calls `findObjectsForSubject()` exactly
 * once and renders it for whichever right was requested.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-003
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\AvgDsrService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command backing the AVG inzage/portabiliteit export.
 */
class AvgDsrExportCommand extends Command
{


    /**
     * @param AvgDsrService             $service         The DSR orchestration service.
     * @param PrivilegedSessionResolver $sessionResolver The shared --as-user session establishment mechanism.
     */
    public function __construct(
        private readonly AvgDsrService $service,
        private readonly PrivilegedSessionResolver $sessionResolver,
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     *
     * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-003
     */
    protected function configure(): void
    {
        $this->setName('hrmq:avg:export')
            ->setDescription('Export the AVG data-subject-rights inzage/portabiliteit overview for one employee (Art 15 / Art 20 -- one findObjectsForSubject() call, rendered two ways).')
            ->addOption('employee', null, InputOption::VALUE_REQUIRED, 'The Employee id whose data is being exported.')
            ->addOption('as-user', null, InputOption::VALUE_REQUIRED, 'The Nextcloud administrator uid establishing the privileged DSAR session.')
            ->addOption('right', null, InputOption::VALUE_REQUIRED, 'inzage or portabiliteit.', 'inzage')
            ->addOption('dsr-request-id', null, InputOption::VALUE_OPTIONAL, 'Optional DsrRequest id to record this export outcome against.');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 on success, 1 on a controlled refusal (never an uncaught throw).
     *
     * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-003
     * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $employeeId = trim((string) $input->getOption('employee'));
        if ($employeeId === '') {
            $output->writeln('<error>--employee is verplicht.</error>');
            return 1;
        }

        $right = (string) $input->getOption('right');
        if (in_array($right, ['inzage', 'portabiliteit'], true) === false) {
            $output->writeln('<error>--right moet "inzage" of "portabiliteit" zijn.</error>');
            return 1;
        }

        // Privileged-session establishment BEFORE any AvgDsrService/DsarService
        // call (REQ-DSR-004, design.md D3): an unknown/non-admin --as-user is
        // refused here, with DsarService never invoked.
        $sessionError = $this->sessionResolver->establish((string) $input->getOption('as-user'));
        if ($sessionError !== null) {
            $output->writeln('<error>'.$sessionError.'</error>');
            return 1;
        }

        $dsrRequestIdOption = $input->getOption('dsr-request-id');
        $dsrRequestId       = (is_string($dsrRequestIdOption) === true && trim($dsrRequestIdOption) !== '') ? trim($dsrRequestIdOption) : null;

        // Defense-in-depth (design.md D3 step 4): a RuntimeException from
        // assertPrivileged() (e.g. a stale race after step above) is still
        // caught here rather than reaching the caller as an uncaught throw.
        try {
            $result = $this->service->exportForSubject($employeeId, $right, $dsrRequestId);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return 1;
        }

        $output->writeln('<info>Hrmq AVG-export ('.$right.')</info>');
        $output->writeln('  aantal objecten: '.(int) ($result['count'] ?? 0));

        foreach ((array) ($result['objects'] ?? []) as $entry) {
            $object = (isset($entry['object']) === true) ? (array) $entry['object'] : (array) $entry;
            $self   = (array) ($object['@self'] ?? []);
            $output->writeln('  - '.(string) ($self['schema'] ?? '?').' / '.(string) ($object['id'] ?? '?'));
        }

        return 0;

    }//end execute()


}//end class
