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
 * `competence` findings refuse, and so do `leave` findings for approved leave
 * (REQ-ROST-C05, row pln-leave-in-roster): nobody is published onto a day they
 * are on leave. Working-time findings and open sick leave stay a report: the
 * decision on #512 covers the competence rule, not the Arbeidstijdenwet ones,
 * and an open sick case has no end date to plan around.
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\CompetenceCheckService;
use OCA\Humaniq\Service\LeaveConflictCheckService;
use OCA\Humaniq\Service\RosterCheckService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Denies publishing a roster that carries an unqualified assignment, or one on approved leave.
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
		$unchecked = $this->uncheckedReason(report: $report);
		if ($unchecked !== null) {
			return GuardResult::deny($unchecked);
		}

		[$competence, $leave] = $this->blockingStatements(report: $report);
		if ($competence === [] && $leave === []) {
			return GuardResult::allow();
		}

		$message = 'Dit rooster kan niet worden gepubliceerd.'
			. $this->named(label: 'Diensten zonder geldige bevoegdheid', statements: $competence)
			. $this->named(label: 'Diensten op een dag met goedgekeurd verlof', statements: $leave);
		if (count($competence) > self::NAMED_FINDINGS || count($leave) > self::NAMED_FINDINGS) {
			$message .= ' Voer occ humaniq:roster:check uit voor de volledige lijst.';
		}

		return GuardResult::deny($message);
	}//end check()

	/**
	 * Why the roster could not be checked, or null when it was.
	 *
	 * @param array<string, mixed> $report The roster check report.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
	 */
	private function uncheckedReason(array $report): ?string {
		if (($report['registerResolved'] ?? false) !== true) {
			return 'De bevoegdheden konden niet worden gecontroleerd: '
				. (string)($report['error'] ?? 'het humaniq-register is niet gevonden.')
				. ' Publiceren is geweigerd.';
		}

		if ((int)($report['rostersChecked'] ?? 0) === 0) {
			return 'Dit rooster is niet gevonden, dus de bevoegdheden kunnen niet worden gecontroleerd. Publiceren is geweigerd.';
		}

		return null;
	}//end uncheckedReason()

	/**
	 * The statements that refuse publication: shifts without a valid
	 * competence, and shifts on a day of approved leave (REQ-ROST-C05; open
	 * sick leave is advisory and only reports).
	 *
	 * @param array<string, mixed> $report The roster check report.
	 *
	 * @return array{0: list<string>, 1: list<string>} Competence and leave statements.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function blockingStatements(array $report): array {
		$competence = [];
		$leave = [];
		foreach (($report['violations'] ?? []) as $finding) {
			$kind = ($finding['kind'] ?? '');
			if ($kind === CompetenceCheckService::FINDING_KIND) {
				$competence[] = (string)($finding['statement'] ?? '');
			}

			if ($kind === LeaveConflictCheckService::FINDING_KIND && ($finding['severity'] ?? '') === 'mandatory') {
				$leave[] = (string)($finding['statement'] ?? '');
			}
		}

		return [$competence, $leave];
	}//end blockingStatements()

	/**
	 * One part of the refusal: a count and the first few statements.
	 *
	 * @param string $label What the statements are.
	 * @param array<int, string> $statements The finding statements.
	 *
	 * @return string The part, empty when there are none.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function named(string $label, array $statements): string {
		if ($statements === []) {
			return '';
		}

		return ' ' . $label . ': ' . count($statements) . '. '
			. implode(' ', array_slice($statements, 0, self::NAMED_FINDINGS));
	}//end named()

}//end class
