<?php

/**
 * Documents Generate Command
 *
 * `occ humaniq:documents:generate [--type <t>] [--employee <id>] [--period
 * <YYYY-MM>] [--year <YYYY>]` -- the MVP trigger for humaniq-docudesk-documents
 * (design.md D7), widened by payslip-pdf-docudesk (design.md D5): with no
 * options, processes the `nl-contract-schriftelijk` backlog (every permanent,
 * written EmploymentContract without an active arbeidsovereenkomst
 * document). A letter documentType other than arbeidsovereenkomst has no
 * backlog semantics and requires `--employee`. `--type loonstrook` has real
 * backlog semantics -- every Payslip lacking an active loonstrook document,
 * optionally narrowed by `--period`/`--employee`. `--type jaaropgaaf`
 * REQUIRES `--year` and aggregates-then-renders per employee with payslips
 * in that year. No automatic lifecycle hook exists yet -- this command is
 * run on operator demand (or via the EmploymentContractDetail/PayslipDetail
 * page action, for a single subject) until a lifecycle hook lands.
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
 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-007
 * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\HrDocumentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that generates standard HR documents via docudesk.
 */
class DocumentsGenerateCommand extends Command {

	/**
	 * Valid `--type` values (GeneratedDocument.documentType enum).
	 *
	 * @var string[]
	 */
	private const DOCUMENT_TYPES = [
		'arbeidsovereenkomst',
		'aanbiedingsbrief',
		'werkgeversverklaring',
		'getuigschrift',
		'loonstrook',
		'jaaropgaaf',
	];

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
	 * Declare the command name, description and CLI options.
	 *
	 * @spec exclude Symfony Console plumbing — declares only this command's name, description and options; the generation behaviour those options drive is specified at openspec/specs/humaniq-docudesk-documents/spec.md#REQ-HDD-007 and openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003, both cited on execute() below.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:documents:generate')
			->setDescription('Generate standard HR documents via docudesk (default: backlog of permanent written contracts missing an arbeidsovereenkomst).')
			->addOption('type', null, InputOption::VALUE_REQUIRED, 'Document type (arbeidsovereenkomst|aanbiedingsbrief|werkgeversverklaring|getuigschrift|loonstrook|jaaropgaaf).')
			->addOption('employee', null, InputOption::VALUE_REQUIRED, 'Restrict to one Employee id (required for employee-level letter types).')
			->addOption('period', null, InputOption::VALUE_REQUIRED, 'Restrict the loonstrook backlog to one YYYY-MM period (--type loonstrook only).')
			->addOption('year', null, InputOption::VALUE_REQUIRED, 'The year to aggregate/render (--type jaaropgaaf only; required for that type).');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every attempt ends generated/already-generated/skipped-no-docudesk, 1 when any ends failed/usage-error.
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-007
	 * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$typeOption = $input->getOption('type');
		$type = (is_string($typeOption) === true && trim($typeOption) !== '') ? trim($typeOption) : null;

		if ($type !== null && in_array($type, self::DOCUMENT_TYPES, true) === false) {
			$output->writeln('<error>Onbekend documenttype "' . $type . '".</error>');
			return 1;
		}

		$employeeOption = $input->getOption('employee');
		$employeeId = (is_string($employeeOption) === true && trim($employeeOption) !== '') ? trim($employeeOption) : null;

		$periodOption = $input->getOption('period');
		$period = (is_string($periodOption) === true && trim($periodOption) !== '') ? trim($periodOption) : null;

		$yearOption = $input->getOption('year');
		$year = (is_string($yearOption) === true && trim($yearOption) !== '') ? trim($yearOption) : null;

		$results = $this->service->generateBacklog($type, $employeeId, $period, $year);

		$output->writeln('<info>Humaniq documentgeneratie</info>');

		if ($results === []) {
			$output->writeln('  geen contracten geselecteerd voor de backlog.');
			return 0;
		}

		$failed = 0;
		foreach ($results as $result) {
			$status = (string)$result['status'];
			$contractBit = $result['contractId'] !== null ? (' / contract ' . $result['contractId']) : '';
			$line = sprintf(
				'  employee %s%s (%s): %s — %s',
				(string)$result['employeeId'],
				$contractBit,
				(string)$result['documentType'],
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
