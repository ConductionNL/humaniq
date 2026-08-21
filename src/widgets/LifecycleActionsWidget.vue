<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LifecycleActionsWidget — bridges CnPageRenderer's v2 widget-grid detail
 context onto `@conduction/nextcloud-vue`'s CnLifecycleActions prop contract
 (2026-07-16 defect: every approve/reject/submit/reopen button in hrmq was
 dead — see the `lifecycle-actions` registry entry docblock for the full
 defect writeup).

 CnLifecycleActions is a plain props-in/events-out component: `object-id` +
 `object` (the FULL current record, read for `object[config.field]` to
 filter a config-declared `transitions` list by current state) + `config`.
 It does NOT inject `cnObjectContext` / `cnDetailObjectContext` itself the
 way `CnActionButtons` / `CnAuditTrailWidget` do, so it cannot be dropped
 straight into `BUILT_IN_WIDGETS` or `registry.js` the way `stat` / `actions`
 were: `CnWidgetGrid` merges the detail context into every widget's props
 under the keys `objectData` / `objectId` / `objectType` / `register` /
 `schema` / `store` (see `CnWidgetGrid.detailContextProps`) — there is no
 `object` key, so CnLifecycleActions would receive `objectId` correctly but
 `object` would stay `null`, and every hrmq page here declares an explicit
 `config.transitions` array (so `useServer` is false and the `object[field]`
 read is load-bearing), which is exactly the silent-dead failure mode this
 fix closes.

 This widget declares the props CnWidgetGrid actually merges in, renames
 `objectData` → `object` for CnLifecycleActions, and forwards the manifest's
 `config` unchanged.

 `@reload`: CnLifecycleActions emits this after a transition POST succeeds
 so the host can refresh the object. CnWidgetGrid renders widgets with
 `v-bind` only (no `v-on`), so nothing above this widget would ever hear the
 event on a v2 slot-grid page. The v2 detail context is a "read-through"
 accessor (`CnPageRenderer.loadDetailObject` — `objectData` is a getter
 reading `store.getObject(type, id)` live) with its own `or-object-{id}`
 live-update subscription, so the status/actions display normally refreshes
 on its own once OpenRegister broadcasts the save. `onReload` below is a
 best-effort belt-and-braces refetch through the SAME store instance
 (forwarded in as the merged `store` prop) for the case live updates are
 disabled/delayed — it does not replace the live-update path, it backstops
 it.
-->
<template>
	<CnLifecycleActions
		:object="objectData"
		:objectId="objectId"
		:config="config"
		@reload="onReload" />
</template>

<script>
import { CnLifecycleActions } from '@conduction/nextcloud-vue'

export default {
	name: 'LifecycleActionsWidget',

	components: { CnLifecycleActions },

	props: {
		/** The full current record, merged in by CnWidgetGrid's detail context. */
		objectData: { type: Object, default: null },
		/** The current record's id, merged in by CnWidgetGrid's detail context. */
		objectId: { type: [String, Number], default: '' },
		/** The registered object-type slug, merged in by CnWidgetGrid's detail context. */
		objectType: { type: String, default: '' },
		/** The live Pinia object store instance, merged in by CnWidgetGrid's detail context. */
		store: { type: Object, default: null },
		/** The manifest's `{ field, transitions }` lifecycle config, forwarded verbatim. */
		config: { type: Object, default: () => ({}) },
	},

	methods: {
		/**
		 * Best-effort refetch of the current object through the SAME store
		 * instance CnPageRenderer's read-through context already reads from,
		 * backstopping the `or-object-{id}` live-update subscription (see the
		 * module docblock).
		 *
		 * @return {Promise<void>}
		 */
		async onReload() {
			if (!this.store || typeof this.store.fetchObject !== 'function') return
			if (!this.objectType || !this.objectId) return
			try {
				await this.store.fetchObject(this.objectType, String(this.objectId))
			} catch {
				// Best-effort only — the live-update subscription is the
				// primary refresh path; a failed backstop fetch is not fatal.
			}
		},
	},
}
</script>
