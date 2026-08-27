# Bouncer — plan

**Access rules for Craft CMS.** One place to say who may see what, covering the two halves that
normally get solved separately and badly: *content* gating (sections, entries, categories, front-end
URIs) and *file* gating (assets that are still sitting in the web root with a guessable URL).

Package `justinholtweb/craft-bouncer`, namespace `justinholtweb\bouncer`, handle `bouncer`.
**Lite / Pro**, Craft 5.3+, PHP 8.2+, no build step, no runtime dependencies beyond Craft's own.

Reference points: content-gating plugins for the element half, and the WordPress *Protect Uploads*
plugin for the file half. Protect Uploads' whole idea is that `wp-content/uploads/secret.pdf` is
readable by anyone who guesses the URL, no matter what the post says — so it moves the files and
serves them through PHP. Craft has exactly the same hole, and Bouncer closes it the same way.

## The load-bearing idea: one verdict, many enforcement points

Everything funnels through `services\Access::check($subject, $user)` returning a `Verdict`. The
subject is an element or a URI; the answer is allow/deny plus *which rule* denied it and *why*.
Nothing else in the plugin decides access. The enforcement points are just places that ask:

| Point | What it protects | Hook |
| --- | --- | --- |
| `Guard` | the page request itself | `Controller::EVENT_BEFORE_ACTION` (site requests) |
| `QueryFilter` | listings, search, feeds, GraphQL | `ElementQuery::EVENT_BEFORE_PREPARE` |
| `Assets` (Pro) | the file bytes | `Asset::EVENT_BEFORE_DEFINE_URL` + a guarded route |
| Twig | partial pages, teasers | `craft.bouncer.*`, `{% bouncer %}`, `|teaser` |

A plugin that only did the first would be security theatre: the entry 403s but the listing still
shows its title and the PDF still downloads. All four exist because all four leak.

## Rules

A rule is **project config** (`bouncer.rules.<uid>`), mirrored into `{{%bouncer_rules}}` for
ordering and fast reads. It has three parts:

- **target** — *what* is protected: type (`entries` · `categories` · `assets` · `uri`), the sources
  (section / category-group / volume UIDs), entry type UIDs, an optional element **condition**
  (Pro), and URI glob patterns for the `uri` type.
- **access** — *who* gets through: `requireLogin`, user groups (match any / all), permissions,
  a shared **password** (Pro), **date window** (Pro), **IP allow/deny** lists (Pro).
- **response** — what a refused visitor gets: `login` redirect · `redirect` · `403` · `404` ·
  `template` · `password` prompt.

### How multiple rules combine

**Every rule that targets an element must pass.** AND, not OR — the secure default, and the one
that does not surprise anyone: adding a rule can only ever remove access. "Editors *or*
subscribers" is expressible inside one rule (a group list matched with *any*), so nothing is lost.

Ordering matters only for which rule's *response* is used: the first matching rule that denies.

## The file half (Pro)

1. `Asset::EVENT_BEFORE_DEFINE_URL` — a protected asset's URL becomes
   `/bouncer/file/<uid>?...`, transform and all, so no template changes anywhere.
2. `FileController` re-checks access on every hit (the URL is not the authority), then streams the
   bytes: `Range` requests, conditional `If-None-Match` / `If-Modified-Since`, inline vs
   attachment, and optional `X-Accel-Redirect` / `X-Sendfile` hand-off for big files.
3. **Signed URLs** — HMAC-signed, expiring links for sharing a protected file with someone who has
   no account. Stateless; the signature covers asset UID, transform, expiry and (optionally) the
   user.
4. **Exposure audit** — the piece that makes the rest true. For every volume Bouncer protects, is
   its filesystem still public? `hasUrls`, a local root under the web root, a base URL that
   resolves — each is reported with a severity, and the CP hands over the exact `.htaccess` or
   `nginx` snippet to seal the originals plus the transform subpath. Rewriting URLs while the
   originals stay fetchable is the single most likely way to deploy this wrong.

## Editions

**Lite** — entries, categories and URI targets; require login, user groups, permissions; all
non-password responses; query filtering; the Twig API.

**Pro** — asset targets and the whole file-delivery half; password gating; date windows; IP rules;
the element condition builder; the access log; the console commands.

**Downgrade policy, stated once and enforced in one place:** on Lite, Pro-only conditions are not
evaluated, and a rule left with nothing evaluable **denies**. Access control must fail closed — a
licence lapse that silently opens a members area is worse than one that locks it. The CP shows a
banner naming every affected rule, and `bouncer/audit` exits non-zero.

## Data model

- `{{%bouncer_rules}}` — `id`, `name`, `handle` (unique), `enabled`, `sortOrder`, `targetType`,
  `settings` (JSON: the whole rule), `uid`. Project config is the source of truth.
- `{{%bouncer_log}}` — `id`, `ruleId`, `userId`, `elementId`, `siteId`, `uri`, `outcome`, `reason`,
  `ip`, `userAgent`, `dateCreated`. Pro, off by default, pruned by GC.

## Not in v1

Per-element ad-hoc overrides on the entry edit screen, Commerce-product-purchase as a condition,
drip scheduling, and a marketing site. All noted rather than half-built.
