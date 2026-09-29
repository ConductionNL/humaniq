<?php

/**
 * Documents Sync Signatures Command
 *
 * `occ humaniq:documents:sync-signatures` reads the status of every running
 * signing request on a generated HR document (or one, with --document) and
 * writes it back; a completed employment contract marks its contract as
 * written (people-esign-hr-documents D4, D5). filinq's getRequest() needs no
 * signed-in session, so this runs from cron or a shell.
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
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\HrDocumentSigningService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sync the signing status of generated HR documents.
 *
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
 */
class DocumentsSyncSignaturesCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param HrDocumentSigningService $service Reads and writes the statuses.
	 */
	public function __construct(
		private readonly HrDocumentSigningService $service,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @spec exclude Symfony Console plumbing; the sync it drives is specified at openspec/specs/hr-document-signing/spec.md#REQ-HDS-003, cited on execute().
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:documents:sync-signatures')
			->setDescription('Read the status of running signing requests on generated HR documents and write it back; a signed employment contract marks its contract as written.')
			->addOption('document', null, InputOption::VALUE_REQUIRED, 'Only this generated document.');
	}//end configure()

	/**
	 * Run the sync.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0, or 1 when a request could not be read.
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$option = $input->getOption('document');
		$results = $this->service->syncSignatures(is_string($option) === true ? trim($option) : null);
		if ($results === []) {
			$output->writeln('No generated documents with a running signing request.');
			return 0;
		}

		$failed = 0;
		foreach ($results as $result) {
			$output->writeln(sprintf('  %s: %s (%s)', (string)$result['signingRequestId'], $result['status'], $result['message']));
			if ($result['status'] === 'not-found') {
				$failed++;
			}
		}

		return ($failed > 0 ? 1 : 0);
	}//end execute()

}//end class
