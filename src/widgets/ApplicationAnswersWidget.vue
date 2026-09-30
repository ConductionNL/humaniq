<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ApplicationAnswersWidget (hiring-portal-audiences D3, REQ-PTA-002).

 The candidate's answers to the vacancy's own questions, each under the
 question as HR wrote it. Reads the application and its vacancy from
 OpenRegister; an answer to a question that was removed later still shows,
 under its key.
-->
<template>
	<div class="application-answers">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-else-if="rows.length === 0" class="application-answers__empty">
			{{ t('humaniq', 'No answers to vacancy questions') }}
		</p>
		<dl v-else class="application-answers__list">
			<template v-for="row in rows" :key="row.key">
				<dt>{{ row.label }}</dt>
				<dd>{{ row.answer }}</dd>
			</template>
		</dl>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * An OpenRegister object URL.
 *
 * @param {string} schema The schema.
 * @param {string} id The object id.
 * @return {string}
 */
function objectUrl(schema, id) {
	return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', { register: 'humaniq', schema, id })
}

export default {
	name: 'ApplicationAnswersWidget',

	components: { NcLoadingIcon, NcNoteCard },

	props: {
		/** The application's id, handed in by CnDetailWidgetHost's detail context. */
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			rows: [],
			loading: true,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the answers and label them with the vacancy's questions.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PTA-002
		 */
		async load() {
			try {
				const application = (await axios.get(objectUrl('job-application', String(this.objectId)))).data || {}
				const answers = application.answers || {}
				let questions = []
				if (application.vacancyId && Object.keys(answers).length > 0) {
					const vacancy = (await axios.get(objectUrl('Vacancy', String(application.vacancyId)))).data || {}
					questions = Array.isArray(vacancy.questions) ? vacancy.questions : []
				}

				const labels = Object.fromEntries(questions.map((question) => [question.key, question.label || question.key]))
				this.rows = Object.entries(answers).map(([key, answer]) => ({
					key,
					label: labels[key] || key,
					answer: typeof answer === 'boolean' ? (answer ? this.t('humaniq', 'Yes') : this.t('humaniq', 'No')) : String(answer),
				}))
			} catch {
				this.error = this.t('humaniq', 'The answers could not be read.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.application-answers {
	padding: 8px 0;

	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__list {
		display: grid;
		grid-template-columns: minmax(160px, max-content) 1fr;
		gap: 8px 24px;

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
			white-space: pre-wrap;
		}
	}
}
</style>
