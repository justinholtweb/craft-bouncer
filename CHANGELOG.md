# Changelog

All notable changes to Bouncer are documented here.

## 5.0.0 — 2026-08-18

Initial release. Numbered 5.0.0 to match the Craft version it targets, as the rest of this plugin
family is.

### Added

- **Access rules** stored in project config: a target, a set of requirements, and a refusal
  response. Multiple rules over the same content are combined with AND, so adding a rule can only
  ever remove access.
- **Targets** — entries (by section and entry type), categories, assets (by volume), and front-end
  URI glob patterns, optionally narrowed by Craft's element condition builder (Pro).
- **Requirements** — being logged in, user groups (matched any or all), Craft permissions, a shared
  password (Pro), a date window (Pro), and IP allow/deny lists with CIDR support (Pro).
- **Responses** — send to login, redirect, render a template in place, ask for the password, 403,
  or 404.
- **Four enforcement points**: the request guard, the element query filter (which covers listings,
  search, feeds, sitemaps and GraphQL in one hook), the guarded file route, and the Twig API.
- **File protection** (Pro): protected assets' URLs are rewritten to a guarded route that
  re-checks access on every request, streams with `Range` and conditional-request support, can hand
  off to nginx or Apache, and generates transforms into Craft's runtime directory rather than into
  a public transform filesystem.
- **Signed, expiring URLs** for sharing one protected file with somebody who has no account.
- **The exposure audit** (Pro): checks every volume a rule protects for public filesystems, public
  transform filesystems and local roots inside the web root, and generates the `.htaccess` or nginx
  block that seals each one off. Available in the control panel and from `bouncer/audit`, which
  exits non-zero so a misconfiguration can fail a deploy.
- **The access log** (Pro), off by default, pruned by Craft's garbage collection.
- **A `bouncer:bypass` permission**, so editors can be let past the site's rules without being made
  admins.
- **Console commands**: `bouncer/audit`, `bouncer/audit/snippets`, `bouncer/rules/list`,
  `bouncer/rules/test`, `bouncer/log/prune`, `bouncer/files/clear-transforms`.
- **Twig**: `craft.bouncer.can()`, `.cannot()`, `.check()`, `.isProtected()`, `.signedUrl()`,
  `.isUnlocked()`, a `|teaser` filter and a `|bouncer` filter, plus `.bouncer(false)` on any
  element query to opt it out of filtering.

### Notes

- On Lite, Pro-only conditions are not evaluated and a rule left with nothing evaluable **denies**.
  Access control fails closed; a lapsed licence must not open a members area.
- The query filter stands down while Craft resolves a request, because Craft matches an element URL
  by running an element query — filtering that one would turn every protected URL into a bare 404
  instead of the response the rule asked for.
