// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// RecordRouteTableWidget: a table whose rows come from a humaniq endpoint
// (`content.endpointSource`) and are records of DIFFERENT schemas, each row
// opening its own record's detail page (REQ-RULE-010, the Compliance check
// page, where one violation is about a contract and the next about a
// payslip).
//
// Why a widget of our own: the library's object-table takes one static
// `rowRoute` for every row. This mounts that same CnWidgetObjectTable,
// content-only like the library's dashboard adapter, and hands its inner
// CnDataTable a `rowClickRoute` (CnWidgetObjectTable forwards $attrs to it)
// that picks the detail page per row from `content.routes`.

import { CnWidgetObjectTable } from '@conduction/nextcloud-vue'
import { h } from 'vue'
import { recordRoute } from './recordRoute.js'

export default {
	name: 'RecordRouteTableWidget',
	inheritAttrs: false,
	props: {
		/** The manifest widget's `content`: endpointSource, columns, routes, routeField, emptyText. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},
	/**
	 * Mount CnWidgetObjectTable on the widget's content, each row routed to its own record.
	 *
	 * @return {object} The vnode.
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
	 */
	render() {
		const { routes, routeField, ...rest } = this.content
		return h(CnWidgetObjectTable, {
			...rest,
			hideWrapper: true,
			rowClickRoute: (row) => recordRoute(routes, routeField, row),
		})
	},
}
