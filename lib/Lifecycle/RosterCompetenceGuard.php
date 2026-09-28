<?php

/**
 * Humaniq RosterCompetenceGuard
 *
 * OpenRegister lifecycle guard for the Roster `publiceren` transition
 * (REQ-ROST-C02, humaniq#512). A roster that puts someone on a shift they are
 * not qualified for on that date does not publish. The competence cross-check
 * itself is `RosterCheckService`'s, reused as is, so `occ humaniq:roster:check`
 * and this refusal can never disagree about who is qualified.
 *
 * Only `competence` findings refuse. Working-time findings stay a report: the
 * coordinator's decision on #512 covers the competence rule, not the
 * Arbeidstijdenwet ones.
 *
 * Fails closed: a roster without an id, an unresolvable register, or a roster
 * the check cannot find all deny rather than publish on a guess.
 *
 * Referenced from the Roster schema
 * `x-openregister-lifecycle.transitions.publiceren.requires`.
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\CompetenceCheckService;
use OCA\Humaniq\Service\RosterCheckService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Denies publishing a roster that carries an unqualified assignment.
 *
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
 */
final class RosterCompetenceGuard implements LifecycleGuardInterface {

	/**
	 * How many findings the refusal names before it summarises the rest.
	 *
	 * @var int
	 */
	private const NAMED_FINDINGS = 3;

	/**
	 * @param RosterCheckService $rosterCheck The roster check whose competence findings decide.
	 */
	public function __construct(
		private readonly RosterCheckService $rosterCheck,
	) {

	}//end __construct()

	/**
	 * Authorise the `publiceren` transition.
	 *
	 * @param array<string, mixed> $object The Roster payload at its current state (OpenRegister puts its uuid in `id`).
	 * @param string $action The transition action ('publiceren').
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow when no assignment lacks a required competence; deny otherwise.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
	 *  static allow()/deny() factories mandated by OpenRegister's contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId are part of
	 *  the LifecycleGuardInterface signature; the refusal depends only on the
	 *  roster's assignments, not on who publishes.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$rosterId = trim((string)($object['id'] ?? ($object['@self']['id'] ?? '')));
		if ($rosterId === '') {
			return GuardResult::deny('Dit rooster heeft geen id, dus de bevoegdheden kunnen niet worden gecontroleerd. Publiceren is geweigerd.');
		}

		$report = $this->rosterCheck->checkRoster($rosterId);
		if (($report['registerResolved'] ?? false) !== true) {
			return GuardResult::deny(
				'De bevoegdheden konden niet worden gecontroleerd: '
				. (string)($report['error'] ?? 'het humaniq-register is niet gevonden.')
				. ' Publiceren is geweigerd.'
			);
		}

		if ((int)($report['rostersChecked'] ?? 0) === 0) {
			return GuardResult::deny('Dit rooster is niet gevonden, dus de bevoegdheden kunnen niet worden gecontroleerd. Publiceren is geweigerd.');
		}

		$statements = [];
		foreach (($report['violations'] ?? []) as $finding) {
			if (($finding['kind'] ?? '') === CompetenceCheckService::FINDING_KIND) {
				$statements[] = (string)($finding['statement'] ?? '');
			}
		}

		if ($statements === []) {
			return GuardResult::allow();
		}

		$message = 'Dit rooster kan niet worden gepubliceerd. Diensten zonder geldige bevoegdheid: '
			. count($statements) . '. ' . implode(' ', array_slice($statements, 0, self::NAMED_FINDINGS));
		if (count($statements) > self::NAMED_FINDINGS) {
			$message .= ' Voer occ humaniq:roster:check uit voor de volledige lijst.';
		}

		return GuardResult::deny($message);
	}//end check()

}//end class
