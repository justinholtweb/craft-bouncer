---
title: Writing a rule
slug: rules
order: 20
summary: Target, access and response — and why multiple rules combine with AND.
---

A rule has three parts, and they read top to bottom as a sentence: **this content**, for **these
people**, otherwise **that**.

## Target — what is protected

| Type | Scoped by |
| --- | --- |
| `entries` | Sections, and optionally entry types |
| `categories` | Category groups |
| `assets` (Pro) | Volumes |
| `uri` | Glob patterns, e.g. `members/*` |

On Pro, any target can be narrowed further with Craft's own **element condition builder** — the
same one the entry index uses. That is how you express "entries in this section whose *Members
only* switch is on" without needing a section per audience.

A `uri` rule protects URIs that resolve to something. A path that would 404 anyway still 404s; the
rule does not conjure a page in order to refuse from it.

## Access — who gets through

- **Require login** — the simplest useful rule, and enough on its own.
- **User groups**, matched *any* or *all*. *Any* is the OR you will want most of the time.
- **Permissions** — any Craft permission, including ones your own module registers.
- **A shared password** (Pro) — one secret, no accounts. Unlocking is per session.
- **A date window** (Pro) — embargoes and time-limited access.
- **IP allow and deny lists** (Pro) — office-only content, or blocking a range.

These stack *inside* one rule, and a visitor must satisfy every condition the rule sets. That is
what lets a single rule express a real membership tier instead of needing three.

## Response — what a refused visitor gets

Six options, covered in full in [Responses](responses): send to login, redirect, render a
template, ask for the password, 403, or 404.

## How multiple rules combine

**Every rule that targets an element must pass.** AND, not OR.

This is the secure default and the one that surprises nobody: **adding a rule can only ever remove
access, never grant it.** If rule A says *Subscribers* and rule B says *logged in after 1 March*,
an entry both rules target needs both to be satisfied.

"Editors *or* subscribers" is expressible inside a single rule's group list, matched *any* — so
nothing is lost by the strict reading, and the alternative (OR across rules) would mean that adding
a rule could accidentally open something up.

**Ordering decides one thing only:** whose response a refused visitor gets. The first matching rule
that denies supplies it. Reorder rules on the index by dragging.

## Rules live in project config

Every rule is stored under `bouncer.rules.<uid>` in project config, and mirrored into a table for
ordering and fast reads. Project config is the source of truth.

That means rules deploy with the sections they protect, diff in a pull request, and arrive in
production without anybody re-entering them — because an access rule that has to be re-entered by
hand in production is an access rule that one day will not be.

## Test it before you trust it

```sh
php craft bouncer/rules/test /members/handbook
php craft bouncer/rules/test /members/handbook --user-id=5
```

The first form asks the question everybody actually has: *does an anonymous visitor still get
this?* The second asks it as a particular person. Both belong in a deploy check — see
[Console commands](console-commands).
