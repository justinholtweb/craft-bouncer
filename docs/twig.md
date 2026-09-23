---
title: Twig API
slug: twig
order: 70
summary: Paywalls, teasers, verdicts, signed links, and opting a query out of the filter.
---

For the pages that are not all-or-nothing.

The request guard gates a whole page. Plenty of pages should stay public and simply show less — a
paywall with the first two paragraphs above it, an index that says how many entries it is not
showing. That is what this is for.

## A paywall in six lines

```twig
{% if craft.bouncer.can(entry) %}
    {{ entry.body }}
{% else %}
    {{ entry.body|teaser(55) }}
    <a href="/subscribe">Read the rest</a>
{% endif %}
```

## The full surface

| Call | Returns |
| --- | --- |
| `craft.bouncer.can(subject)` | Whether this visitor may have it. Takes an element, a URI string, or `'@ruleHandle'`. |
| `craft.bouncer.cannot(subject)` | The inverse. Exists for readability — `cannot(entry)` reads better than a negation. |
| `craft.bouncer.check(subject)` | The whole verdict: `.allowed`, `.reason`, `.rule`, `.isProtected`, `.passwordWouldUnlock`. |
| `craft.bouncer.isProtected(subject)` | True even for a visitor who is allowed through. Use it to badge content as members-only for the members. |
| `craft.bouncer.signedUrl(asset, seconds)` | A link that works without an account, for a while. See [Protecting files](files). |
| `craft.bouncer.isUnlocked('handle')` | Whether a password-gated rule is unlocked in this session. |
| `value\|teaser(55)` | A preview cut on words, not on markup — so it never leaves a half-open tag behind. |
| `entries\|bouncer` | Drops the ones this visitor may not have, for arrays the query filter cannot reach. |

`can()`, `cannot()` and `check()` take an optional second argument, a user, to ask about somebody
other than the current visitor.

## Why `check()` rather than `can()`

`can()` answers yes or no. `check()` tells you *which rule* refused and *why*, which is what a
refusal page needs in order to say something useful — "this is for subscribers" rather than "access
denied". A template response gets the verdict handed to it for exactly this reason.

## Opting a query out

```twig
{% set locked = craft.entries.section('members').bouncer(false).all() %}
```

Sites legitimately want to list what they are not showing — "12 more articles for subscribers" is a
conversion prompt, not a leak, as long as it is titles and not bodies. This is the documented way
to do it. Without one, whoever needs it will find an undocumented one, and that is worse.

Be deliberate about it: `.bouncer(false)` turns the filter off for that query completely. Pair it
with `isProtected()` so the template knows to render a lock rather than a link.
