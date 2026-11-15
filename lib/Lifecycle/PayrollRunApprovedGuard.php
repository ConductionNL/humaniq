<?php

/**
 * Humaniq PayrollRunApprovedGuard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @package  OCA\Humaniq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/pension-filing-upa-mvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Support\RegisterSlugLookup;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use OCA\Humaniq\Support\ResolvesObjectServiceByClass;

/**
 * Denies the PensionFiling `controleren` transition unless the referenced
 * PayrollRun is approved (or further along).
 *
 * Fails closed: when the reference is empty, dangling, or the run cannot be
 * loaded, the transition is denied rather than allowed on a guess.
 */
final class PayrollRunApprovedGuard implements LifecycleGuardInterface {

	use ResolvesObjectServiceByClass;

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
	 * @spec openspec/specs/pension-filing-upa-mvp/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$payrollRunId = trim((string)($object['payrollRunId'] ?? ''));
		if ($payrollRunId === '') {
			return GuardResult::deny(
				'Deze pensioenaangifte verwijst niet naar een loonrun; controleren is geweigerd.'
			);
		}

		// An absent register is not "the run is not approved". It is this
		// instance being unable to say where payroll runs live, and the guard
		// denies rather than guessing — fail-CLOSED, the same direction every
		// other branch here takes, but with a message that names the actual
		// cause so an admin can act on it instead of hunting a phantom status.
		$register = $this->registerSlug();
		if ($register === null) {
			return GuardResult::deny(
				'Het humaniq-register is niet gevonden op deze instance, dus de gekoppelde loonrun kan niet '
				. 'worden gecontroleerd; controleren is geweigerd. Voer de humaniq-reparatiestap uit of stel '
				. 'het register in bij de humaniq-instellingen.'
			);
		}

		try {
			$run = $this->objectService()->find(id: $payrollRunId, register: $register, schema: 'PayrollRun');
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
	 * The slug this instance's humaniq register answers to, or null when absent.
	 *
	 * This used to end `return $register === '' ? 'hrmq' : $register;` under a
	 * note saying the `hrmq` fallback was frozen across the rename — while the
	 * `getValueString()` default beside it already said `humaniq`. So the
	 * frozen branch was reachable only for a config value stored as the empty
	 * string, and every ordinary instance took the canonical default instead.
	 * On an instance that has not yet run `MigrateRegisterSlug` the register is
	 * still `hrmq`, the read below matched nothing, and this guard denied a
	 * transition that should have been allowed. See
	 * ConductionNL/openregister#3579.
	 *
	 * @return string|null The slug, or null when this instance carries no
	 *                     humaniq register under any of its known slugs.
	 */
	private function registerSlug(): ?string {
		return (new RegisterSlugLookup($this->container, $this->appConfig))->slugOrNull();
	}//end registerSlug()
}//end class
