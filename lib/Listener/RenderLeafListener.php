<?php

/**
 * Humaniq RenderLeafListener.
 *
 * The shared server half of humaniq's render-surface leaves (`humaniq-agenda`
 * and `humaniq-hours`). Each leaf listener declares its own id, label, icon,
 * group, reference type and surfaces as constants and builds its descriptor;
 * this base class turns those into the descriptor arguments, declares the
 * shared-entry load strategy when the OpenRegister beside us understands it,
 * and registers the leaf without a provider.
 *
 * RENDER-AND-READ ONLY (ADR-066 decision 2): the leaves carry no verb and no
 * run authority and pass a null provider, because neither serves an app-local
 * store behind its leaf.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\AppInfo\Application;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafAvailability;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafIdentity;
use OCA\OpenRegister\Service\Integration\LeafRendering;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Registers one render-surface leaf described by the subclass's constants.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
 */
abstract class RenderLeafListener implements IEventListener {

	/**
	 * The leaf id, shared with the JS half. Each leaf listener declares its own.
	 *
	 * @var string
	 */
	public const LEAF_ID = '';

	/**
	 * The l10n SOURCE string for the label. Each leaf listener declares its own.
	 *
	 * @var string
	 */
	public const LABEL_SOURCE = '';

	/**
	 * Material Design Icons name. Each leaf listener declares its own.
	 *
	 * @var string
	 */
	public const ICON = '';

	/**
	 * Admin-UI grouping. Each leaf listener declares its own.
	 *
	 * @var string
	 */
	public const GROUP = '';

	/**
	 * AD-18 reference type marker. Each leaf listener declares its own.
	 *
	 * @var string
	 */
	public const REFERENCE_TYPE = '';

	/**
	 * The render surfaces the leaf targets. Each leaf listener declares its own.
	 *
	 * @var string[]
	 */
	public const SURFACES = [];

	/**
	 * Constructor.
	 *
	 * @param IL10N           $l10n   Localisation for the human-readable label.
	 * @param LoggerInterface $logger PSR-3 logger; a throwing listener must cost only its own leaf.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Contribute this listener's leaf descriptor.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-006
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterLeafProvidersEvent === false) {
			return;
		}

		try {
			// Render-only leaf: no IntegrationProvider.
			$event->registerLeaf($this->describe($this->descriptorArguments(), $this->renderingArguments()), null);
		} catch (Throwable $e) {
			// Never take the leaf catalogue down: log and skip our own leaf only.
			$this->logger->warning(
				'Humaniq could not register the ' . static::LEAF_ID . ' leaf: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end handle()

	/**
	 * Build the leaf's descriptor from the shared arguments.
	 *
	 * @param array<string, mixed> $arguments The descriptor arguments other than the rendering, by parameter name.
	 * @param array<string, mixed> $rendering The rendering arguments, by parameter name.
	 *
	 * @return LeafDescriptor
	 */
	abstract protected function describe(array $arguments, array $rendering): LeafDescriptor;

	/**
	 * The descriptor arguments every render-surface leaf declares, except its rendering.
	 *
	 * @return array<string, mixed>
	 */
	private function descriptorArguments(): array {
		return [
			'identity' => new LeafIdentity(
				id: static::LEAF_ID,
				label: $this->l10n->t(static::LABEL_SOURCE),
				icon: static::ICON,
				group: static::GROUP,
			),
			'kinds' => [LeafDescriptor::KIND_RENDER_SURFACE],
			'availability' => new LeafAvailability(requiredApp: Application::APP_ID),
		];

	}//end descriptorArguments()

	/**
	 * The rendering arguments every render-surface leaf declares.
	 *
	 * @return array<string, mixed>
	 */
	private function renderingArguments(): array {
		$rendering = [
			'surfaces' => static::SURFACES,
			'referenceType' => static::REFERENCE_TYPE,
		];

		if ($this->descriptorSupportsLoadStrategy() === true) {
			// humaniq builds `js/humaniq-leaves.js` from a dedicated `leaves`
			// webpack entry, and OpenRegister's `LeafScriptListener` puts it
			// on the consuming pages. That is the one convention the platform
			// can VERIFY, so declaring it opts this leaf into being checked:
			// openregister#3956 refuses a leaf that claims the shared entry
			// and ships no bundle, which is exactly the failure that left
			// `humaniq-hours` dark on dossiq case pages before the entry
			// existed.
			$rendering['loadStrategy'] = LeafDescriptor::LOADS_VIA_SHARED_ENTRY;
		}

		return $rendering;

	}//end renderingArguments()

	/**
	 * Whether the OpenRegister beside us understands the `loadStrategy` argument.
	 *
	 * 🔴 A DECLARATION ABOUT HOW A LEAF LOADS MUST NEVER BE WHY IT DOES NOT LOAD.
	 *
	 * `loadStrategy` and the `LOADS_*` constants arrived together in
	 * openregister#3956. humaniq cannot choose which OpenRegister an admin runs
	 * it beside, and reading a constant that class does not declare is an
	 * `Error`. The catch in `handle()` would then swallow it and the leaf would
	 * simply be absent, with nothing anywhere saying so. hermiq measured exactly
	 * that on a live instance: seven warnings in nextcloud.log and an agent leaf
	 * that never registered.
	 *
	 * Both halves are checked rather than one standing in for the other: the
	 * constant is what this listener reads, the `LeafRendering` parameter is what
	 * it passes, and a stub or a partial backport can carry one without the other.
	 *
	 * @return bool Whether the descriptor accepts a load strategy.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-006
	 */
	protected function descriptorSupportsLoadStrategy(): bool {
		if (defined(LeafDescriptor::class.'::LOADS_VIA_SHARED_ENTRY') === false) {
			return false;
		}

		$constructor = (new ReflectionClass(LeafRendering::class))->getConstructor();
		if ($constructor === null) {
			return false;
		}

		foreach ($constructor->getParameters() as $parameter) {
			if ($parameter->getName() === 'loadStrategy') {
				return true;
			}
		}

		return false;

	}//end descriptorSupportsLoadStrategy()
}//end class
