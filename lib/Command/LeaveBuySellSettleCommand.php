<?php

/**
 * Leave Buy/Sell Settle Command
 *
 * `occ hrmq:leave:settle --id TRANSACTION_ID` — the occ entry point for
 * settling one approved LeaveTransaction (leave-buy-sell design.md D4):
 * idempotent (an already-settled transaction is a no-op), refuses
 * non-approved/missing-settlement-period/balance-unresolvable/insufficient-
 * bovenwettelijk transactions, and otherwise adjusts
 * `LeaveBalance.bovenwettelijkHours` and stamps the transaction settled.
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
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\LeaveBuySellSettlementService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that settles one approved LeaveTransaction.
 */
class LeaveBuySellSettleCommand extends Command {

	/**
	 * @param LeaveBuySellSettlementService $service The settlement service.
	 */
	public function __construct(
		private readonly LeaveBuySellSettlementService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('hrmq:leave:settle')
			->setDescription('Settle one approved LeaveTransaction (buy/sell leave hours).')
			->addOption('id', null, InputOption::VALUE_REQUIRED, 'The LeaveTransaction id.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when the outcome ends settled/already-settled, 1 otherwise.
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$idOption = $input->getOption('id');
		$id = is_string($idOption) === true ? trim($idOption) : '';
		if ($id === '') {
			$output->writeln('<error>--id is verplicht.</error>');
			return 1;
		}

		$result = $this->service->settle($id);
		$status = (string)$result['status'];

		$output->writeln('<info>Hrmq leave buy/sell settlement</info>');
		$output->writeln(sprintf('  transactie %s: %s — %s', (string)($result['transactionId'] ?? 'onbekend'), $status, (string)$result['message']));

		return in_array($status, ['settled', 'already-settled'], true) === true ? 0 : 1;
	}//end execute()

}//end class
