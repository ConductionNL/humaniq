<?php

/**
 * Humaniq RightToWorkGuard
 *
 * OpenRegister lifecycle guard on the Onboarding `gereed_melden` and `starten`
 * transitions (people-dossier-completeness D5). A new hire cannot be marked
 * ready for the first working day, nor started, without a passing
 * right-to-work check dated on or before the case's start date.
 *
 * The newest check decides, so a failed check cannot be lifted by an older
 * pass; only a new check with new evidence lifts it. The guard also re-applies
 * RightToWorkService's rule to the facts the check stored, so a check saved by
 * hand as passed whose own facts fail the rule is refused: the result field
 * alone is never trusted.
 *
 * Fails closed: an unreadable start date or employee denies.
 *
 * Referenced from the Onboarding schema
 * `x-openregister-lifecycle.transitions.{gereed_melden,starten}.requires`.
 *
 * @category Lifecycle
 * @package  OCA\Humaniq\Lifecycle
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
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RightToWorkService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Refuses the first working day without a passing right-to-work check.
 *
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-004
 */
final class RightToWorkGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Register reads, past RBAC: the guard must see every check.
	 * @param RightToWorkService $rule The stated rule, re-applied to the stored facts.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly RightToWorkService $rule,
	) {

	}//end __construct()

	/**
	 * Allow only with a passing, newest check dated on or before startDate.
	 *
	 * @param array<string, mixed> $object The Onboarding payload.
	 * @param string $action gereed_melden or starten.
	 * @param string $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only static allow()/deny() factories.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId belong to the interface; the rule does not depend on them.
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-004
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$employeeId = trim((string)($object['employeeId'] ?? ''));
		$startDate = substr(trim((string)($object['startDate'] ?? '')), 0, 10);
		if ($employeeId === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) {
			return GuardResult::deny('Deze onboarding heeft geen medewerker of geen geldige startdatum, dus de right-to-work-controle kan niet worden beoordeeld.');
		}

		$latest = $this->latest(checks: $this->gateway->findFiltered('RightToWorkCheck', ['employeeId' => $employeeId]));
		if ($latest === null) {
			return GuardResult::deny('Er is nog geen right-to-work-controle (identiteit en recht op arbeid) voor deze medewerker. Voer de controle uit op de onboarding.');
		}

		$checkedOn = substr((string)($latest['checkedOn'] ?? ''), 0, 10);
		if ($checkedOn === '' || $checkedOn > $startDate) {
			return GuardResult::deny(sprintf('De right-to-work-controle moet op of voor de startdatum (%s) zijn gedaan.', $startDate));
		}

		if (($latest['result'] ?? '') !== RightToWorkService::RESULT_PASS) {
			return GuardResult::deny('De laatste right-to-work-controle is mislukt: ' . (string)($latest['reason'] ?? '') . ' Alleen een nieuwe controle met nieuw bewijs heft dit op.');
		}

		$again = $this->rule->decide(array_merge($latest, ['startDate' => $startDate, 'mrz' => null]));
		if ($again['result'] !== RightToWorkService::RESULT_PASS) {
			return GuardResult::deny('De right-to-work-controle staat als geslaagd, maar de gegevens voldoen niet aan de regel: ' . (string)$again['reason']);
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * The newest check by checkedOn.
	 *
	 * @param array<int, array<string, mixed>> $checks The employee's checks.
	 *
	 * @return array<string, mixed>|null
	 */
	private function latest(array $checks): ?array {
		$latest = null;
		foreach ($checks as $check) {
			if ($latest === null || (string)($check['checkedOn'] ?? '') >= (string)($latest['checkedOn'] ?? '')) {
				$latest = $check;
			}
		}

		return $latest;
	}//end latest()

}//end class
