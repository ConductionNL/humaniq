<?php

/**
 * Rules Seed Test-Data Command
 *
 * `occ humaniq:rules:seed-testdata` — idempotently backfills the local TEST data so
 * it satisfies the enforced HR/labour rules (creates compliant sample objects for
 * empty types and backfills provider-declared field defaults on existing rows).
 * Run after a clean-env reset so a fresh environment audits at 100%.
 * Test/dev utility only.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\RuleTestDataSeeder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that seeds compliant local test data.
 */
class RulesSeedTestDataCommand extends Command {
	/**
	 * @param RuleTestDataSeeder $seeder The test-data seeder.
	 */
	public function __construct(
		private readonly RuleTestDataSeeder $seeder,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command name and description.
	 *
	 * @spec exclude Symfony Console plumbing — declares only this command's name and description and takes no options; the idempotent seeding behaviour is specified at openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006, cited on execute() below.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:rules:seed-testdata')
			->setDescription('Backfill local test data to satisfy the enforced rules (idempotent; test/dev only).');

	}//end configure()

	/**
	 * Backfill local test data to a rule-compliant state, idempotently.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 *
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->seeder->seed();

		$output->writeln('<info>Humaniq rules test-data seeder</info>');
		$output->writeln(sprintf('  provider objects created : %d', ($result['providerObjectsCreated'] ?? 0)));
		$output->writeln(sprintf('  provider fields backfilled : %d', ($result['providerFieldsAdded'] ?? 0)));
		$output->writeln(sprintf('  already compliant          : %d', $result['alreadyCompliant']));
		$output->writeln('Run <info>occ humaniq:rules:audit</info> to confirm 100% compliance.');

		return 0;
	}//end execute()
}//end class
