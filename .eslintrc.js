// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

module.exports = {
	extends: [
		'@nextcloud',
	],
	rules: {
		// Allow unused i18n functions (t, n) — imported for future translation wiring
		'no-unused-vars': ['error', { varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_', ignoreRestSiblings: true }],
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		'n/no-missing-import': 'off',
		'import/namespace': 'off',
		'import/default': 'off',
		'import/no-named-as-default': 'off',
		'import/no-named-as-default-member': 'off',
	},
}
