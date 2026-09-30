<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 YearTransitionWidget (payroll-pack-and-cao-updates D4/D5, REQ-PKU-002).

 For a year the administrator enters, shows which pack and which tax tables
 that year is paid with, whether each comes with the app or was uploaded,
 which figures are unconfirmed, and whether the pack reproduces its own test
 payslips. Reads GET /api/payroll/packs/resolution, the same answer occ
 humaniq:payroll:year-transition prints.
-->
<template>
	<div class="year-transition">
		<p class="year-transition__intro">
			{{ t('humaniq', 'Before the first payroll run of a new year, check which pack and tax tables that year will be paid with.') }}
		</p>

		<form class="year-transition__form" @submit.prevent="check">
			<NcTextField v-model="year"
				:label="t('humaniq', 'Tax year')"
				type="number"
				min="2000"
				max="2100"
				required />
			<NcButton variant="primary" type="submit" :disabled="loading">
				<template #icon>
					<NcLoadingIcon v-if="loading" />
				</template>
				{{ t('humaniq', 'Check year') }}
			</NcButton>
		</form>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>
		<NcNoteCard v-else-if="answer && !answer.resolves" type="warning">
			{{ t('humaniq', 'No pack resolves for {jurisdiction} {year}. Upload a pack with its tax tables first.', { jurisdiction: answer.jurisdiction, year: answer.year }) }}
		</NcNoteCard>
		<dl v-else-if="answer" class="year-transition__answer">
			<dt>{{ t('humaniq', 'Pack') }}</dt>
			<dd>{{ answer.engineVersion }} ({{ originLabel(answer.packOrigin) }})</dd>
			<dt>{{ t('humaniq', 'Tax tables') }}</dt>
			<dd>{{ answer.tablesId }} ({{ originLabel(answer.tablesOrigin) }})</dd>
			<dt>{{ t('humaniq', 'Test payslips') }}</dt>
			<dd>
				<span v-if="answer.selfTest.passed">{{ t('humaniq', 'Passed') }}</span>
				<span v-else>{{ t('humaniq', 'Failed: {message}', { message: answer.selfTest.message }) }}</span>
			</dd>
			<dt>{{ t('humaniq', 'Unconfirmed figures') }}</dt>
			<dd>{{ answer.provenance.length > 0 ? answer.provenance.join(', ') : t('humaniq', 'None') }}</dd>
		</dl>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'

export default {
	name: 'YearTransitionWidget',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			year: String(new Date().getFullYear() + 1),
			answer: null,
			loading: false,
			errorMessage: '',
		}
	},

	methods: {
		/**
		 * Where a pack or tables came from, in words.
		 *
		 * @param {string} origin bundled or uploaded.
		 * @return {string}
		 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
		 */
		originLabel(origin) {
			return origin === 'bundled'
				? this.t('humaniq', 'comes with the app')
				: this.t('humaniq', 'uploaded')
		},

		/**
		 * Ask which pack and tables the year resolves to.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
		 */
		async check() {
			this.loading = true
			this.errorMessage = ''
			this.answer = null
			try {
				const response = await axios.get(generateUrl('/apps/humaniq/api/payroll/packs/resolution'), { params: { year: this.year } })
				this.answer = response.data
			} catch (error) {
				this.errorMessage = error?.response?.data?.error || this.t('humaniq', 'The year could not be checked.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.year-transition {
	padding: 20px;

	&__intro {
		color: var(--color-text-maxcontrast);
		margin-bottom: 16px;
		max-width: 720px;
	}

	&__form {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: 12px;
		margin-bottom: 24px;
	}

	&__answer {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: 8px 24px;

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
		}
	}
}
</style>
