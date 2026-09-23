---
title: Where it is enforced
slug: enforcement
order: 30
summary: One verdict, four doors — and what is deliberately not guarded.
---

One verdict, four doors. The enforcement points do not decide anything — they only ask.

## One service decides

Everything funnels through a single access service that returns a verdict: allowed or not, which
rule decided, and why. Nothing else in the plugin makes an access decision.

This is not architectural tidiness for its own sake. Re-implementing a slice of the logic locally
is exactly how an access plugin ends up allowing at one door what it refuses at another — and how a
fix applied in one place quietly fails to apply in the other three. If a fifth surface ever needs
guarding, it asks the same service.

## The four doors

| Point | What it protects |
| --- | --- |
| **Request guard** | The page request itself. Hooks the first controller action of a site request, so it already knows which entry the URL resolved to. |
| **Query filter** | Listings, search results, RSS feeds, sitemaps and GraphQL — they are all element queries, so one hook covers every one of them. |
| **File route** (Pro) | The bytes. A protected asset's URL is rewritten to a guarded route that re-checks access on every hit. |
| **Twig API** | Teasers, paywalls, and any part of a page you want to gate without gating the page. |

A plugin that did only the first would be security theatre: the entry 403s, but its title is still
in the index, its excerpt is still in the search results, and the PDF attached to it still
downloads. All four exist because all four leak.

## Why the query filter matters most

It is the one people underestimate. Turning it off — there is a setting — leaves protected titles
in every listing on the site while the pages themselves refuse, which reads to a visitor as a
broken site rather than a private one.

It is also the one with a documented escape hatch, because sites legitimately want to *list* what
they are not showing:

```twig
{% set locked = craft.entries.section('members').bouncer(false).all() %}
```

"12 more articles for subscribers" needs this. Without a documented way to turn the filter off,
whoever needs it will find an undocumented one.

## What is deliberately not guarded

**Previews, share tokens and the control panel.** Those are Craft's own ways of showing somebody
content they were already given access to. Gating them makes the plugin look broken rather than
making the site safer — an author who cannot preview their own draft will reasonably conclude the
plugin is at fault, and they will be right.

Craft's own routing is also not filtered. Craft matches an element URL by running an element query
for that URI, so a filter that did not stand down during routing would hide the protected entry
from Craft's own lookup — and the request would 404 before the guard ever saw it. The rule's
configured response would never happen, and the plugin would look like it was silently breaking
URLs. The filter stands down for routing and resumes at the first controller action.
