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

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = process.env.USE_LOCAL_LIB !== 'false' && fs.existsSync(localLib)

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
