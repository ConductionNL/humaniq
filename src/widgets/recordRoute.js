// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// recordRoute: the detail page a table row opens when the rows are records of
// different schemas, as on the Compliance check page (REQ-RULE-010). The
// manifest names the detail page per schema in `content.routes`; the row
// names its schema in `content.routeField` (default `schema`) and its record
// in `id`. A row whose schema has no detail page opens nothing.

/**
 * The vue-router location a row opens, or null.
 *
 * @param {object} routes Detail page id per schema, from the manifest.
 * @param {string} routeField The row field that names the row's schema.
 * @param {object} row The row.
 * @return {object|null} A `{ name, params: { id } }` location, or null.
 * @spec openspec/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
 */
export function recordRoute(routes, routeField, row) {
	if (!row || !routes) {
		return null
	}
	const name = routes[row[routeField || 'schema']]
	const id = row.id || (row['@self'] && row['@self'].id)
	if (!name || !id) {
		return null
	}
	return { name, params: { id: String(id) } }
}
