---
title: FAQ
slug: faq
order: 130
summary: How Bouncer differs from content-gating plugins, and the questions worth asking first.
---

## How is this different from the content-gating plugins that already exist?

Those gate *elements*. That stops the entry rendering and leaves its title in the index page, its
excerpt in the search results, its URL in the sitemap, and the PDF attached to it sitting at
`/uploads/2026/contract.pdf` for anyone who guesses the path. Bouncer closes all four, because all
four leak. The file half is the reason it exists.

## If two rules cover the same entry, which one wins?

**Both must pass.** Rules combine with AND, never OR, so adding a rule can only ever remove access.
Ordering decides one thing only: which rule's response a refused visitor gets — the first matching
rule that denies. If you want "editors or subscribers", that is one rule with two groups in its
list, matched *any*.

## What happens if my licence lapses?

Bouncer fails closed. Pro-only conditions are not evaluated on Lite, and a rule left with nothing
evaluable **denies everybody**. A lapsed licence that silently opened a members area would be far
worse than one that locked it. The control panel names every affected rule and `bouncer/audit` exits
non-zero, so it is never silent. See [Editions](editions).

## Does protecting a volume actually stop the files being downloaded?

Only if the files are not still reachable underneath. Bouncer rewrites URLs and re-checks every
request — but nothing a plugin does can stop a web server serving a file it can see on disk. That is
what the [exposure audit](exposure) is for.

## Do I have to change my templates?

No. A protected asset's `getUrl()` returns the guarded route, transform and all, so existing
`{{ asset.url }}` and `{{ asset.getUrl(transform) }}` calls keep working and start being checked.
The [Twig API](twig) is there for when you *want* to change a template.

## Will it break my index pages or my search?

It will change them: protected entries drop out of element queries, which is the point. If a page
legitimately needs to show what it is not showing, opt that query out with `.bouncer(false)`.

## Can a protected video still be scrubbed?

Yes. Range requests get a real 206, `Content-Range`, and a 416 where one is due, along with
`If-None-Match` and `If-Modified-Since`. Remote filesystems take a local copy first for ranged
requests, because seeking a remote stream can send the wrong bytes.

## How do I share one protected file with someone who has no account?

`craft.bouncer.signedUrl(asset, 3600)`. The link is HMAC-signed and expiring, and nothing is stored —
so there is no table of live share links to clean up later.

## Am I going to lock myself out?

Admins are exempt by default, and **Guard front-end requests** is a master switch you can turn off to
stage rules — or to get back in. **Bypass all access rules** lets editors past the site's own rules
without being made admins.

## Are previews and share links gated too?

No, deliberately. Previews, share tokens and the control panel are Craft's own ways of showing
somebody content they were already given access to.

## What does Bouncer cost?

Lite is free. Pro is $59 as a one-off licence, with an optional $29/year renewal for updates — the
plugin keeps working if you never renew, you just stop getting new versions.

## What are the requirements?

Craft CMS 5.3 or later and PHP 8.2 or later. No build step, and no runtime dependencies beyond Craft.
