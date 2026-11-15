/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * humaniq's agenda leaf: what is planned for the person a record names, for the
 * coming week.
 *
 * humaniq owns what is planned for a person, so humaniq renders it. A consuming
 * app places this leaf and passes the object context rather than querying
 * humaniq's register from its own manifest, which is what dossiq used to do for
 * hours, and why its tile rendered `0` on every install without humaniq
 * (ADR-113). A leaf cannot render when its app is absent, so an empty agenda and
 * a missing humaniq cannot look the same (REQ-AGD-006).
 */
import { translate as t } from '@nextcloud/l10n'
import CnAgendaWidget from './CnAgendaWidget.vue'
import { leafMounting, openRegisterIntegrations } from './integrationLeaf.js'

/**
 * The integration id a consuming app references to place this leaf.
 *
 * Equal to `RegisterAgendaLeafListener::LEAF_ID` on the server half; the two are
 * bound by this string alone, and a mismatch is an orphan registration on both
 * sides rather than an error on either.
 *
 * @type {string}
 */
export const AGENDA_INTEGRATION_ID = 'humaniq-agenda'

/**
 * Every render surface this leaf targets, written out rather than left to the
 * host's default.
 *
 * The list is duplicated verbatim by `RegisterHoursLeafListener::SURFACES` on
 * the server half, and gate-24 compares the two. A half that declares its
 * surfaces by OMISSION is how hermiq's two halves drifted apart unnoticed.
 *
 * @type {string[]}
 */
const SURFACES = ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity']

/**
 * Roots the widget at a host-owned element, one Vue 3 app per element.
 */
const { mount, unmount } = leafMounting(CnAgendaWidget)

/**
 * The integration descriptor for the hours leaf.
 *
 * @type {object}
 */
export const agendaLeafDescriptor = {
	id: AGENDA_INTEGRATION_ID,
	label: t('humaniq', 'Agenda'),
	icon: 'CalendarMonthOutline',
	requiredApp: 'humaniq',
	order: 45,
	group: 'workflow',
	surfaces: SURFACES,
	// AD-18: a schema property carrying referenceType:'agenda' renders this
	// leaf's single-entity surface instead of a plain value, so a case schema can
	// point at the handler's week from the field that names the handler.
	referenceType: 'agenda',
	renderMode: 'mount',
	mount,
	unmount,
	defaultSize: { w: 4, h: 2 },
}

/**
 * Register the leaf on the shared OpenRegister integration registry.
 *
 * Installs a load-order-safe queue stub when OpenRegister's bundle has not yet
 * installed the real registry, so a humaniq bundle that loads first is not lost.
 * Idempotent under the AD-13 collision policy: the first registration of this id
 * wins, a duplicate warns in production and throws in development.
 *
 * @param {object} [globalRef] Global to attach to (defaults to `window`).
 *
 * @return {void}
 */
export function registerAgendaLeaf(globalRef) {
	const integrations = openRegisterIntegrations(globalRef)
	if (integrations !== null) {
		integrations.register(agendaLeafDescriptor)
	}
}
