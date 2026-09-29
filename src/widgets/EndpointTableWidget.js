// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// EndpointTableWidget: a table whose rows come from a humaniq endpoint
// (`content.endpointSource`), for DETAIL pages.
//
// Why a widget of our own: CnDetailWidgetHost resolves a library widget type
// through widgetDispatch.resolveRegistryRenderer, which canonicalises the type
// before it looks it up. `object-table` becomes `table`, and `table` renders
// CnObjectListWidget, which reads content.register/schema and has no
// endpointSource. So an `object-table` with an endpointSource showed its empty
// text on every detail page (nextcloud-vue 2.57.1). On a dashboard the same
// widget works, because CnDashboardPage looks the type up as written first and
// reaches the library's CnWidgetObjectTable host adapter.
//
// This mounts that same CnWidgetObjectTable, content-only like the library's
// dashboard adapter: the detail host draws the card. `@objectId` tokens in the
// endpoint resolve from the cnObjectContext CnDetailPage provides.
// tests/validate-widget-keys.js refuses a detail-page endpointSource widget on
// any other type, and retires itself once the library looks the type up as
// written.

import { CnWidgetObjectTable } from '@conduction/nextcloud-vue'
import { h } from 'vue'

export default {
	name: 'EndpointTableWidget',
	inheritAttrs: false,
	props: {
		/** The manifest widget's `content`: endpointSource, columns, rowRoute, emptyText. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},
	/**
	 * Mount CnWidgetObjectTable on the widget's content, without its own card.
	 *
	 * @return {object} The vnode.
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
	 */
	render() {
		return h(CnWidgetObjectTable, { ...this.content, hideWrapper: true })
	},
}
