---
title: Editions
slug: editions
order: 110
summary: Lite, Pro, and why this plugin fails closed when a licence lapses.
---

Lite is the content half and it is free. Pro adds the file half, and the conditions that need it.

## Lite — free

- Entry, category and URI targets
- Require login, user groups, Craft permissions
- Every response except the password prompt
- The query filter, across listings, search, feeds, sitemaps and GraphQL
- The whole Twig API

Lite is real access control, not a demo. A site that wants a members-only section behind a user
group gets that for nothing, including the listing filter that stops the titles leaking.

## Pro — $59

A one-off licence, with an optional **$29/year** renewal for updates. Everything in Lite, plus:

- **Asset targets and the guarded file route** — the half the plugin exists for
- Signed, expiring links, and the [exposure audit](exposure)
- Password gating, date windows, IP allow and deny lists
- The element condition builder on any target
- The access log and the console commands

Assets are Pro-only as a whole, not in part. Gating an asset *element* while its file stays
fetchable is worse than not gating it — it looks protected — so Lite does not offer the target at
all rather than offering the half that does not hold.

## Bouncer fails closed

This is the one place Bouncer behaves differently from a normal paid plugin, and it is worth
understanding before you buy rather than after.

Most plugins downgrade gracefully. A lapsed licence on a store locator shows a free map; a lapsed
licence on a chart plugin shows a simpler chart. **A lapsed licence on an access-control plugin
would show the members area to the public** — which is not a degraded experience, it is a data
breach.

So Bouncer does the opposite:

- On Lite, **Pro-only conditions are not evaluated at all.** They are not ignored, which would let a
  visitor through — they simply do not count towards a rule being satisfiable.
- A rule left with **nothing evaluable denies everybody.** A rule whose only condition was a date
  window will, on Lite, refuse everyone rather than admit everyone.

Whether that is the right call is a judgement, and it is the one we made: locking out paying members
is a support ticket, and opening a members area is not recoverable.

## It is never silent

Failing closed quietly would be its own kind of bad. So:

- The control panel shows a banner **naming every affected rule**, not a generic warning.
- `php craft bouncer/audit` **exits non-zero**, so a deploy check or a monitor notices without
  anybody logging in.

If you are downgrading deliberately, run the audit first and rewrite the affected rules in Lite terms
before the licence changes — not after.
