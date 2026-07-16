<?php

/**
 * Expense Extract Receipt Command
 *
 * `occ hrmq:expense:extract-receipt [--expense <id>]` -- the MVP trigger for
 * receipt-ocr (design.md D7): with no options, processes the backlog of every
 * Expense with a non-empty `receiptFile` and no active (`pending`/`extracted`)
 * `ReceiptExtraction`; `--expense <id>` narrows processing to that one
 * Expense (a receiptFile-less Expense yields a single `failed` outcome).
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
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\ReceiptExtractionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that extracts receipt fields via docudesk and prefills empty
 * Expense fields.
 */
class ExpenseExtractReceiptCommand extends Command
{

    /**
     * Outcome statuses that count as a failure for the command's exit code.
     *
     * @var string[]
     */
    private const FAILURE_STATUSES = ['failed'];


    /**
     * @param ReceiptExtractionService $service The receipt-extraction service.
     */
    public function __construct(
        private readonly ReceiptExtractionService $service,
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('hrmq:expense:extract-receipt')
            ->setDescription(
                'Extract receipt fields via docudesk and prefill empty Expense fields '
                .'(default: backlog of Expenses with a receipt and no active extraction).'
            )
            ->addOption('expense', null, InputOption::VALUE_REQUIRED, 'Restrict to one Expense id.');

    }//end configure()


    /**
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 when every attempt ends extracted/already-extracted/skipped-no-docudesk, 1 when any ends failed.
     *
     * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-006
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expenseOption = $input->getOption('expense');
        $expenseId     = (is_string($expenseOption) === true && trim($expenseOption) !== '') ? trim($expenseOption) : null;

        $results = $this->service->backlog($expenseId, null);

        $output->writeln('<info>Hrmq receipt-extractie</info>');

        if ($results === []) {
            $output->writeln('  geen declaraties geselecteerd voor de backlog.');
            return 0;
        }

        $failed = 0;
        foreach ($results as $result) {
            $status = (string) $result['status'];
            $line   = sprintf(
                '  expense %s: %s — %s',
                (string) $result['expenseId'],
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
