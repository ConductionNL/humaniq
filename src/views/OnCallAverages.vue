<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 OnCallAverages (people-flex-contract-rules D4, REQ-FLX-002).

 The average approved hours of every on-call contract over a period HR picks,
 per week and per month, to decide the fixed-hours offer of BW 7:628a lid 5.
 Reads GET /api/contracts/on-call-averages; the CSV link asks the same
 endpoint with format=csv, so the download carries the same figures.
-->
<template>
	<div class="on-call-averages">
		<h2>{{ t('humaniq', 'On-call hours') }}</h2>
		<p class="on-call-averages__intro">
			{{ t('humaniq', 'The average hours each on-call worker worked in the period, from approved timesheets only. After twelve months, offer at least the twelve-month average as fixed hours.') }}
		</p>

		<form class="on-call-averages__form" @submit.prevent="load">
			<NcTextField v-model="from"
				:label="t('humaniq', 'From')"
				type="date"
				required />
			<NcTextField v-model="to"
				:label="t('humaniq', 'To')"
				type="date"
				required />
			<NcButton variant="primary" type="submit" :disabled="loading">
				<template #icon>
					<NcLoadingIcon v-if="loading" />
				</template>
				{{ t('humaniq', 'Show') }}
			</NcButton>
			<NcButton :href="csvUrl" :disabled="loading">
				{{ t('humaniq', 'Download CSV') }}
			</NcButton>
		</form>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>

		<NcEmptyContent v-else-if="loaded && rows.length === 0"
			:name="t('humaniq', 'No on-call contracts in this period')" />

		<table v-else-if="rows.length > 0" class="on-call-averages__table">
			<thead>
				<tr>
					<th>{{ t('humaniq', 'Employee') }}</th>
					<th>{{ t('humaniq', 'Counted from') }}</th>
					<th>{{ t('humaniq', 'Counted to') }}</th>
					<th>{{ t('humaniq', 'Approved hours') }}</th>
					<th>{{ t('humaniq', 'Hours per week') }}</th>
					<th>{{ t('humaniq', 'Hours per month') }}</th>
					<th>{{ t('humaniq', 'Offer due') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.contractId">
					<td>{{ row.name || row.employeeId }}</td>
					<td>{{ row.countedFrom }}</td>
					<td>{{ row.countedTo }}</td>
					<td>{{ row.hours }}</td>
					<td>{{ row.hoursPerWeek }}</td>
					<td>{{ row.hoursPerMonth }}</td>
					<td>{{ row.offerDue ? t('humaniq', 'Yes') : t('humaniq', 'No') }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { getRequestToken } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'

/**
 * A Y-m-d string for a date.
 *
 * @param {Date} date The date.
 * @return {string}
 */
function ymd(date) {
	return date.toISOString().slice(0, 10)
}

export default {
	name: 'OnCallAverages',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		const today = new Date()
		const yearAgo = new Date(today)
		yearAgo.setFullYear(today.getFullYear() - 1)
		yearAgo.setDate(yearAgo.getDate() + 1)

		return {
			from: ymd(yearAgo),
			to: ymd(today),
			rows: [],
			loading: false,
			loaded: false,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * The CSV download for the chosen period. A plain link carries no
		 * requesttoken header, so the token rides in the query.
		 *
		 * @return {string}
		 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
		 */
		csvUrl() {
			return generateUrl('/apps/humaniq/api/contracts/on-call-averages') + '?' + new URLSearchParams({ from: this.from, to: this.to, format: 'csv', requesttoken: getRequestToken() || '' }).toString()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the averages for the chosen period.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
		 */
		async load() {
			this.loading = true
			this.errorMessage = ''
			try {
				const response = await axios.get(generateUrl('/apps/humaniq/api/contracts/on-call-averages'), { params: { from: this.from, to: this.to } })
				this.rows = response.data.rows || []
			} catch (error) {
				this.rows = []
				this.errorMessage = error?.response?.status === 403
					? this.t('humaniq', 'Only HR can read the on-call hours.')
					: this.t('humaniq', 'The on-call hours could not be read. Check the dates and try again.')
			} finally {
				this.loading = false
				this.loaded = true
			}
		},
	},
}
</script>

<style scoped lang="scss">
.on-call-averages {
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

	&__table {
		border-collapse: collapse;
		width: 100%;

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
