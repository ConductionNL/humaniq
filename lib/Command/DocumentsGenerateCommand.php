<?php

/**
 * Documents Generate Command
 *
 * `occ hrmq:documents:generate [--type <t>] [--employee <id>]` -- the MVP
 * trigger for hrmq-docudesk-documents (design.md D7): with no options,
 * processes the `nl-contract-schriftelijk` backlog (every permanent, written
 * EmploymentContract without an active arbeidsovereenkomst document). A
 * documentType other than arbeidsovereenkomst has no backlog semantics and
 * requires `--employee`. No automatic lifecycle hook exists yet -- this
 * command is run on operator demand (or via the EmploymentContractDetail page
 * action, for a single contract) until a lifecycle hook lands.
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
 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\HrDocumentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that generates standard HR documents via docudesk.
 */
class DocumentsGenerateCommand extends Command
{

    /**
     * Valid `--type` values (GeneratedDocument.documentType enum).
     *
     * @var string[]
     */
    private const DOCUMENT_TYPES = ['arbeidsovereenkomst', 'aanbiedingsbrief', 'werkgeversverklaring', 'getuigschrift'];

    /**
     * Outcome statuses that count as a failure for the command's exit code.
     *
     * @var string[]
     */
    private const FAILURE_STATUSES = ['failed', 'usage-error'];


    /**
     * @param HrDocumentService $service The document-generation service.
     */
    public function __construct(
        private readonly HrDocumentService $service,
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('hrmq:documents:generate')
            ->setDescription('Generate standard HR documents via docudesk (default: backlog of permanent written contracts missing an arbeidsovereenkomst).')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Document type (arbeidsovereenkomst|aanbiedingsbrief|werkgeversverklaring|getuigschrift).')
            ->addOption('employee', null, InputOption::VALUE_REQUIRED, 'Restrict to one Employee id (required for non-arbeidsovereenkomst types).');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 when every attempt ends generated/already-generated/skipped-no-docudesk, 1 when any ends failed/usage-error.
     *
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-007
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $typeOption = $input->getOption('type');
        $type       = (is_string($typeOption) === true && trim($typeOption) !== '') ? trim($typeOption) : null;

        if ($type !== null && in_array($type, self::DOCUMENT_TYPES, true) === false) {
            $output->writeln('<error>Onbekend documenttype "'.$type.'".</error>');
            return 1;
        }

        $employeeOption = $input->getOption('employee');
        $employeeId     = (is_string($employeeOption) === true && trim($employeeOption) !== '') ? trim($employeeOption) : null;

        $results = $this->service->generateBacklog($type, $employeeId);

        $output->writeln('<info>Hrmq documentgeneratie</info>');

        if ($results === []) {
            $output->writeln('  geen contracten geselecteerd voor de backlog.');
            return 0;
        }

        $failed = 0;
        foreach ($results as $result) {
            $status      = (string) $result['status'];
            $contractBit = $result['contractId'] !== null ? (' / contract '.$result['contractId']) : '';
            $line        = sprintf(
                '  employee %s%s (%s): %s — %s',
                (string) $result['employeeId'],
                $contractBit,
                (string) $result['documentType'],
                $status,
                (string) $result['message']
            );

            if (in_array($status, self::FAILURE_STATUSES, true) === true) {
                $failed++;
                $output->writeln('<error>'.$line.'</error>');
                continue;
            }

            $output->writeln($line);
        }

        $output->writeln(sprintf('  %d poging(en) verwerkt, %d mislukt.', count($results), $failed));

        return $failed > 0 ? 1 : 0;

    }//end execute()


}//end class
