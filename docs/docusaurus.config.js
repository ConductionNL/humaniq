// @ts-check

/**
 * Humaniq documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens,
 * theme swizzles for Navbar / Footer, i18n scaffolding, KvK / BTW
 * copyright). Site-specific overrides — locales, sidebar path,
 * mermaid theme, custom prism themes, navbar items — are passed
 * through createConfig() opts.
 *
 * Mirrors the fleet convention (shillinq/pipelinq/procest/openregister
 * docs sites): docs source at the repo root of `docs/`, en (default) +
 * nl locale declared for the navbar dropdown.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

/* createConfig replaces themes wholesale when `themes:` is passed, so
   we re-include the brand theme plugin alongside @docusaurus/theme-mermaid.
   Without the brand theme entry the Navbar/Footer swizzles and
   brand.css auto-load would silently drop. */
const BRAND_THEME = require.resolve('@conduction/docusaurus-preset/theme');

const config = createConfig({
  title: 'Humaniq',
  tagline: 'Open-source Dutch HR & payroll administration for Nextcloud',
  /* Docs subdomain deliberately still `hrmq.conduction.nl` — the DNS move
     to a humaniq subdomain happens separately from the app rename. */
  url: 'https://hrmq.conduction.nl',
  baseUrl: '/',

  organizationName: 'Conduction',
  projectName: 'humaniq',

  /* Locales: en (primary) + nl (declared so the locale dropdown is
     present for translators; Dutch markdown is a follow-up). If SSR
     fails on the nl locale (ADR-030 edge case with stale locale
     metadata), revert `locales` to `['en']` with a one-line comment. */
  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'nl'],
    localeConfigs: {
      en: { label: 'English' },
      nl: { label: 'Nederlands' },
    },
  },

  /* The docs source lives at the repo root of `docs/` rather than
     under a `docs/` subfolder, so we override the preset's default
     `presets:` block to point `docs.path` at './' and disable the
     blog plugin. customCss carries app-specific CSS only — brand
     tokens and the theme swizzles are auto-loaded by the brand theme
     entry in `themes:` below. */
  presets: [
    [
      'classic',
      {
        docs: {
          path: './',
          /* docs.path: './' makes plugin-content-docs scan every file
             in docs/, which collides with plugin-content-pages's own
             scan of docs/src/pages/. Exclude src/ (pages live there)
             plus the standard node_modules/scripts/build buckets. */
          exclude: ['**/node_modules/**', 'src/**', 'scripts/**', 'build/**'],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://github.com/ConductionNL/humaniq/tree/development/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      },
    ],
  ],

  themes: [BRAND_THEME, '@docusaurus/theme-mermaid'],

  /* Brand navbar provides locale dropdown + GitHub by default; we
     replace items[] with Humaniq's own (Documentation sidebar link,
     GitHub link, locale dropdown). Object.assign in createConfig is
     shallow, so items: replaces wholesale. */
  navbar: {
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'tutorialSidebar',
        position: 'left',
        label: 'Documentation',
      },
      {
        href: 'https://github.com/ConductionNL/humaniq',
        label: 'GitHub',
        position: 'right',
      },
      { type: 'localeDropdown', position: 'right' },
    ],
  },

  /* Per-property footer override (preset 1.2.0+): we pass `links` only,
     so the brand `style: 'dark'` and the brand KvK/BTW/IBAN/address
     copyright string both inherit unchanged. */
  footer: {
    links: [
      ...baseFooterLinks().filter((column) => column.title === 'Conduction'),
    ],
  },

  /* Drop the canal-footer mini-games on this product-page footer
     (preset 1.3.0+). The static skyline + canal decoration are kept;
     the interactive layer goes away. */
  minigames: false,

  /* themeConfig is shallow-merged into the preset's defaults
     (colorMode + navbar + footer). prism + mermaid land alongside. */
  themeConfig: {
    prism: {
      theme: require('prism-react-renderer/themes/github'),
      darkTheme: require('prism-react-renderer/themes/dracula'),
    },
    mermaid: {
      theme: { light: 'default', dark: 'dark' },
    },
  },
});

/* createConfig doesn't pass-through arbitrary top-level fields; assign
   onBrokenLinks/onBrokenAnchors/markdown directly so they make it into
   the final Docusaurus config. onBrokenLinks: 'warn' per the prep brief
   — this is a from-scratch site and cross-page links will firm up as
   content grows; broken-link warnings should not fail the build.
   trailingSlash is left at the preset's default. */
config.onBrokenLinks = 'warn';
config.onBrokenAnchors = 'warn';
config.markdown = {
  mermaid: true,
  hooks: {
    onBrokenMarkdownImages: 'warn',
  },
};

module.exports = config;
