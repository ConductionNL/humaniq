<?php

/**
 * Humaniq RevokeAccessNode
 *
 * The `humaniq.revoke-access` flow step (hiring-offboarding-completion). Disable the Nextcloud account of every leaver whose last working day has passed and whose case is still open, and tick Access revoked on the case.
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use OCA\Humaniq\Service\AccessRevocationService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * A scheduled step over every due record.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */
class RevokeAccessNode implements IFlowNode {

	public const OUTCOME_KEY = 'revoked';

	/**
	 * Constructor.
	 *
	 * @param IL10N          $l10n  Translations.
	 * @param IURLGenerator  $urls  The icon.
	 * @param AccessRevocationService $revocation The work.
	 * @param string|null    $today The day to run on; null is today.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		private readonly AccessRevocationService $revocation,
		private readonly ?string $today=null,
	) {

	}//end __construct()

	/**
	 * The node id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function getId(): string {
		return 'humaniq.revoke-access';
	}//end getId()

	/**
	 * The name in the flow editor.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Revoke the access of leavers');
	}//end getDisplayName()

	/**
	 * What the step does.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function getDescription(): string {
		return $this->l10n->t('Disable the Nextcloud account of every leaver whose last working day has passed and whose case is still open, and tick Access revoked on the case.');
	}//end getDescription()

	/**
	 * The icon.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('humaniq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * Available to administrators only: it acts on every case.
	 *
	 * @param int $scope The scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}//end isAvailableForScope()

	/**
	 * Nothing to validate.
	 *
	 * @param array<string, mixed> $config The step config.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function validateConfig(array $config): void {
		unset($config);
	}//end validateConfig()

	/**
	 * Run the step once and pass the outcome on.
	 *
	 * @param array<int, mixed>    $items   The items in.
	 * @param array<string, mixed> $config  The step config.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return array<int, mixed> The items out, each carrying the outcome.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function execute(array $items, array $config, array $context): array {
		unset($config, $context);
		$today = ($this->today ?? gmdate('Y-m-d'));
		$now = ($today === gmdate('Y-m-d') ? gmdate('c') : $today . 'T00:00:00+00:00');
		$done = $this->revocation->revokeDue(today: $today, now: $now);

		$out = [];
		foreach (($items === [] ? [['json' => []]] : $items) as $item) {
			$item = (array)$item;
			$json = (array)($item['json'] ?? []);
			$json[self::OUTCOME_KEY] = ['ids' => $done, 'count' => count($done)];
			$item['json'] = $json;
			$out[] = $item;
		}

		return $out;
	}//end execute()
}//end class
