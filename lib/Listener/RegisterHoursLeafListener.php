<?php

/**
 * Humaniq RegisterHoursLeafListener.
 *
 * Registers Humaniq's `humaniq-hours` leaf on OpenRegister through the
 * sibling-app leaf-registration hook (`RegisterLeafProvidersEvent`, ADR-066).
 * This is the SERVER-SIDE half of a registration whose client half lives in
 * `src/integrations/registerHoursLeaf.js` and mounts under the SAME id.
 *
 * WHY BOTH HALVES, when the leaf already renders. ADR-066 decision 1 makes the
 * JS `registerIntegration()` path the render-surface half, "bound to the server
 * descriptor by shared `id`". Without this listener the leaf renders but is
 * invisible to every server-side consumer — an orphan registration under
 * ADR-066 decision 4, which gate-24 R2 refuses.
 *
 * WHY THE LEAF EXISTS AT ALL. Humaniq owns hours (ADR-107 decision 6: "hours
 * logged on a case are humaniq time entries carrying the case reference"), so
 * Humaniq renders them. dossiq used to aggregate `humaniq/TimeEntry` from its
 * own manifest instead, and on an install without Humaniq that request 404'd
 * and the tile rendered `0` — which is exactly what a real zero renders, on
 * every case, looking correct throughout (ADR-113). A leaf cannot render when
 * its app is absent, so that failure mode does not exist here rather than being
 * handled.
 *
 * RENDER-AND-READ ONLY (ADR-066 decision 2). The descriptor carries no Vue
 * components, no verb and no run authority. It declares one kind,
 * `render-surface`: Humaniq mounts the hours surface on a host object, and the
 * components stay in Humaniq's own bundle.
 *
 * It does NOT declare `data-provider`: the widget reads time entries through
 * OpenRegister's own object API from the client (ADR-022), so Humaniq serves no
 * app-local store behind this leaf and passes a null provider.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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
 * Contributes the `humaniq-hours` leaf descriptor to OpenRegister.
 *
 * @template-implements IEventListener<Event>
 */
class RegisterHoursLeafListener implements IEventListener {

	/**
	 * The leaf id, equal to `HOURS_INTEGRATION_ID` in the JS half.
	 *
	 * The two halves are bound by this shared string; a mismatch is an orphan
	 * registration on both sides rather than an error on either.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'humaniq-hours';

	/**
	 * The l10n SOURCE string for the label, equal to the string the JS half
	 * passes to its own translate call.
	 *
	 * Both halves must translate the SAME key or the two render different
	 * labels for one leaf depending on which side the reader is looking at.
	 *
	 * @var string
	 */
	public const LABEL_SOURCE = 'Hours';

	/**
	 * Material Design Icons name, equal to the JS half's `icon`.
	 *
	 * @var string
	 */
	public const ICON = 'ClockOutline';

	/**
	 * Admin-UI grouping, equal to the JS half's `group`.
	 *
	 * @var string
	 */
	public const GROUP = 'workflow';

	/**
	 * AD-18 marker: a schema property carrying `referenceType: 'hours'` renders
	 * this leaf's single-entity surface instead of a plain value.
	 *
	 * @var string
	 */
	public const REFERENCE_TYPE = 'hours';

	/**
	 * The render surfaces this leaf targets — the SAME set, in the same order,
	 * as `SURFACES` in the JS half.
	 *
	 * Written out on both halves rather than left to a default, because that is
	 * what gives gate-24 R4 two explicit sets to compare. A half that declares
	 * its surfaces by omission is how hermiq's two halves drifted apart
	 * unnoticed.
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
	 * Contribute the `humaniq-hours` leaf descriptor.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
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

			// Render-only leaf: no IntegrationProvider. The widget reads time
			// entries through OpenRegister's object API in the browser, so there
			// is no app-local store to serve behind this leaf.
			$event->registerLeaf($descriptor, null);
		} catch (Throwable $e) {
			// Never take the leaf catalogue down: log and skip our own leaf only.
			$this->logger->warning(
				'Humaniq could not register the humaniq-hours leaf: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end handle()
}//end class
