/**
 * HRMQ v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. HRMQ ships
 * almost exclusively declarative `type: "index"` / `type: "detail"` pages,
 * which the library renders generically from each page's register+schema
 * config. The exceptions are the pro-forma payslip simulator
 * (proforma-payslip design.md D6) and the multi-administratie switcher
 * (multi-administratie design.md D4/D5): manifest v2 has no declarative
 * primitive for a persist-nothing interactive compute-form, nor for an
 * active-tenant indicator + switch backed by GET/POST endpoints, so each is
 * a host-app SFC registered here under `kind: "page"`.
 *
 * `stat` (kind: "widget") is a WORKAROUND, not a host-app SFC: the library's
 * `CnStatWidget` self-registers the `stat` key into its dashboardWidgetRegistry
 * via a bare side-effect import (`CnWidgetGrid/registerDashboardWidgets.js`
 * imports `CnStatWidget/index.js`), the same pattern that previously shipped
 * `object-list` and `map` as dead widgets before those two were pinned with an
 * explicit inline `registerDashboardWidget()` call. `stat` never got that
 * treatment, so webpack's production tree-shaking drops the self-registration
 * from HRMQ's own bundle (confirmed: `registerDashboardWidget(` does not
 * appear anywhere in the built `js/hrmq-main.js`, only in its source map) and
 * every one of the 31 `widgetKey: "stat"` placements across the manifest
 * (Dashboard KPIs + detail-page stat tiles) resolves to nothing and renders
 * `CnUnknownWidget` ("Widget unavailable"). Registering the library's own
 * `CnStatWidget` here under `kind: "widget"` uses the SAME consumer-override
 * path `CnWidgetGrid` already checks first (`effectiveRegistry` /
 * `cnRegistry` inject), so it resolves regardless of whether the fragile
 * side-effect import survives bundling. Remove this entry if a future
 * `@conduction/nextcloud-vue` release inline-registers `stat` the same way
 * `object-list` / `map` were fixed.
 *
 * `actions` (kind: "widget") — live-verified 2026-07-16 gap, same family as
 * `stat`: 14 `type:"detail"` pages declared a page-level `config.headerActions`
 * (the shape CnDetailPage.vue's own `headerActions` prop expects), but a v2
 * manifest with ANY `widgets[]` entry whose `slot:"body"` (every page in this
 * manifest) makes `CnPageRenderer` take the `widgetsBySlot.has('body')`
 * branch — it renders `CnWidgetGrid` directly and NEVER instantiates
 * `CnDetailPage` at all, so `config.headerActions` never reaches anywhere.
 * `CnPageRenderer` DOES render a `widgets[]` entry whose `slot:"header-actions"`
 * (via the same `CnWidgetGrid`), but `CnActionButtons` — the component that
 * shape actually needs — was never wired into `BUILT_IN_WIDGETS` as a
 * resolvable `widgetKey`. Registering it here (same override path as `stat`)
 * lets each page place `{ widgetKey:"actions", slot:"header-actions",
 * props:{ actions:[...] } }`; `CnActionButtons` resolves `@objectId`/
 * `@object.<field>` tokens itself via the `cnDetailObjectContext` inject
 * `CnPageRenderer` provides regardless of which slot renders it, so no
 * object-context wiring is lost by skipping CnDetailPage.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { CnActionButtons, CnStatWidget } from '@conduction/nextcloud-vue'
import AdministrationSwitcher from './views/AdministrationSwitcher.vue'
import ProformaPayslip from './views/ProformaPayslip.vue'

export default {
	ProformaPayslip: {
		kind: 'page',
		component: ProformaPayslip,
		_note: 'proforma-payslip design.md D6 — persist-nothing interactive gross-to-net compute-form. open-form persists its object and api-call only interpolates fixed/@token params + toast, so neither manifest action can express "gather hypothetical inputs → POST → render breakdown, save nothing". Host-app SFC calling POST /api/payroll/proforma.',
	},
	AdministrationSwitcher: {
		kind: 'page',
		component: AdministrationSwitcher,
		_note: 'multi-administratie design.md D4/D5 — the ADR-001 Rule 3 active-administratie indicator + switch. The manifest v2 renderer chrome exposes no topbar-switcher slot (the known v2 renderer chrome gap) and no declarative primitive can gather the caller\'s accessible administraties, POST a guarded switch, and re-scope sibling pages. Host-app SFC calling GET/POST /api/administration/*.',
	},
	stat: {
		kind: 'widget',
		component: CnStatWidget,
		defaultSize: { w: 4, h: 2 },
		minSize: { w: 2, h: 2 },
		maxSize: { w: 12, h: 4 },
		allowedSlots: ['body', 'sidebar'],
		propsSchema: null,
		_note: 'Explicit override for the library\'s CnStatWidget — see the module docblock above. Manifest widgets already pass the exact { title, icon, content } shape CnStatWidget expects, so no wrapper is needed.',
	},
	actions: {
		kind: 'widget',
		component: CnActionButtons,
		defaultSize: { w: 12, h: 1 },
		minSize: { w: 2, h: 1 },
		maxSize: { w: 12, h: 2 },
		allowedSlots: ['header-actions'],
		propsSchema: null,
		_note: 'Wires the library\'s CnActionButtons into a widgetKey so a page-level `{ widgetKey:"actions", slot:"header-actions", props:{ actions:[...] } }` entry actually renders — see the module docblock above (defect: config.headerActions is dead code once a page has any body-slot widgets).',
	},
}
