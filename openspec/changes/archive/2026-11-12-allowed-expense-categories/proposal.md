---
kind: code
---

# The employer chooses which expense categories employees may claim

## Why

Ruben decided on 3 November 2026 that expense claims cover more than travel: an employee can
claim other costs too, when the employer allows them. Today every category in the schema can
be claimed, and an employer has no way to switch one off.

## What changes

- The admin settings page gets a section "Expense categories" with one switch per category:
  travel, accommodation, meals, supplies, software, training and other. Every category is on
  by default.
- The setting is stored as the list of categories that are switched off, in the app config key
  `expense_categories_disabled`. A category added to the schema later is on until an admin
  switches it off.
- The server refuses a claim in a category that is switched off: when the claim is created,
  when its category is changed to that category, and when it is submitted. A claim that is
  already submitted can still be approved, rejected and paid, so switching a category off
  never strands a claim halfway.
- `GET /api/expense-categories` lists each category and whether it is on, for every signed-in
  user. `PUT /api/expense-categories` saves the switches, for admins only, and refuses an
  unknown category.
- humaniq's own writes (the payroll run, repair steps) are not refused.

## Out of scope

- Hiding switched-off categories in the claim form's category list. The form is manifest
  configuration; the server refusal is the rule, and the form can follow.
- A different list per administration.
