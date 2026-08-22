<?php

/**
 * Offer Request Signature Command
 *
 * `occ humaniq:offer:request-signature --application <id>` -- the occ write
 * trigger for offer-esign (design.md D8), scriptability parity with the
 * sibling docudesk leaves. `--application` is REQUIRED -- unlike the letter
 * backlog, "which candidates are due an offer" is an HR judgement call, not a
 * scan.
 *
 * KNOWN LIMITATION (design.md D5, verified against docudesk HEAD): this
 * command WILL reliably fail with `failed: "No authenticated user"` when run
 * from a genuine `occ` CLI process, because
 * `OCA\DocuDesk\Service\SigningService::createRequest()` throws
 * `RuntimeException('No authenticated user')` whenever
 * `IUserSession::getUser()` is null -- true for every bare CLI invocation.
 * The command is still shipped (scriptability parity, and for a future
 * service-account/impersonation context), but the primary, actually-working
 * write path today is the guarded `ApplicationDetail` manifest action,
 * executed in an authenticated browser session.
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
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\OfferEsignService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that requests an offer-letter + e-signature for one Application.
 */
class OfferRequestSignatureCommand extends Command {

	/**
	 * Outcome statuses that count as a failure for the command's exit code.
	 *
	 * @var string[]
	 */
	private const FAILURE_STATUSES = ['failed', 'usage-error'];

	/**
	 * @param OfferEsignService $service The offer-letter + e-signature service.
	 */
	public function __construct(
		private readonly OfferEsignService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command name, description and CLI options.
	 *
	 * @spec exclude Symfony Console plumbing — declares only this command's name, description and options; the signature-request behaviour those options drive is specified at openspec/specs/offer-esign/spec.md#REQ-OFFR-007, cited on execute() below.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:offer:request-signature')
			->setDescription(
				'Generate the aanbiedingsbrief and raise a docudesk e-signature request for one Application in status "aanbod". '
				. 'KNOWN LIMITATION: docudesk\'s SigningService::createRequest() throws "No authenticated user" when run from a '
				. 'genuine occ CLI process (no Nextcloud user session) -- this command will reliably fail there. The '
				. 'ApplicationDetail manifest action (authenticated browser session) is the primary, actually-working trigger.'
			)
			->addOption('application', null, InputOption::VALUE_REQUIRED, 'The Application id (required -- no backlog semantics).');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when the outcome is requested/already-signed/skipped-no-docudesk, 1 when failed/usage-error.
	 *
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$applicationOption = $input->getOption('application');
		$applicationId = (is_string($applicationOption) === true) ? trim($applicationOption) : '';

		if ($applicationId === '') {
			$output->writeln('<error>--application is verplicht.</error>');
			return 1;
		}

		$result = $this->service->requestSignature($applicationId);

		$status = (string)$result['status'];
		$line = sprintf(
			'application %s: %s — %s',
			(string)$result['applicationId'],
			$status,
			(string)$result['message']
		);

		if (in_array($status, self::FAILURE_STATUSES, true) === true) {
			$output->writeln('<error>' . $line . '</error>');
			return 1;
		}

		$output->writeln($line);

		return 0;
	}//end execute()

}//end class
