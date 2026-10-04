---
title: Permissions
slug: permissions
order: 100
summary: Who can edit the rules, and who is never refused by them.
---

Two kinds: who can change the rules, and who is not subject to them.

## Managing Bouncer

Bouncer's screens — the rules, the exposure audit and the access log — are for admins only. A rule
changes who can read the site, so it isn't something to hand out as a plugin permission, and the
Bouncer item only appears in the control panel nav for admins.

Rules are project config, so on a site with `allowAdminChanges` off in production the rules screens
are read-only there: admins can read every rule and use the tester, but not add, change, reorder or
delete one. Production is not where an access rule should be authored, and a change made there
would be reverted by the next deploy.

## Bypass all access rules

A separate permission that exempts its holder from every rule on the site.

This exists so that editors can be let past the site's own gates **without being made admins**. The
alternative — which is what happens when a plugin does not offer it — is that somebody needs to see
a members-only page to check their work, and the quickest fix is to tick *Admin*. That trade is made
in a hurry and never revisited.

Grant it narrowly. It is not "can edit protected content"; it is "never refused, anywhere".

## The exemptions are not permissions

Two settings look like permissions and are not:

- **Admins are exempt** — on by default, and worth keeping on. It is why a mistake in your first rule
  does not lock you out.
- **Control panel users are exempt** — off by default, and it should stay off. It is not a role, it
  is "everyone who can log into the CP", which on most sites is every author and on some sites is
  every customer.

If you find yourself reaching for the second one, what you actually want is **Bypass all access
rules** on a specific group. See [Configuration](configuration).
