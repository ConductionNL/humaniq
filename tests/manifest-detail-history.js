// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// manifest-detail-history.js — every humaniq detail page shows the record's
// audit history in its sidebar (record-history-on-every-detail-page,
// REQ-RAH-002, design D4). A `type: "detail"` page without
// `sidebar.showMetadata: true` hides who viewed or changed the record, so
// tests/validate-manifest.js fails on it, naming the page.
//
// @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#requirement-every-humaniq-detail-page-shall-show-the-records-history-req-rah-002

"use strict";

/**
 * The sidebar block of a page: under `config`, where humaniq's pages put it, or on the page itself.
 *
 * @param {object} page A manifest page.
 * @return {object} The sidebar block, or an empty object.
 */
function sidebarOf(page) {
	const config = page && typeof page.config === "object" && page.config !== null ? page.config : {};
	const sidebar = config.sidebar ?? page.sidebar;
	return sidebar && typeof sidebar === "object" ? sidebar : {};
}

/**
 * The ids of the detail pages that do not show the record's history.
 *
 * @param {object} manifest The effective manifest.
 * @return {string[]} The page ids, in manifest order.
 */
function detailPagesWithoutHistory(manifest) {
	const pages = Array.isArray(manifest && manifest.pages) ? manifest.pages : [];
	return pages
		.filter((page) => page && page.type === "detail" && sidebarOf(page).showMetadata !== true)
		.map((page) => String(page.id ?? page.route ?? "(unnamed)"));
}

module.exports = { detailPagesWithoutHistory };
