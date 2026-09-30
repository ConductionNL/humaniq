<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PayrollPacksWidget (payroll-pack-and-cao-updates D5, REQ-PKU-001/003).

 Upload a payroll pack with its tax tables, see the gate that refused them in
 the validator's own words, list the uploaded packs and withdraw a wrong one.
 Posts to POST /api/payroll/packs, reads GET /api/payroll/packs and posts
 POST /api/payroll/packs/{id}/deactivate. Every check is on the server; this
 only reads the chosen files and shows the answer.
-->
<template>
	<div class="payroll-packs">
		<p class="payroll-packs__intro">
			{{ t('humaniq', 'Load next year\'s pack and tax tables before an app update brings them. Both are checked together: the pack must reproduce its own test payslips with these tables, or nothing is stored.') }}
		</p>

		<form class="payroll-packs__form" @submit.prevent="upload">
			<label class="payroll-packs__file">
				<span>{{ t('humaniq', 'Pack file (JSON)') }}</span>
				<input ref="packFile"
					type="file"
					accept=".json,application/json"
					required
					@change="packName = fileName($event)">
			</label>
			<label class="payroll-packs__file">
				<span>{{ t('humaniq', 'Tax tables file (JSON), when the app does not ship them') }}</span>
				<input ref="tablesFile"
					type="file"
					accept=".json,application/json"
					@change="tablesName = fileName($event)">
			</label>
			<NcCheckboxRadioSwitch v-model="override" type="checkbox">
				{{ t('humaniq', 'Use this pack instead of the one that comes with the app for the same year') }}
			</NcCheckboxRadioSwitch>
			<NcButton variant="primary" type="submit" :disabled="uploading || packName === ''">
				<template #icon>
					<NcLoadingIcon v-if="uploading" />
				</template>
				{{ t('humaniq', 'Upload pack') }}
			</NcButton>
		</form>

		<NcNoteCard v-if="uploadError" type="error">
			{{ uploadError }}
		</NcNoteCard>
		<NcNoteCard v-else-if="uploaded" type="success">
			{{ t('humaniq', 'Pack {pack} is active for {year}.', { pack: uploaded.engineVersion, year: uploaded.taxYear }) }}
			<span v-if="uploaded.provenance">
				{{ t('humaniq', 'Unconfirmed figures: {list}', { list: uploaded.provenance }) }}
			</span>
		</NcNoteCard>

		<NcNoteCard v-if="listError" type="error">
			{{ listError }}
		</NcNoteCard>
		<NcEmptyContent v-else-if="loaded && packs.length === 0"
			:name="t('humaniq', 'No uploaded packs. The packs that come with the app are used for their own year.')" />
		<table v-else-if="packs.length > 0" class="payroll-packs__table">
			<thead>
				<tr>
					<th scope="col">{{ t('humaniq', 'Pack') }}</th>
					<th scope="col">{{ t('humaniq', 'Tax year') }}</th>
					<th scope="col">{{ t('humaniq', 'Tables') }}</th>
					<th scope="col">{{ t('humaniq', 'Active') }}</th>
					<th scope="col">{{ t('humaniq', 'Unconfirmed figures') }}</th>
					<th scope="col">
						<span class="hidden-visually">{{ t('humaniq', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="pack in packs" :key="pack.id">
					<td>{{ pack.engineVersion }}</td>
					<td>{{ pack.taxYear }}</td>
					<td>{{ pack.tables }}</td>
					<td>{{ pack.active ? t('humaniq', 'Yes') : t('humaniq', 'No') }}</td>
					<td>{{ pack.provenance || t('humaniq', 'None') }}</td>
					<td class="payroll-packs__actions">
						<template v-if="pack.active && confirming === pack.id">
							<NcButton variant="error" :disabled="withdrawing" @click="withdraw(pack)">
								{{ t('humaniq', 'Withdraw {pack}', { pack: pack.engineVersion }) }}
							</NcButton>
							<NcButton @click="confirming = ''">
								{{ t('humaniq', 'Keep it') }}
							</NcButton>
						</template>
						<NcButton v-else-if="pack.active" @click="confirming = pack.id">
							{{ t('humaniq', 'Withdraw') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * Read a chosen file as JSON.
 *
 * @param {File|undefined} file The file.
 * @return {Promise<object|null>}
 */
async function readJson(file) {
	if (!file) {
		return null
	}

	return JSON.parse(await file.text())
}

export default {
	name: 'PayrollPacksWidget',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			packName: '',
			tablesName: '',
			override: false,
			uploading: false,
			uploadError: '',
			uploaded: null,
			packs: [],
			loaded: false,
			listError: '',
			confirming: '',
			withdrawing: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The name of the file an input now holds.
		 *
		 * @param {Event} event The change event.
		 * @return {string}
		 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
		 */
		fileName(event) {
			return event.target.files?.[0]?.name || ''
		},

		/**
		 * Read the uploaded packs.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
		 */
		async load() {
			this.listError = ''
			try {
				const response = await axios.get(generateUrl('/apps/humaniq/api/payroll/packs'))
				this.packs = response.data.packs || []
			} catch (error) {
				this.packs = []
				this.listError = error?.response?.status === 403
					? this.t('humaniq', 'Only administrators manage payroll packs.')
					: this.t('humaniq', 'The uploaded packs could not be read.')
			} finally {
				this.loaded = true
			}
		},

		/**
		 * Post the chosen pack and tables, and show what the server decided.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
		 */
		async upload() {
			this.uploading = true
			this.uploadError = ''
			this.uploaded = null
			try {
				let pack
				let tables
				try {
					pack = await readJson(this.$refs.packFile.files[0])
					tables = await readJson(this.$refs.tablesFile.files[0])
				} catch {
					this.uploadError = this.t('humaniq', 'A chosen file is not valid JSON.')
					return
				}

				const response = await axios.post(generateUrl('/apps/humaniq/api/payroll/packs'), { pack, tables, override: this.override })
				this.uploaded = response.data
				await this.load()
			} catch (error) {
				this.uploadError = error?.response?.data?.error || this.t('humaniq', 'The pack could not be uploaded.')
			} finally {
				this.uploading = false
			}
		},

		/**
		 * Withdraw an uploaded pack. Runs already calculated keep the pack
		 * they were paid with.
		 *
		 * @param {object} pack The pack row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-003
		 */
		async withdraw(pack) {
			this.withdrawing = true
			this.listError = ''
			try {
				await axios.post(generateUrl('/apps/humaniq/api/payroll/packs/{id}/deactivate', { id: pack.id }))
				this.confirming = ''
				await this.load()
			} catch (error) {
				this.listError = error?.response?.data?.error || this.t('humaniq', 'The pack could not be withdrawn.')
			} finally {
				this.withdrawing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.payroll-packs {
	padding: 20px;

	&__intro {
		color: var(--color-text-maxcontrast);
		margin-bottom: 16px;
		max-width: 720px;
	}

	&__form {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 12px;
		margin-bottom: 24px;
	}

	&__file {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__actions {
		display: flex;
		gap: 8px;
	}

	&__table {
		border-collapse: collapse;
		width: 100%;
		margin-top: 16px;

		th,
		td {
			border-bottom: 1px solid var(--color-border);
			padding: 8px;
			text-align: start;
		}

		th {
			color: var(--color-text-maxcontrast);
			font-weight: normal;
		}
	}
}
</style>
