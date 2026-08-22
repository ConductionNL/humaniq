<?php

/**
 * AVG DSR Erase Command
 *
 * `occ humaniq:avg:erase --employee <id> --as-user <admin-uid> [--dsr-request-id
 * <id>] [--confirm]` -- the CLI mirror of Art 17 vergetelheid, retention-
 * guarded by OpenRegister's own `Gdpr\DataSubjectRequestService::erase()`
 * (hrmq#99 -- consumed directly, never a bespoke humaniq classification): a bare
 * invocation (no `--confirm`) ALWAYS previews (zero writes, `erase(...,
 * dryRun: true)`) -- when `--dsr-request-id` is given the preview is
 * recorded onto that `DsrRequest`, the evidence `--confirm`'s precondition
 * checks for. `--confirm` REQUIRES `--dsr-request-id` naming a request whose
 * preview already ran; only then does the guarded erase execute
 * (REQ-DSR-005/-006). `--as-user` establishes a session for the guarded
 * service's RBAC/tenant scoping (CLI has no ambient request/session by
 * default).
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\AvgDsrService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command backing the retention-guarded AVG erasure (preview + confirm).
 */
class AvgDsrEraseCommand extends Command {

	/**
	 * @param AvgDsrService $service The DSR orchestration service.
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
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
	 */
	protected function configure(): void {
		$this->setName('humaniq:avg:erase')
			->setDescription('Retention-guarded AVG vergetelheid erasure for one employee -- previews by default; --confirm with a --dsr-request-id whose preview already ran executes.')
			->addOption('employee', null, InputOption::VALUE_REQUIRED, 'The Employee id to erase.')
			->addOption('as-user', null, InputOption::VALUE_REQUIRED, 'The Nextcloud administrator uid establishing the privileged DSAR session.')
			->addOption('dsr-request-id', null, InputOption::VALUE_OPTIONAL, 'The DsrRequest id to record the preview against, or (with --confirm) to execute.')
			->addOption('confirm', null, InputOption::VALUE_NONE, 'Execute the erase (requires --dsr-request-id naming a request whose preview already ran). Omitted -> preview only, zero writes.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on success (including a successful preview), 1 on a controlled refusal.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$employeeId = trim((string)$input->getOption('employee'));
		if ($employeeId === '') {
			$output->writeln('<error>--employee is verplicht.</error>');
			return 1;
		}

		$dsrRequestIdOption = $input->getOption('dsr-request-id');
		$dsrRequestId = (is_string($dsrRequestIdOption) === true && trim($dsrRequestIdOption) !== '') ? trim($dsrRequestIdOption) : null;
		$confirm = (bool)$input->getOption('confirm');

		if ($confirm === true && $dsrRequestId === null) {
			$output->writeln('<error>--confirm vereist --dsr-request-id.</error>');
			return 1;
		}

		// Privileged-session establishment BEFORE any AvgDsrService/DsarService
		// call (REQ-DSR-004, design.md D3): an unknown/non-admin --as-user is
		// refused here, with DsarService never invoked.
		$sessionError = $this->sessionResolver->establish((string)$input->getOption('as-user'));
		if ($sessionError !== null) {
			$output->writeln('<error>' . $sessionError . '</error>');
			return 1;
		}

		try {
			if ($confirm === false) {
				return $this->runPreview($output, $employeeId, $dsrRequestId);
			}

			return $this->runExecute($output, $employeeId, (string)$dsrRequestId);
		} catch (\RuntimeException $e) {
			// Defense-in-depth (design.md D3 step 4): a RuntimeException from
			// assertPrivileged() is still caught rather than reaching the
			// caller as an uncaught throw.
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

	}//end execute()

	/**
	 * The mandatory preview path -- zero writes to any subject's data object;
	 * when `$dsrRequestId` is given the preview is recorded onto it.
	 *
	 * @param OutputInterface $output Console output.
	 * @param string $employeeId The Employee id.
	 * @param string|null $dsrRequestId Optional DsrRequest id to record the preview against.
	 *
	 * @return int Always 0 (a successful preview is not an error).
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
	 */
	private function runPreview(OutputInterface $output, string $employeeId, ?string $dsrRequestId): int {
		$preview = $this->service->previewErasure($employeeId, $dsrRequestId);

		$output->writeln('<info>Humaniq AVG-verwijdering — voorbeeld (geen schrijfacties)</info>');
		$output->writeln('  zou verwijderd worden: ' . count($preview['wouldErase']));
		foreach ($preview['retained'] as $ref) {
			$output->writeln(
				sprintf(
					'  - %s: %s',
					(string)($ref['uuid'] ?? ''),
					(string)($ref['reason'] ?? '')
				)
			);
		}

		$output->writeln('  retained (OpenRegister legal-hold / immutable archival status): ' . count($preview['retained']));

		return 0;
	}//end runPreview()

	/**
	 * The confirmed execute path -- refused (controlled, non-zero exit) when
	 * the precondition is unmet; otherwise runs the retention-guarded erase.
	 *
	 * @param OutputInterface $output Console output.
	 * @param string $employeeId The Employee id.
	 * @param string $dsrRequestId The DsrRequest id whose preview already ran.
	 *
	 * @return int 0 when the erase ran (regardless of a partial failure list -- see the printed outcome), 1 when refused.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
	 */
	private function runExecute(OutputInterface $output, string $employeeId, string $dsrRequestId): int {
		$outcome = $this->service->eraseSubject($employeeId, $dsrRequestId);

		if ((string)($outcome['status'] ?? '') === 'refused') {
			$output->writeln('<error>' . (string)$outcome['message'] . '</error>');
			return 1;
		}

		$output->writeln('<info>Humaniq AVG-verwijdering — uitgevoerd</info>');
		$output->writeln('  verwijderd: ' . count((array)$outcome['erased']));
		$output->writeln('  retained (OpenRegister legal-hold / immutable archival status): ' . count((array)$outcome['retained']));
		$output->writeln('  mislukt: ' . count((array)$outcome['failed']));

		return ((string)$outcome['status'] === 'afgewezen') ? 1 : 0;
	}//end runExecute()

}//end class
