<?php

/**
 * Expense Extract Receipt Command
 *
 * `occ humaniq:expense:extract-receipt --as-user <admin-uid> [--expense <id>]`
 * -- the MVP trigger for receipt-ocr (design.md D7): with no `--expense`,
 * processes the backlog of every Expense with a non-empty `receiptFile` and
 * no active (`pending`/`extracted`) `ReceiptExtraction`; `--expense <id>`
 * narrows processing to that one Expense (a receiptFile-less Expense yields
 * a single `failed` outcome).
 *
 * Live-verified 2026-07-16 (docudesk 0.0.37): this command established NO
 * Nextcloud user session, but docudesk's `DocumentService::resolveFile()`
 * (the collaborator `runExtraction()` calls) reads
 * `IUserSession::getUser()` -- null on a genuine `occ` CLI process -- and
 * OpenRegister's `saveObject()` RBAC then rejects the resulting `Anonymous`
 * actor, so the command could never work regardless of `receiptFile`
 * content. `--as-user` establishes the SAME privileged-session mechanism
 * the three `humaniq:avg:*` commands already use (`PrivilegedSessionResolver`,
 * avg-dsr design.md D3) BEFORE any docudesk/OR call: an unknown or
 * non-admin uid is refused with a one-line controlled message and
 * `ReceiptExtractionService::backlog()` is never invoked.
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
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\ReceiptExtractionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that extracts receipt fields via docudesk and prefills empty
 * Expense fields.
 */
class ExpenseExtractReceiptCommand extends Command {

	/**
	 * Outcome statuses that count as a failure for the command's exit code.
	 *
	 * @var string[]
	 */
	private const FAILURE_STATUSES = ['failed'];

	/**
	 * @param ReceiptExtractionService $service The receipt-extraction service.
	 * @param PrivilegedSessionResolver $sessionResolver The shared --as-user session establishment mechanism (the humaniq:avg:* precedent).
	 */
	public function __construct(
		private readonly ReceiptExtractionService $service,
		private readonly PrivilegedSessionResolver $sessionResolver,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command name, description and CLI options.
	 *
	 * @spec exclude Symfony Console plumbing — declares only this command's name, description and options; the receipt-extraction behaviour those options drive is specified at openspec/specs/receipt-ocr/spec.md#REQ-RCPT-006, cited on execute() below.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:expense:extract-receipt')
			->setDescription(
				'Extract receipt fields via docudesk and prefill empty Expense fields '
				. '(default: backlog of Expenses with a receipt and no active extraction).'
			)
			->addOption('expense', null, InputOption::VALUE_REQUIRED, 'Restrict to one Expense id.')
			->addOption('as-user', null, InputOption::VALUE_REQUIRED, 'The Nextcloud administrator uid establishing the privileged session docudesk/OpenRegister require.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every attempt ends extracted/already-extracted/skipped-no-docudesk, 1 when any ends failed or --as-user is refused.
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Privileged-session establishment BEFORE any ReceiptExtractionService/
		// docudesk/OpenRegister call (the humaniq:avg:* precedent, avg-dsr
		// design.md D3-D4): an unknown/non-admin --as-user is refused here,
		// with the service never invoked.
		$asUser = trim((string)$input->getOption('as-user'));
		$sessionError = $this->sessionResolver->establish($asUser);
		if ($sessionError !== null) {
			$output->writeln('<error>' . $sessionError . '</error>');
			return 1;
		}

		$expenseOption = $input->getOption('expense');
		$expenseId = (is_string($expenseOption) === true && trim($expenseOption) !== '') ? trim($expenseOption) : null;

		$results = $this->service->backlog($expenseId, $asUser);

		$output->writeln('<info>Humaniq receipt-extractie</info>');

		if ($results === []) {
			$output->writeln('  geen declaraties geselecteerd voor de backlog.');
			return 0;
		}

		$failed = 0;
		foreach ($results as $result) {
			$status = (string)$result['status'];
			$line = sprintf(
				'  expense %s: %s — %s',
				(string)$result['expenseId'],
				$status,
				(string)$result['message']
			);

			if (in_array($status, self::FAILURE_STATUSES, true) === true) {
				$failed++;
				$output->writeln('<error>' . $line . '</error>');
				continue;
			}

			$output->writeln($line);
		}

		$output->writeln(sprintf('  %d poging(en) verwerkt, %d mislukt.', count($results), $failed));

		return $failed > 0 ? 1 : 0;
	}//end execute()

}//end class
