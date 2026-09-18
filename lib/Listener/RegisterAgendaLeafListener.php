<?php

/**
 * Humaniq RegisterAgendaLeafListener.
 *
 * Registers humaniq's `humaniq-agenda` leaf on OpenRegister through the
 * sibling-app leaf-registration hook (`RegisterLeafProvidersEvent`, ADR-066).
 * This is the SERVER half of a registration whose client half lives in
 * `src/integrations/registerAgendaLeaf.js` and mounts under the SAME id.
 *
 * WHY BOTH HALVES. ADR-066 decision 1 makes the JS registration the render
 * half, bound to the server descriptor by shared `id`. A leaf with only one
 * half is an orphan registration: it either renders while no server-side
 * consumer can see it, or is catalogued while nothing puts it on a page.
 * gate-24 R2 refuses both, and hermiq's two halves drifted apart exactly this
 * way.
 *
 * WHY THE LEAF EXISTS. humaniq owns what is planned for a person, so humaniq
 * renders it. A consuming app placing this leaf on a case page shows the
 * handler's week without querying humaniq's register from its own manifest,
 * which is the mistake ADR-113 records: a query against an app that is not
 * installed answers zero, and a real zero looks exactly the same.
 *
 * WHEN HUMANIQ IS ABSENT the leaf is not registered at all, so a host renders
 * no agenda panel rather than an empty one (REQ-AGD-006). An empty agenda and a
 * missing humaniq must not look the same.
 *
 * RENDER-AND-READ ONLY (ADR-066 decision 2). The descriptor carries no verb and
 * no run authority: it declares `render-surface`, and the widget reads the
 * agenda through humaniq's own guarded endpoint.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-006
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\AppInfo\Application;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contributes the `humaniq-agenda` leaf descriptor to OpenRegister.
 *
 * @template-implements IEventListener<Event>
 */
class RegisterAgendaLeafListener implements IEventListener {

	/**
	 * The leaf id, equal to `AGENDA_INTEGRATION_ID` in the JS half.
	 *
	 * The two halves are bound by this shared string; a mismatch is an orphan
	 * registration on both sides rather than an error on either.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'humaniq-agenda';

	/**
	 * The l10n SOURCE string for the label, equal to the string the JS half
	 * passes to its own translate call.
	 *
	 * @var string
	 */
	public const LABEL_SOURCE = 'Agenda';

	/**
	 * Material Design Icons name, equal to the JS half's `icon`.
	 *
	 * @var string
	 */
	public const ICON = 'CalendarMonthOutline';

	/**
	 * Admin-UI grouping, equal to the JS half's `group`.
	 *
	 * @var string
	 */
	public const GROUP = 'workflow';

	/**
	 * AD-18 marker: a schema property carrying `referenceType: 'agenda'`
	 * renders this leaf's single-entity surface instead of a plain value.
	 *
	 * @var string
	 */
	public const REFERENCE_TYPE = 'agenda';

	/**
	 * The render surfaces this leaf targets — the SAME set, in the same order,
	 * as `SURFACES` in the JS half.
	 *
	 * Written out on both halves rather than left to a default, because that is
	 * what gives gate-24 R4 two explicit sets to compare.
	 *
	 * @var string[]
	 */
	public const SURFACES = [
		'user-dashboard',
		'app-dashboard',
		'detail-page',
		'single-entity',
	];

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
	 * Contribute the `humaniq-agenda` leaf descriptor.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-006
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterLeafProvidersEvent === false) {
			return;
		}

		try {
			$descriptor = new LeafDescriptor(
				id: self::LEAF_ID,
				label: $this->l10n->t(self::LABEL_SOURCE),
				icon: self::ICON,
				kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
				requiredApp: Application::APP_ID,
				group: self::GROUP,
				surfaces: self::SURFACES,
				referenceType: self::REFERENCE_TYPE,
				// Vue 3 leaf under a possibly-Vue-2.7 host: the JS half renders
				// through a mount/unmount DOM hand-off, so the server descriptor
				// MUST declare the same render mode under the shared id or the
				// surface blanks (gate-24 R3).
				renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
			);

			// Render-only leaf: no IntegrationProvider. The widget reads the
			// agenda through humaniq's own endpoint, so there is no app-local
			// store to serve behind this leaf.
			$event->registerLeaf($descriptor, null);
		} catch (Throwable $e) {
			// Never take the leaf catalogue down: log and skip our own leaf only.
			$this->logger->warning(
				'Humaniq could not register the humaniq-agenda leaf: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end handle()
}//end class
