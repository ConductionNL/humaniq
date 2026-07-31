// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

// Shared Vue 3 correction layer from @conduction/nextcloud-vue.
//
// `@nextcloud/eslint-config@8` (pulled in via FlatCompat below) resolves
// eslint-plugin-vue's **Vue 2** preset. That is not merely stale: several of
// its rules are INVERTED under Vue 3, and none of the `vue/no-deprecated-*`
// rules are active — so Vue 2 idioms survive a migration silently.
// `beforeDestroy` is the dangerous case: Vue 3 never calls that hook, so a
// component cleaning up an interval or a subscription there leaks with zero
// console output.
//
// `conductionVue3Fixes` is an ARRAY of flat-config objects (language level,
// SFC parser, deprecation rules). It deliberately registers no plugins, so it
// layers cleanly onto the `@nextcloud` base — and must be spread **last** to
// win over the preset it is correcting.
//
// As of `2.1.0-vue3.12` it also disables the two inverted Vue-2 rules
// (`vue/no-v-model-argument`, `vue/no-v-for-template-key`) that forbid
// constructs Vue 3 requires, so those no longer need disabling by hand here.
//
// It enables `vue/v-on-event-hyphenation` with `ignore: ['update:modelValue']`.
// That exception is load-bearing — Nextcloud Vue 3 field components read
// `onUpdate:modelValue` directly via `useModel`, so the hyphenated
// `@update:model-value` form is silently dead.
const {
	conductionVue3Fixes,
} = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	settings: {
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
				],
				extensions: ['.js', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		// Allow unused i18n functions (t, n) — imported for translation wiring.
		'no-unused-vars': ['error', { varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_', ignoreRestSiblings: true }],
		'jsdoc/require-jsdoc': 'off',
		// @spec is the hydra gate-16 spec-traceability tag (ADR-020) — a
		// defined project tag, not a typo.
		'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
		'vue/first-attribute-linebreak': 'off',
		'n/no-missing-import': 'off',
		'import/namespace': 'off',
		'import/default': 'off',
		'import/no-named-as-default': 'off',
		'import/no-named-as-default-member': 'off',
	},
}, ...conductionVue3Fixes])
