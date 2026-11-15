<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SurveyAnswerWidget (talent-engagement-surveys D1, REQ-SRV-002).

 The employee answers a survey from their own invitation. The questions come
 from the survey the invitation names; the answers go to
 POST /api/surveys/{id}/responses, which marks the invitation answered and
 stores the answers without anything that names the employee.
-->
<template>
	<div class="survey-answer">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="answered" type="success">
			{{ t('humaniq', 'You answered this survey. Thank you.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!survey || survey.status !== 'open'" type="info">
			{{ t('humaniq', 'This survey does not take answers now.') }}
		</NcNoteCard>
		<form v-else class="survey-answer__form" @submit.prevent="submit">
			<p v-if="survey.introduction" class="survey-answer__intro">
				{{ survey.introduction }}
			</p>
			<template v-for="question in questions">
				<fieldset v-if="question.type !== 'textarea'"
					:key="question.key"
					class="survey-answer__question">
					<legend>
						{{ question.label }}<span v-if="question.required" class="survey-answer__required"> ({{ t('humaniq', 'required') }})</span>
					</legend>
					<div class="survey-answer__choices">
						<label v-for="choice in choicesOf(question)"
							:key="question.key + '-' + choice"
							class="survey-answer__choice">
							<input v-model="answers[question.key]"
								type="radio"
								:name="question.key"
								:value="choice">
							<span>{{ choice }}</span>
						</label>
					</div>
					<p v-if="question.type === 'recommend'" class="survey-answer__hint">
						{{ t('humaniq', '0 is not at all likely, 10 is very likely.') }}
					</p>
				</fieldset>
				<div v-else :key="question.key" class="survey-answer__question">
					<label :for="'survey-answer-' + question.key">{{ question.label }}</label>
					<textarea :id="'survey-answer-' + question.key"
						v-model="answers[question.key]"
						rows="4"
						maxlength="2000" />
				</div>
			</template>
			<p class="survey-answer__privacy">
				{{ t('humaniq', 'Your answers are stored without your name. HR only sees that you answered.') }}
			</p>
			<NcButton type="submit" variant="primary" :disabled="sending">
				<template #icon>
					<NcLoadingIcon v-if="sending" />
				</template>
				{{ t('humaniq', 'Send answers') }}
			</NcButton>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'SurveyAnswerWidget',

	components: { NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** The invitation, handed in by CnDetailWidgetHost's detail context. */
		objectData: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			survey: null,
			answers: {},
			loading: true,
			sending: false,
			sent: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The survey's questions that have a key.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
		 */
		questions() {
			const questions = Array.isArray(this.survey?.questions) ? this.survey.questions : []
			return questions.filter((question) => question && String(question.key || '').trim() !== '')
		},

		/**
		 * Whether the invitation is answered, now or before.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
		 */
		answered() {
			return this.sent || this.objectData?.status === 'beantwoord'
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the survey the invitation names.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
		 */
		async load() {
			this.loading = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', { register: 'humaniq', schema: 'Survey', id: this.objectData?.surveyId || '' })
				const response = await axios.get(url)
				this.survey = response.data || null
			} catch {
				this.error = this.t('humaniq', 'The survey could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The values an employee picks from: the choices, 1 to 5 for a scale
		 * (or its own range), 0 to 10 for the recommend question.
		 *
		 * @param {object} question The question.
		 * @return {Array<string|number>}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
		 */
		choicesOf(question) {
			if (question.type === 'enum') {
				return Array.isArray(question.options) ? question.options : (question.options?.choices || [])
			}
			const low = question.type === 'recommend' ? 0 : Number(question.options?.min ?? 1)
			const high = question.type === 'recommend' ? 10 : Number(question.options?.max ?? 5)
			const values = []
			for (let value = low; value <= high; value++) {
				values.push(value)
			}
			return values
		},

		/**
		 * Send the answers.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
		 */
		async submit() {
			this.sending = true
			this.error = ''
			try {
				const url = generateUrl('/apps/humaniq/api/surveys/{id}/responses', { id: this.objectData?.surveyId || '' })
				await axios.post(url, { answers: this.answers })
				this.sent = true
			} catch (e) {
				this.error = e?.response?.data?.message || this.t('humaniq', 'Your answers could not be sent.')
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.survey-answer {
	padding: 8px 0;

	&__form {
		display: flex;
		flex-direction: column;
		gap: 16px;
		max-width: 720px;
	}

	&__question {
		border: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;

		legend,
		label {
			font-weight: bold;
		}

		textarea {
			width: 100%;
		}
	}

	&__choices {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 16px;
	}

	&__choice {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-weight: normal !important;
	}

	&__required,
	&__hint,
	&__privacy {
		color: var(--color-text-maxcontrast);
		font-weight: normal;
	}
}
</style>
