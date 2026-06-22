<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 HRMQ app shell. Mounts CnAppRoot with the bundled manifest. Every page is a
 declarative `type: "index"` / `type: "detail"` page rendered generically by
 the @conduction/nextcloud-vue library from its register+schema config — there
 are no bespoke page components, so the registry is empty. CnAppRoot reads
 manifest.dependencies and renders a dependency-missing empty state when
 OpenRegister is absent (ADR-024).
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		:registry="registry"
		:page-types="pageTypes"
		app-id="hrmq"
		:translate="translateForApp" />
</template>

<script>
import { translate as ncT } from '@nextcloud/l10n'
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

	methods: {
		// Translate library/manifest strings against the hrmq l10n domain.
		translateForApp(key, vars) {
			return ncT('hrmq', key, vars)
		},
	},
}
</script>
