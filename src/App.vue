<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 HRMQ app shell. Mounts CnAppRoot with the bundled manifest. Every page is a
 declarative `type: "index"` / `type: "detail"` page rendered generically by
 the @conduction/nextcloud-vue library from its register+schema config — there
 are no bespoke page components, so the registry is empty. CnAppRoot reads
 manifest.dependencies and renders a dependency-missing empty state when
 OpenRegister is absent (ADR-024).

 multi-administratie / #64: this component is also the SPA ROOT that provides
 the reactive `cnWorkspaceContext` every manifest-rendered widget injects
 (`nextcloud-vue/src/utils/sentinelTokens.js`'s `workspace` context —
 `@workspace.<key>` / `@workspace.<key>?`). The library's own `CnDashboardPage`
 only provides this bag page-scoped (dashboards), but Vue's provide/inject
 walks the WHOLE ancestor chain, not just the direct parent — providing it
 here makes `@workspace.activeAdministrationId?` resolve on every page type
 (index/detail/dashboard/custom), with zero upstream nextcloud-vue change.
 Seeded from `IInitialState` (`PageController::index()`) so it is correct on
 first paint, not only after a switch; `AdministrationSwitcher.vue` injects
 the SAME ref and writes into it on a successful switch so every page
 re-scopes reactively, without a full reload.
-->
<template>
	<CnAppRoot
		:ai-companion="true"
		:manifest="manifest"
		:registry="registry"
		:page-types="pageTypes"
		app-id="hrmq"
		:translate="translateForApp" />
</template>

<script>
import { ref, provide } from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { CnAppRoot } from '@conduction/nextcloud-vue'

export default {
	name: 'App',

	components: {
		CnAppRoot,
	},

	props: {
		manifest: {
			type: Object,
			required: true,
		},
		registry: {
			type: Object,
			default: () => ({}),
		},
		pageTypes: {
			type: Object,
			default: () => ({}),
		},
	},

	/**
	 * Provide the page-level `cnWorkspaceContext` bag at the SPA root, seeded
	 * from the `activeAdministrationId` initial state `PageController::index()`
	 * stamps for the caller (empty string when the caller never switched — the
	 * `@workspace.activeAdministrationId?` filters then drop the clause, the
	 * documented no-regression default). A plain `ref({})`, matching the shape
	 * `CnDashboardPage` provides and every widget's `workspaceCtx()` computed
	 * already knows how to unwrap (`'value' in c ? c.value : c`).
	 *
	 * @return {void}
	 */
	setup() {
		const initialAdministrationId = loadState('hrmq', 'activeAdministrationId', '')
		const cnWorkspaceContext = ref(
			initialAdministrationId ? { activeAdministrationId: initialAdministrationId } : {},
		)
		provide('cnWorkspaceContext', cnWorkspaceContext)
	},

	methods: {
		// Translate library/manifest strings against the hrmq l10n domain.
		translateForApp(key, vars) {
			return ncT('hrmq', key, vars)
		},
	},
}
</script>
