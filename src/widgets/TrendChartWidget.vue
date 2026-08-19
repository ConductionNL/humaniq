<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 TrendChartWidget — bridges CnPageRenderer's v2 widget-grid `body` slot onto
 `@conduction/nextcloud-vue`'s CnChartWidget (hrmq-dashboard-steering-indicators),
 and fixes a confirmed null-coercion defect in the library's OWN endpointSource
 mapping along the way.

 WHY THIS EXISTS (title/icon chrome)
 ------------------------------------
 CnChartWidget is registered in the library's dashboard-widget catalog under
 the key `chart` (`CnWidgetGrid/registerDashboardWidgets.js`) and CnWidgetGrid
 resolves it via that catalog when nothing overrides it — so the manifest's
 `widgetKey: "chart"` DOES mount a real, working chart. But CnChartWidget was
 built to be embedded inside `CnDashboardPage`'s own per-widget template,
 which wraps it in `CnWidgetWrapper` itself and supplies `title`/the shared
 overflow Actions menu from the SURROUNDING template — CnChartWidget declares
 no `title` prop of its own and does not self-wrap. `CnWidgetGrid` (the
 renderer every hrmq page actually uses — every page here has a
 `slot:"body"` widget, so `CnPageRenderer` always takes the CnWidgetGrid
 branch, never `CnDashboardPage`) mounts the resolved component directly with
 no such surrounding chrome. Left unbridged, the trend charts would render as
 an unlabelled chart canvas — no visible title — unlike every other widget on
 the same Dashboard page.

 WHY THIS EXISTS (null-safe endpoint binding — REQ-DSI-004/006/007)
 ---------------------------------------------------------------------
 `AnalyticsService` deliberately emits JSON `null` (never `0`) for an
 unmeasured period bucket — the whole point of REQ-DSI-004/006/007's
 null-not-zero contract. But CnChartWidget's OWN `endpointChartData` computed
 (`CnChartWidget.vue`, the `series` mapping for an ARRAY-shaped endpoint
 payload) reads:
 ```
 data: payload.map((pt) => Number(getByPath(pt, s.path)) || 0)
 ```
 `Number(null)` is `0`, and `0 || 0` is `0` — CONFIRMED against the actually
 INSTALLED `node_modules/@conduction/nextcloud-vue` dist (not just the
 sibling source tree), not a stale-checkout artefact. Every `null` bucket
 this endpoint emits would silently become a `0` data point before
 ApexCharts ever sees it — the EXACT "0% — excellent" failure mode
 `AbsenceRateService`'s own docblock names, reintroduced one layer up, by a
 library default this leaf app cannot change (a `@conduction/nextcloud-vue`
 fix needs discussion/a PR there; out of scope for this change).

 So for an `endpointSource`-bound chart, this bridge does NOT hand
 `endpointSource` to CnChartWidget at all. It fetches the SAME endpoint
 itself via the library's own `useEndpointSource` composable (identical
 token resolution, shared-response cache, `cn:page:refresh` /
 `cn:widget:refresh` subscriptions — only the null-handling differs) and
 maps the response to `series` / `categories` props itself, preserving
 `null` exactly: `(v === null || v === undefined) ? null : Number(v)`. A
 `null` datapoint reaches ApexCharts unmodified, which is what actually
 renders it as a gap in the line (ApexCharts' own documented null-handling —
 true once the value ISN'T coerced away first).

 This is the SAME class of gap `registry.js` already documents and bridges
 twice (`actions`, `lifecycle-actions`): a library component built for one
 rendering path — or, here, one data contract — doesn't quite fit what this
 app needs. Registered under the SAME `chart` widgetKey in `registry.js`
 (the `stat` override precedent — `effectiveRegistry` wins over the
 catalog), so the manifest keeps declaring plain `widgetKey: "chart"`.
-->
<template>
	<CnWidgetWrapper
		:title="title"
		:widget-id="widgetId"
		:documentation-url="documentationUrl"
		flush>
		<CnChartWidget
			v-if="endpointSource"
			v-bind="$attrs"
			:series="nullSafeSeries"
			:categories="nullSafeCategories"
			:widget-id="widgetId" />
		<!-- No endpointSource configured (the two dataSource-native trend
		     widgets, Billable ratio / Headcount & turnover): CnChartWidget's
		     OWN dataSource handling has no equivalent null-coercion bug in
		     scope for THIS change (neither widget carries a null-sensitive
		     metric), so it is forwarded through unmodified. -->
		<CnChartWidget v-else v-bind="$attrs" :widget-id="widgetId" />
	</CnWidgetWrapper>
</template>

<script>
import { computed } from 'vue'
import { CnChartWidget, CnWidgetWrapper, useEndpointSource } from '@conduction/nextcloud-vue'

export default {
	name: 'TrendChartWidget',

	components: { CnChartWidget, CnWidgetWrapper },

	// Every OTHER CnChartWidget prop (type, dataSource, valueFormat, height,
	// …) rides through as an undeclared attr via `v-bind="$attrs"` rather
	// than being individually re-declared here — the manifest's `props`
	// already name them exactly as CnChartWidget expects, and re-listing
	// them would only add a second place to keep in sync with the library's
	// own prop contract. `series` / `categories` are the two exceptions:
	// this component computes them itself for the endpointSource case (see
	// module docblock), so they are bound explicitly rather than forwarded.
	inheritAttrs: false,

	props: {
		/** Widget title, shown in the CnWidgetWrapper header. */
		title: {
			type: String,
			default: '',
		},
		/** Widget id, merged in by CnWidgetGrid's `props.widgetId` — drives the `cn:widget:refresh` bus target on both the wrapper's Refresh action and the chart's own subscription. */
		widgetId: {
			type: String,
			default: '',
		},
		/** Optional docs link surfaced in the wrapper's overflow Actions menu. */
		documentationUrl: {
			type: String,
			default: '',
		},
		/**
		 * Null-safe endpoint binding. Same shape CnChartWidget's own
		 * `endpointSource` prop takes (`{url, method?, params?,
		 * responsePath?}`), fetched through the SAME `useEndpointSource`
		 * composable — only the series/categories MAPPING differs (see
		 * module docblock). `null` when this chart is dataSource-bound
		 * instead.
		 *
		 * @type {{url: string, method?: string, params?: object, responsePath?: string}|null}
		 */
		endpointSource: {
			type: Object,
			default: null,
		},
		/**
		 * Per-item field name read as the x-axis category label — the
		 * ARRAY-payload sibling of CnChartWidget's own `endpointSource.labelsPath`.
		 */
		labelsPath: {
			type: String,
			default: 'date',
		},
		/**
		 * One series per entry: `{name, path}` — `path` is the per-item
		 * field name read as that series' value. The ARRAY-payload sibling
		 * of CnChartWidget's own `endpointSource.series[]`.
		 *
		 * @type {Array<{name: string, path: string}>}
		 */
		seriesFields: {
			type: Array,
			default: () => [],
		},
	},

	setup(props) {
		const ep = useEndpointSource(() => props.endpointSource)

		const payloadArray = computed(() => (Array.isArray(ep.data.value) ? ep.data.value : []))

		const nullSafeSeries = computed(() => props.seriesFields.map((field) => ({
			name: field.name || field.path,
			data: payloadArray.value.map((point) => {
				const raw = point ? point[field.path] : undefined
				return (raw === null || raw === undefined) ? null : Number(raw)
			}),
		})))

		const nullSafeCategories = computed(() => payloadArray.value.map((point) => {
			const raw = point ? point[props.labelsPath] : undefined
			return (raw === null || raw === undefined) ? '' : String(raw)
		}))

		return { nullSafeSeries, nullSafeCategories }
	},
}
</script>
