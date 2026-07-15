<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Administration switcher (multi-administratie design.md D4/D5). The manifest v2
 `type: "custom"` host-app SFC for the "Configuratie › Administraties" page:
 the ADR-001 Rule 3 active-administratie indicator + switch. It GETs the
 caller's context (active id + accessible administraties) from
 `/api/administration/context` and POSTs a switch to
 `/api/administration/active` (guarded server-side by
 AdministrationController::setActive — a caller can never activate an
 administratie they have no AdministrationAccess row for). On a successful
 switch it also writes the chosen id into the page workspace context
 (`activeAdministrationId`) so sibling manifest pages' `@workspace.
 activeAdministrationId?` base filters re-scope — the documented INTERIM
 mechanism until the upstream `@administration` filter token lands in
 @conduction/nextcloud-vue (design.md D3); the per-user pointer the endpoint
 persists is the durable source of truth the backend stamps into
 `runtime.user.activeAdministrationId` for that future token.

 There is deliberately no register/schema on this page and no object
 create/save call: the switch is a per-user IConfig pointer, not domain data.
-->
<template>
	<div class="administration-switcher">
		<h2>{{ t('hrmq', 'Administrations') }}</h2>
		<p class="administration-switcher__intro">
			{{ t('hrmq', 'Choose the active administration. Every list and detail page in the app is scoped to it. You can only switch to administrations you have access to.') }}
		</p>

		<NcNoteCard type="warning" class="administration-switcher__note">
			{{ t('hrmq', 'Scoping is a convenience, not a security boundary — a determined user could still read another administration\'s data through the raw OpenRegister API. True per-tenant isolation (mapping each administration onto an OpenRegister organisation) is a named hardening follow-up.') }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" class="administration-switcher__loading" />

		<NcNoteCard v-else-if="errorMessage" type="error" class="administration-switcher__note">
			{{ errorMessage }}
		</NcNoteCard>

		<div v-else-if="administrations.length === 0" class="administration-switcher__empty">
			{{ t('hrmq', 'You have no administration access rows. Ask an administrator to grant you access.') }}
		</div>

		<ul v-else class="administration-switcher__list">
			<li v-for="administration in administrations"
				:key="administration.administrationId"
				class="administration-switcher__item">
				<NcButton :type="administration.administrationId === activeAdministrationId ? 'primary' : 'secondary'"
					:disabled="switching"
					class="administration-switcher__button"
					@click="switchTo(administration.administrationId)">
					<template #icon>
						<Check v-if="administration.administrationId === activeAdministrationId" :size="20" />
						<Domain v-else :size="20" />
					</template>
					{{ administration.name }}
					<span class="administration-switcher__role">({{ administration.role }})</span>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Domain from 'vue-material-design-icons/Domain.vue'

export default {
	name: 'AdministrationSwitcher',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Check,
		Domain,
	},

	data() {
		return {
			activeAdministrationId: null,
			administrations: [],
			loading: true,
			switching: false,
			errorMessage: '',
		}
	},

	mounted() {
		this.loadContext()
	},

	methods: {
		/**
		 * GET the caller's active administratie id + accessible
		 * administraties from the guarded context endpoint.
		 *
		 * @return {Promise<void>}
		 */
		async loadContext() {
			this.loading = true
			this.errorMessage = ''

			try {
				const response = await axios.get(generateUrl('/apps/hrmq/api/administration/context'))
				this.activeAdministrationId = response.data.activeAdministrationId ?? null
				this.administrations = response.data.administrations ?? []
			} catch (error) {
				this.errorMessage = error?.response?.data?.error
					|| this.t('hrmq', 'Could not load your administrations. Try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * POST a switch to the guarded setter. The server refuses any
		 * administratie the caller has no access row for (404) before
		 * persisting. On success, mirror the choice into the page workspace
		 * context so sibling pages re-scope (the documented interim
		 * mechanism, design.md D3).
		 *
		 * @param {string} administrationId The administratie id to activate.
		 * @return {Promise<void>}
		 */
		async switchTo(administrationId) {
			this.switching = true
			this.errorMessage = ''

			try {
				const response = await axios.post(generateUrl('/apps/hrmq/api/administration/active'), {
					administrationId,
				})
				this.activeAdministrationId = response.data.activeAdministrationId ?? administrationId
				this.$emit('cn:workspace:set', { key: 'activeAdministrationId', value: this.activeAdministrationId })
			} catch (error) {
				this.errorMessage = error?.response?.data?.error
					|| this.t('hrmq', 'Could not switch administration. You may not have access to it.')
			} finally {
				this.switching = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.administration-switcher {
	max-width: 640px;
	padding: 20px;

	&__intro {
		color: var(--color-text-maxcontrast);
		margin-bottom: 16px;
	}

	&__note {
		margin-bottom: 16px;
	}

	&__loading {
		margin: 24px auto;
	}

	&__empty {
		color: var(--color-text-maxcontrast);
		padding: 16px 0;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 8px;
		list-style: none;
		padding: 0;
		margin: 0;
	}

	&__button {
		width: 100%;
	}

	&__role {
		color: var(--color-text-maxcontrast);
		margin-inline-start: 6px;
	}
}
</style>
