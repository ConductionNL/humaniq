<?php

/**
 * Hrmq PayrollRunApprovedGuard
 *
 * OpenRegister lifecycle guard for the PensionFiling `controleren` transition
 * (pension-filing-upa-mvp). It enforces the one precondition the declarative
 * `x-openregister-lifecycle` state machine cannot express on its own: a UPA
 * pension delivery may only progress past review once the PayrollRun it
 * reports on is approved (or further along: posted/paid) — the verified
 * Loket.nl/APG rule that "a pensioenaangifte can only be created once the
 * salary run is approved".
 *
 * Unlike the stateless `NoSelfApprovalGuard`, this guard needs to load the
 * referenced PayrollRun from the register, so it is constructed by the DI
 * container with `ContainerInterface` (lazy `ObjectService` resolution, the
 * `RuleTestDataSeeder`/`RuleAuditService` pattern) and `IAppConfig` (the
 * configured register slug). Guards are read-only per OpenRegister's
 * contract — no stamping happens here.
 *
 * Fails closed: an empty `payrollRunId`, a run that cannot be loaded, or a
 * run in any status other than approved/posted/paid all deny the transition.
 *
 * Referenced from the PensionFiling schema
 * `x-openregister-lifecycle.transitions.controleren.requires`.
 *
 * @category Lifecycle
 * @package  OCA\Hrmq\Lifecycle
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
 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Lifecycle;

use OCA\Hrmq\AppInfo\Application;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Denies the PensionFiling `controleren` transition unless the referenced
 * PayrollRun is approved (or further along).
 *
 * Fails closed: when the reference is empty, dangling, or the run cannot be
 * loaded, the transition is denied rather than allowed on a guess.
 */
final class PayrollRunApprovedGuard implements LifecycleGuardInterface {

	/**
	 * PayrollRun statuses that unblock the `controleren` transition.
	 *
	 * @var string[]
	 */
	private const ALLOWED_STATUSES = ['approved', 'posted', 'paid'];

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Authorise the `controleren` transition by checking the referenced
	 * PayrollRun's approval status.
	 *
	 * @param array<string, mixed> $object The PensionFiling payload at its current state.
	 * @param string $action The transition action ('controleren').
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow when the referenced PayrollRun is
	 *                     approved/posted/paid; deny otherwise (fail-closed).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
	 *  static allow()/deny() factories mandated by OpenRegister's contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId are part of
	 *  the LifecycleGuardInterface signature; the gate depends only on the
	 *  referenced PayrollRun's status, not on who is acting.
	 *
	 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$payrollRunId = trim((string)($object['payrollRunId'] ?? ''));
		if ($payrollRunId === '') {
			return GuardResult::deny(
				'Deze pensioenaangifte verwijst niet naar een loonrun; controleren is geweigerd.'
			);
		}

		try {
			$run = $this->objectService()->find(id: $payrollRunId, register: $this->register(), schema: 'PayrollRun');
		} catch (\Throwable $e) {
			return GuardResult::deny(
				'De gekoppelde loonrun kon niet worden geladen; controleren is geweigerd.'
			);
		}

		if ($run === null) {
			return GuardResult::deny(
				'De gekoppelde loonrun bestaat niet (meer); controleren is geweigerd.'
			);
		}

		$status = (string)($this->toArray($run)['status'] ?? '');
		if (in_array($status, self::ALLOWED_STATUSES, true) === false) {
			return GuardResult::deny(sprintf(
				'De loonrun heeft status "%s"; controleren kan pas nadat de loonrun is goedgekeurd, geboekt of uitbetaald.',
				$status !== '' ? $status : 'onbekend'
			));
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Normalise an ObjectService lookup result (entity or array) to an array.
	 *
	 * @param mixed $run The loaded PayrollRun, or an unexpected shape.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $run): array {
		if (is_array($run) === true) {
			return $run;
		}

		if (is_object($run) === true && method_exists($run, 'jsonSerialize') === true) {
			return (array)$run->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. class_exists() rather
		// than SettingsService::isOpenRegisterAvailable(), because this guard
		// does not inject SettingsService and adding a constructor dependency
		// to a lifecycle guard purely to ask a yes/no question is the wrong
		// trade. It answers the same question the container would otherwise
		// have answered fatally.
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'hrmq');
		return $register === '' ? 'hrmq' : $register;
	}//end register()

}//end class
