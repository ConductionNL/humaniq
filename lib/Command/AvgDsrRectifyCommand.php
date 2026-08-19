<?php

/**
 * AVG DSR Rectify Command
 *
 * `occ hrmq:avg:rectify --employee <id> --as-user <admin-uid> --changes
 * <json> --dsr-request-id <id>` -- the CLI mirror of Art 16 rectificatie
 * (avg-dsr design.md D6): `--as-user` establishes the privileged session
 * BEFORE any call (design.md D3), the employee is RBAC-resolved (existence +
 * access, the no-admin-idor guard), then `AvgDsrService::rectifySubjectObject()`
 * calls OpenRegister's guarded `Gdpr\DataSubjectRequestService::rectify()`
 * directly (hrmq#99) -- only an immutable archival status blocks it (a
 * correction does not remove data, so no legal-hold guard applies). A failed
 * rectification is reported (`DsrRequest` -> `afgewezen`) with a non-zero
 * exit, never silently dropped.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Command;

use OCA\Hrmq\Service\AvgDsrService;
use OCA\Hrmq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command backing the Art 16 rectificatie pass-through.
 */
class AvgDsrRectifyCommand extends Command {

	/**
	 * @param AvgDsrService $service The DSR orchestration service.
	 * @param PrivilegedSessionResolver $sessionResolver The shared --as-user session establishment mechanism.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param SettingsService $settingsService The register-slug source.
	 */
	public function __construct(
		private readonly AvgDsrService $service,
		private readonly PrivilegedSessionResolver $sessionResolver,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
	 */
	protected function configure(): void {
		$this->setName('hrmq:avg:rectify')
			->setDescription('Apply an AVG rectificatie (Art 16) directly to one employee\'s object -- no retention guard.')
			->addOption('employee', null, InputOption::VALUE_REQUIRED, 'The Employee id to correct.')
			->addOption('as-user', null, InputOption::VALUE_REQUIRED, 'The Nextcloud administrator uid establishing the privileged DSAR session.')
			->addOption('changes', null, InputOption::VALUE_REQUIRED, 'JSON object of field -> new value to apply.')
			->addOption('dsr-request-id', null, InputOption::VALUE_REQUIRED, 'The DsrRequest id this rectification is recorded against.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on a successful rectification, 1 on a controlled refusal or a failed rectification.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$employeeId = trim((string)$input->getOption('employee'));
		if ($employeeId === '') {
			$output->writeln('<error>--employee is verplicht.</error>');
			return 1;
		}

		$dsrRequestId = trim((string)$input->getOption('dsr-request-id'));
		if ($dsrRequestId === '') {
			$output->writeln('<error>--dsr-request-id is verplicht.</error>');
			return 1;
		}

		$changesJson = (string)$input->getOption('changes');
		$changes = json_decode($changesJson, true);
		if (is_array($changes) === false || $changes === []) {
			$output->writeln('<error>--changes moet een niet-lege JSON-object zijn.</error>');
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

		$resolvedEmployeeId = $this->resolveEmployeeIdentifier($employeeId);
		if ($resolvedEmployeeId === null) {
			$output->writeln('<error>Werknemer niet gevonden.</error>');
			return 1;
		}

		try {
			$result = $this->service->rectifySubjectObject($resolvedEmployeeId, $changes, $dsrRequestId);
		} catch (\RuntimeException $e) {
			// Defense-in-depth (design.md D3 step 4): a RuntimeException from
			// assertPrivileged() is still caught rather than reaching the
			// caller as an uncaught throw.
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		if ($result === null) {
			$output->writeln('<error>Rectificatie mislukt -- object kon niet worden geladen of bijgewerkt.</error>');
			return 1;
		}

		$output->writeln('<info>Hrmq AVG-rectificatie toegepast</info>');
		$output->writeln('  veld(en): ' . implode(', ', array_keys($changes)));

		return 0;
	}//end execute()

	/**
	 * RBAC-resolve the employee (existence + access, the no-admin-idor
	 * guard) and return its id unchanged (hrmq#99: the guarded
	 * `Gdpr\DataSubjectRequestService::rectify()` takes a plain id/uuid
	 * string directly -- no internal-int-id resolution workaround is needed
	 * anymore).
	 *
	 * @param string $employeeId The Employee id.
	 *
	 * @return string|null
	 */
	private function resolveEmployeeIdentifier(string $employeeId): ?string {
		try {
			$entity = $this->objectService()->find(
				id: $employeeId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Employee'
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $employeeId;
	}//end resolveEmployeeIdentifier()

	/**
	 * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

}//end class
