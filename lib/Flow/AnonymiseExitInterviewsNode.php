<?php

/**
 * Humaniq AnonymiseExitInterviewsNode
 *
 * The `humaniq.anonymise-exit-interviews` flow step (hiring-offboarding-completion). Clear the leaver, the case, who held it and the free text of every exit interview held more than the given number of days ago, keeping the reason, scores, department and date.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use OCA\Humaniq\Service\ExitInterviewService;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * A scheduled step over every due record.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
 */
class AnonymiseExitInterviewsNode extends AdminSweepNode {

	public const OUTCOME_KEY = 'anonymised';

	/**
	 * Constructor.
	 *
	 * @param IL10N          $l10n  Translations.
	 * @param IURLGenerator  $urls  The icon.
	 * @param ExitInterviewService $interviews The work.
	 * @param string|null    $today The day to run on; null is today.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		private readonly ExitInterviewService $interviews,
		private readonly ?string $today=null,
	) {

	}//end __construct()

	/**
	 * The node id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function getId(): string {
		return 'humaniq.anonymise-exit-interviews';
	}//end getId()

	/**
	 * The name in the flow editor.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Anonymise old exit interviews');
	}//end getDisplayName()

	/**
	 * The icon.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('humaniq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * What the step does.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function getDescription(): string {
		return $this->l10n->t('Clear the leaver, the case, who held it and the free text of every exit interview held more than the given number of days ago, keeping the reason, scores, department and date.');
	}//end getDescription()

	/**
	 * Run the step once and pass the outcome on.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run-level metadata.
	 *
	 * @return array<int, mixed> The items out, each carrying the outcome.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function execute(FlowNodeCall $call): array {
		$config = $call->config;
		$today = ($this->today ?? gmdate('Y-m-d'));
		$now = ($today === gmdate('Y-m-d') ? gmdate('c') : $today . 'T00:00:00+00:00');
		$done = $this->interviews->anonymiseDue(today: $today, now: $now, afterDays: (int)($config['afterDays'] ?? 90));

		return FlowItems::stampEach($call->items, [self::OUTCOME_KEY => FlowItems::idsAndCount($done)]);
	}//end execute()
}//end class
