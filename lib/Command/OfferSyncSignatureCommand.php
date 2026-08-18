<?php

/**
 * Offer Sync Signature Command
 *
 * `occ hrmq:offer:sync-signature [--application <id>]` -- the read-only poll
 * trigger for offer-esign (design.md D8). Default scope: every Application
 * whose `offerSigningRequestId` is set and `offerSigningStatus` is
 * PENDING/IN_PROGRESS. Unlike `hrmq:offer:request-signature`, this genuinely
 * works from a bare `occ` CLI process -- `SigningService::getRequest()`
 * carries no session guard (design.md D5 point 3), the one piece of the
 * offer-esign lifecycle CLI can honestly own today. NEVER writes
 * `Application.status` or invokes the `aannemen` transition (REQ-OFFR-006).
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
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\OfferEsignService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that polls docudesk signing-request status onto Applications.
 */
class OfferSyncSignatureCommand extends Command {

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
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('hrmq:offer:sync-signature')
			->setDescription(
				'Read-only poll of docudesk signing-request status onto Application.offerSigningStatus '
				. '(default: every Application with an active offerSigningRequestId). Never touches Application.status.'
			)
			->addOption('application', null, InputOption::VALUE_REQUIRED, 'Restrict to one Application id.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every polled Application ends synced/skipped-no-docudesk/not-found, 1 when any ends failed/usage-error.
	 *
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$applicationOption = $input->getOption('application');
		$applicationId = (is_string($applicationOption) === true && trim($applicationOption) !== '') ? trim($applicationOption) : null;

		$results = $this->service->syncSignatureStatus($applicationId);

		$output->writeln('<info>Hrmq offer-signature sync</info>');

		if ($results === []) {
			$output->writeln('  geen sollicitaties met een actieve e-handtekeningaanvraag gevonden.');
			return 0;
		}

		$failed = 0;
		foreach ($results as $result) {
			$status = (string)$result['status'];
			$line = sprintf(
				'  application %s: %s — %s',
				(string)$result['applicationId'],
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

		$output->writeln(sprintf('  %d sollicitatie(s) verwerkt, %d mislukt.', count($results), $failed));

		return $failed > 0 ? 1 : 0;
	}//end execute()

}//end class
