/**
 * Humaniq v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. Humaniq ships
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
 * from Humaniq's own bundle (confirmed: `registerDashboardWidget(` does not
 * appear anywhere in the built `js/humaniq-main.js`, only in its source map) and
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
 * `lifecycle-actions` (kind: "widget") — live-verified 2026-07-16 gap, the
 * worst of the three: 20 `type:"detail"` pages (TimesheetDetail,
 * ExpenseDetail, LoonaangifteFilingDetail, PensionFilingDetail, …) declare a
 * page-level `config.lifecycleActions` (the shape `CnDetailPage.vue`'s own
 * `lifecycleActions` prop expects, mounting the library's
 * `CnLifecycleActions`). Same root cause as `actions` above — every page has
 * a `slot:"body"` widget, so `CnPageRenderer` renders `CnWidgetGrid` directly
 * and NEVER instantiates `CnDetailPage`, so `config.lifecycleActions` never
 * reaches anywhere. This meant every approve/reject/submit/reopen button in
 * the app — humaniq's core submit-approve-reject workflow — was unclickable.
 *
 * UNLIKE `actions`, this isn't a drop-in registration: `CnLifecycleActions`
 * takes plain props (`object-id`, `object`, `config`) and does NOT inject
 * `cnObjectContext` / `cnDetailObjectContext` itself the way
 * `CnActionButtons` / `CnAuditTrailWidget` do. `CnWidgetGrid` merges the
 * detail context into every widget's props as `objectData` (not `object`),
 * so `CnLifecycleActions` dropped in directly would receive `objectId`
 * correctly but `object` would stay `null` — and every one of these 20 pages
 * declares an explicit `config.transitions` array, which makes
 * `CnLifecycleActions` filter client-side by `object[config.field]`, so a
 * null `object` means every transition silently fails to match and renders
 * NO buttons at all (a second-order phantom underneath the first). See
 * `./widgets/LifecycleActionsWidget.vue` for the thin bridge that renames
 * `objectData` → `object` and backstops the `@reload` event (CnWidgetGrid
 * renders widgets `v-bind`-only, so nothing above ever hears it — the v2
 * detail context's own `or-object-{id}` live-update subscription is the
 * primary refresh path; see that file's docblock).
 *
 * `chart` (kind: "widget") — humaniq-dashboard-steering-indicators: the SAME
 * `effectiveRegistry`-wins-over-catalog override mechanism as `stat`, but
 * for a DIFFERENT reason. `CnChartWidget` IS reachable via the dashboard
 * catalog (`registerDashboardWidget('chart', …)` is called directly inside
 * `CnWidgetGrid/registerDashboardWidgets.js`'s own module body, not behind a
 * second bare side-effect import the way `CnStatWidget`'s registration was —
 * so it does not share `stat`'s specific tree-shaking defect). The problem
 * is chrome, not resolution: `CnChartWidget` was built to be embedded inside
 * `CnDashboardPage`'s own per-widget template, which supplies `title`/the
 * shared Actions menu from the SURROUNDING markup — it declares no `title`
 * prop and does not self-wrap in `CnWidgetWrapper` the way `CnStatWidget` /
 * `CnWidgetObjectTable` do. `CnWidgetGrid` (the only renderer humaniq's pages
 * take, since every page has a `slot:"body"` widget) mounts it bare, so an
 * un-bridged `widgetKey:"chart"` would render as an unlabelled canvas.
 * `./widgets/TrendChartWidget.vue` bridges it — a title bar identical to
 * every object-table widget's, everything else forwarded through
 * `v-bind="$attrs"` unchanged. Remove this override (point `component`
 * straight at the library's `CnChartWidget`) once a future
 * `@conduction/nextcloud-vue` release gives the catalog's chart widget its
 * own chrome the way `CnWidgetObjectTable` already has.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { CnActionButtons, CnStatWidget } from '@conduction/nextcloud-vue'
import AdministrationSwitcher from './views/AdministrationSwitcher.vue'
import FlowDetailSidebar from './views/flows/FlowDetailSidebar.vue'
import ProformaPayslip from './views/ProformaPayslip.vue'
import LifecycleActionsWidget from './widgets/LifecycleActionsWidget.vue'
import TrendChartWidget from './widgets/TrendChartWidget.vue'

export default {
	// --- Flows (ADR-110 Decision 4). Only the SIDEBAR is an app component;
	//     the list and the canvas are the shared `flows` / `flow-detail`
	//     manifest page types. CnFlowSidebar has to mount in the NC app
	//     sidebar for the canvas to keep full width. ---
	FlowDetailSidebar: { kind: 'page', component: FlowDetailSidebar },

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
	chart: {
		kind: 'widget',
		component: TrendChartWidget,
		defaultSize: { w: 6, h: 4 },
		minSize: { w: 3, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['body'],
		propsSchema: null,
		_note: 'Chrome bridge for the library\'s CnChartWidget — see the module docblock above (`./widgets/TrendChartWidget.vue`: title bar via CnWidgetWrapper, everything else forwarded through v-bind="$attrs").',
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
	'lifecycle-actions': {
		kind: 'widget',
		component: LifecycleActionsWidget,
		defaultSize: { w: 8, h: 1 },
		minSize: { w: 4, h: 1 },
		maxSize: { w: 12, h: 2 },
		allowedSlots: ['header-actions'],
		propsSchema: null,
		_note: 'Wires a host-app bridge (./widgets/LifecycleActionsWidget.vue) around the library\'s CnLifecycleActions into a widgetKey so a page-level `{ widgetKey:"lifecycle-actions", slot:"header-actions", props:{ config:{...} } }` entry actually renders — see the module docblock above (defect: config.lifecycleActions is dead code once a page has any body-slot widgets, AND CnLifecycleActions needs an `object` prop CnWidgetGrid never supplies under that name). Remove the bridge (register CnLifecycleActions here directly) if a future @conduction/nextcloud-vue release gives it its own cnDetailObjectContext inject the way CnActionButtons has.',
	},
}
