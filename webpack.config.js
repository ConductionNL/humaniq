// SPDX-License-Identifier: EUPL-1.2
const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

// The base @nextcloud/webpack-vue-config hardcodes
//   output.publicPath = '/apps/<appName>/js/'
// which is wrong when the app lives in `apps-extra/`. The main entry script is
// injected by PHP (Util::addScript) with the correct `/custom_apps/hrmq/js/`
// webroot, but webpack's runtime loader uses output.publicPath for dynamically
// imported chunks (CnIndexPage, CnAdvancedFormDialog, CnMapWidget), so those get
// requested from `/apps/hrmq/js/...` instead - a path NC's PHP router intercepts
// and answers with the SPA shell HTML instead of JS, leaving every index page's
// <main> empty. 'auto' makes webpack derive the public path from the URL of the
// executing entry script at runtime, so lazy chunks load from wherever the app
// is actually mounted. Mirrors openregister and pipelinq.
webpackConfig.output = {
	...webpackConfig.output,
	publicPath: 'auto',
}

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'hrmq'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
}

// Build against the DECLARED dependency unless a developer deliberately asks
// for the sibling source. This used to be opt-OUT — `USE_LOCAL_LIB !== 'false'`
// — which meant the alias engaged automatically on any machine that happened to
// have `../nextcloud-vue` checked out. That is every fleet dev box, so the
// bundle was compiled from whatever branch that unrelated repo was sitting on,
// while CI (which checks out only this repo, so the path does not exist) built
// from the npm package. Local and CI were building different code by default,
// and nothing said so.
//
// Measured 2026-08-19, same source, same machine, minutes apart:
//   npm run build                          -> 13 errors, NO bundle emitted
//   USE_LOCAL_LIB=false npm run build      -> clean, bundle emitted
//
// The 13 errors were one root cause: webpack resolved
// `../nextcloud-vue/node_modules/vue-codemirror6/node_modules/vue-demi`, whose
// `lib/index.mjs` is the 1982-byte Vue 2 shim (`export { Vue, Vue2, isVue2 }`),
// where this repo's hoisted copy is the 524-byte Vue 3 passthrough. vue-demi
// rewrites that file at postinstall to match whichever Vue it resolved against,
// so a sibling checkout installed under Vue 2 poisons a Vue 3 app's build. That
// is exactly the dual-package hazard the singleton aliases below exist to
// prevent — vue-demi simply was not on the list. It is now.
//
// Monorepo dev still works, deliberately: `USE_LOCAL_LIB=true npm run build`.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)

if (process.env.USE_LOCAL_LIB === 'true' && !fs.existsSync(localLib)) {
	// Asked for explicitly and not there: say so rather than silently building
	// something else. A flag that is ignored when it cannot be honoured is how
	// a build ends up not being the build you asked for.
	throw new Error(`USE_LOCAL_LIB=true but ${localLib} does not exist.`)
}

console.log(useLocalLib
	? `[webpack] @conduction/nextcloud-vue -> LOCAL SOURCE ${localLib}`
	: '[webpack] @conduction/nextcloud-vue -> npm package (node_modules)')

webpackConfig.resolve = {
	extensions: ['.vue', '.js', '.mjs'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		//
		// ⚠️ An alias that resolves to a package DIRECTORY makes webpack fall
		// back to `main`/`mainFields` and skip the package's `exports` map
		// entirely. `@nextcloud/vue@9` ships NO `main` and NO `module` — it is
		// reachable only through `exports["."] -> ./dist/index.mjs` — so the
		// previous directory alias produced 232 `Can't resolve '@nextcloud/vue'`
		// errors, one per importing module, including from inside
		// @conduction/nextcloud-vue's own dist. Point singleton aliases at a
		// concrete entry FILE. (`vue` and `pinia` still declare `main`, so a
		// directory alias resolves for them.)
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		// vue-demi resolves to a DIFFERENT FILE depending on which Vue was
		// installed alongside it — its postinstall rewrites lib/index.mjs in
		// place. A nested copy inside a sibling checkout can therefore be the
		// Vue 2 shim while this app is Vue 3, and webpack prefers the nested
		// one. Pin it to this repo's hoisted, Vue-3 copy.
		'vue-demi$': path.resolve(__dirname, 'node_modules/vue-demi'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue/dist/index.mjs'),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// The former `@nextcloud/dialogs` directory alias is GONE.
//
// It existed to stop "the nextcloud-vue submodule's nested deps (Vue 3)"
// leaking into a Vue 2 app — a rationale this migration inverts, since the app
// is now Vue 3 and there is a single hoisted @nextcloud/dialogs@7.4.1. Worse,
// it was the same latent landmine as the `@nextcloud/vue` alias above: dialogs
// v7 ships NO `main`, only `exports`, so aliasing the bare specifier to the
// package DIRECTORY makes every `import … from '@nextcloud/dialogs'` unresolvable.
// The companion `style.css$` alias is likewise unnecessary — v7's exports map
// publishes `./style.css` directly.

// @nextcloud/dialogs drags in a FilePicker chunk that imports node's `path`, and
// webpack 5 no longer auto-polyfills node core modules — without this the bundle
// fails to emit with "Can't resolve 'path'". This app does not use the FilePicker
// API, so the code path never runs and an empty module is safe.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: false,
}

module.exports = webpackConfig
