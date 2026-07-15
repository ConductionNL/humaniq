/**
 * HRMQ landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the pattern used
 * by the other docs sites in the fleet (openregister, hermiq, shillinq).
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
} from '@conduction/docusaurus-preset/components';

/* HRMQ glyph — the app's Material Design Icons "account-tie" mark
   (same path as img/app.svg / img/app-store.svg at the app root). */
const HRMQ_ICON = (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="0" xmlns="http://www.w3.org/2000/svg">
    <path fill="currentColor" d="M12 3C14.21 3 16 4.79 16 7S14.21 11 12 11 8 9.21 8 7 9.79 3 12 3M16 13.54C16 14.6 15.72 17.07 13.81 19.83L13 15L13.94 13.12C13.32 13.05 12.67 13 12 13S10.68 13.05 10.06 13.12L11 15L10.19 19.83C8.28 17.07 8 14.6 8 13.54C5.61 14.24 4 15.5 4 17V21H20V17C20 15.5 18.4 14.24 16 13.54Z" />
  </svg>
);

const TAGLINE = (
  <>
    HRMQ is open-source HR and payroll administration for Dutch and EU
    employers, built on the OpenRegister data layer. Timesheets, expense
    claims, leave and verzuim (Poortwachter), onboarding through
    offboarding, and the only open-source Dutch payroll calculation
    engine — all audited by a versioned, machine-checkable labour and
    wage-tax rule corpus.
  </>
);

/* --- Generic mock widget panels --------------------------------------
   Token-only abstractions of HRMQ's real surfaces. */

function PayrollPanel() {
  const rows = [
    { label: 'Bruto loon', w: '85%', tone: 'var(--c-cobalt-300)' },
    { label: 'Loonheffing', w: '30%', tone: 'var(--c-lavender-300)' },
    { label: 'Netto loon', w: '60%', tone: 'var(--c-mint-500)' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              height: 6,
              background: 'var(--c-cobalt-50)',
              borderRadius: 1,
              overflow: 'hidden',
            }}
          >
            <div
              style={{
                height: '100%',
                width: row.w,
                background: row.tone,
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function LifecyclePanel() {
  const steps = ['concept', 'klaargezet', 'bevestigd', 'verzonden'];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      {steps.map((step, i) => (
        <div key={step} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span
            style={{
              width: 8,
              height: 8,
              borderRadius: '50%',
              background: i === steps.length - 1 ? 'var(--c-mint-500)' : 'var(--c-cobalt-200)',
              flexShrink: 0,
            }}
          />
          <span
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 10,
              color: 'var(--c-cobalt-700)',
            }}
          >
            {step}
          </span>
        </div>
      ))}
    </div>
  );
}

function ComplianceRulePanel() {
  const lines = ['{', '  "framework": "nl-arbeidstijdenwet",', '  "severity": "mandatory",', '  "machineCheckable": true', '}'];
  return (
    <div
      style={{
        fontFamily: 'var(--conduction-typography-font-family-code)',
        fontSize: 10,
        lineHeight: 1.6,
        color: 'var(--c-cobalt-700)',
        display: 'flex',
        flexDirection: 'column',
        gap: 2,
      }}
    >
      {lines.map((line, i) => (
        <div key={i}>{line}</div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Gross-to-net, table-driven',
    desc: 'A pure, stateless PayrollCalculator computes the NL Rekenvoorschriften 2026 chain in integer cents over a versioned nl-2026.json tax-year parameter file. occ hrmq:payroll:run creates draft runs and payslips; occ hrmq:payroll:verify audits them against the same rule corpus that audits hand-entered data.',
    panel: <PayrollPanel />,
  },
  {
    title: 'Declarative lifecycles, everywhere',
    desc: 'Timesheets, expenses, leave requests, sickness cases, loonaangifte filings, pension UPA filings — every workflow is a declarative x-openregister-lifecycle state machine on the OpenRegister register, not bespoke PHP transition code.',
    panel: <LifecyclePanel />,
  },
  {
    title: 'A versioned, machine-checkable rule corpus',
    desc: 'occ hrmq:rules:audit reports enforced ÷ machine-checkable coverage across payroll, labour and privacy rule domains — Dutch labour law, EU directives, GDPR for employee data — sourced and cited, never invented.',
    panel: <ComplianceRulePanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="HRMQ — open-source Dutch HR & payroll for Nextcloud"
      description="HRMQ is open-source HR and payroll administration for Dutch and EU employers, built on the OpenRegister data layer."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          status={{ label: 'Beta', color: 'var(--c-orange-knvb)' }}
          locales="NL · EN"
          title="HRMQ"
          tagline={TAGLINE}
          primaryCta={{
            label: 'View on Codeberg',
            href: 'https://codeberg.org/Conduction/hrmq',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          iconColor="var(--c-orange-knvb)"
          icon={HRMQ_ICON}
        />

        <WidgetShelf
          eyebrow="What you start with"
          title="HR and payroll, governed by OpenRegister."
          lede="Timesheets, expenses, leave & verzuim, onboarding through offboarding, recruiting, performance reviews, an org chart, an asset register, and the open-source Dutch payroll engine — all backed by a versioned labour and wage-tax rule corpus."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
