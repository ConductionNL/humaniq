<?php

/**
 * Flow step humaniq.team-digest (self-service-announcements-and-digest D3).
 *
 * Composes the daily away-and-birthday message of one org unit and puts it on
 * the item as `digest.message`, where the next step, OpenRegister's
 * send-talk-message, reads it with `{{ digest.message }}`. A scheduled run
 * starts without an item, so the step makes its own. On a day with nothing to
 * say it returns no item, and the Talk step posts nothing.
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use InvalidArgumentException;
use OCA\Humaniq\Service\TeamDigestComposer;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * The team-digest flow step.
 *
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
 */
class TeamDigestNode implements IFlowNode {

	public const OUTCOME_KEY = 'digest';

	/**
	 * Constructor.
	 *
	 * @param IL10N              $l10n     Translations.
	 * @param IURLGenerator      $urls     For the step icon.
	 * @param TeamDigestComposer $composer Builds the message.
	 * @param string|null        $today    The day to compose for; today when null (tests pin it).
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		private readonly TeamDigestComposer $composer,
		private readonly ?string $today=null,
	) {

	}//end __construct()

	/**
	 * The step type id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function getId(): string {
		return 'humaniq.team-digest';
	}//end getId()

	/**
	 * The step name in the builder.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Compose the daily team message');
	}//end getDisplayName()

	/**
	 * The step description in the builder.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function getDescription(): string {
		return $this->l10n->t('Say who in a department is away today and until when, and whose birthday it is, without a leave type, reason or age.');
	}//end getDescription()

	/**
	 * The step icon.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('humaniq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * Available to admin and user flows.
	 *
	 * @param int $scope The scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The step needs the department.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Without an orgUnitId.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['orgUnitId'] ?? '')) === '') {
			throw new InvalidArgumentException($this->l10n->t('The daily team message needs the department it is about.'));
		}
	}//end validateConfig()

	/**
	 * Compose the message and put it on each item.
	 *
	 * @param array<int, mixed>    $items   The incoming items; none on a scheduled start.
	 * @param array<string, mixed> $config  orgUnitId, includeChildren (default true).
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return array<int, array<string, mixed>> The items with `digest`, or none on a quiet day.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function execute(array $items, array $config, array $context): array {
		unset($context);
		$this->validateConfig($config);
		$digest = $this->composer->compose(
			orgUnitId: trim((string)$config['orgUnitId']),
			includeChildren: (($config['includeChildren'] ?? true) !== false),
			today: ($this->today ?? gmdate('Y-m-d'))
		);

		if ($digest['message'] === '') {
			return [];
		}

		$out = [];
		foreach (($items === [] ? [['json' => []]] : $items) as $item) {
			$item = (array)$item;
			$json = (array)($item['json'] ?? []);
			$json[self::OUTCOME_KEY] = [
				'unit' => $digest['unit'],
				'message' => $digest['message'],
				'awayCount' => count($digest['away']),
				'birthdayCount' => count($digest['birthdays']),
			];
			$item['json'] = $json;
			$out[] = $item;
		}

		return $out;
	}//end execute()

}//end class
