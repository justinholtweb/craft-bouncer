# Bouncer — Craft CMS 5 Plugin

## Project Overview

Bouncer is an access-control plugin for Craft CMS 5: rules that say who may see which sections,
entries, categories, front-end URIs and **asset files**. Distributed as
`justinholtweb/craft-bouncer`. **Lite / Pro.**

Reference points: content-gating plugins for the element half, and the WordPress *Protect Uploads*
plugin for the file half. The framing for all the copy: an element-only access plugin leaves the
title in the listing and the PDF at its original URL.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no runtime dependencies beyond Craft's own
- Namespace `justinholtweb\bouncer`, handle `bouncer`

## Architecture

### One verdict, many enforcement points

Everything funnels through `services\Access::check*()` returning a `models\Verdict`. Nothing else
decides access. The enforcement points only *ask*:

- `services\Guard` — `Controller::EVENT_BEFORE_ACTION`, site requests only
- `services\QueryFilter` — `ElementQuery::EVENT_BEFORE_PREPARE`
- `services\Assets` + `controllers\FileController` — `Asset::EVENT_BEFORE_DEFINE_URL` and the
  guarded route (Pro)
- `twig\BouncerVariable` / `twig\Extension`

If a fifth surface ever needs guarding, it asks `Access` too. Re-implementing a slice of the logic
locally is how an access plugin ends up allowing at one door what it refuses at another.

### Rules

Project config (`bouncer.rules.<uid>`), mirrored into `{{%bouncer_rules}}` for ordering and fast
reads. `models\AccessRule` = `RuleTarget` + `RuleAccess` + `RuleResponse`.

**Multiple rules combine with AND.** Adding a rule can only remove access. "Any" exists only inside
one rule's user-group list.

### The edition boundary

`models\Edition` is the single place that answers "what does Pro buy". Unlike the rest of this
family it **cannot downgrade gracefully** — a lapsed licence on a store locator shows a free map; a
lapsed licence here would show the members area to the public. So: Pro conditions are not evaluated
on Lite, and a rule left with nothing evaluable **denies**. `Edition::deniesUnevaluable()` is a
named method rather than a bare `true` so the call sites read as the policy.

## Traps found while building this

- **The query filter breaks routing if it does not stand down.** Craft matches an element URL by
  running an element query for that URI, so filtering that query hides the protected entry from
  Craft's *own* lookup and the request 404s before the guard sees it — the rule's configured
  response never happens and the plugin looks like it is silently breaking URLs. `QueryFilter`
  keeps a `_routing` flag, set at `Application::EVENT_BEFORE_REQUEST` and cleared at the first
  `Controller::EVENT_BEFORE_ACTION`.
- **Bouncer's own lookups must opt out.** `FileController` finds its asset with `.bouncer(false)` —
  the asset is protected *by definition*, so the filter would hide it from the route that exists to
  serve it, and every protected file would 404.
- **`isset()` is false for null.** `Gate` stores `uid => null` for "unlocked for this session"
  (the default), so `isset($unlocked[$uid])` read back as *not unlocked* — the session was correct
  and every page still refused. `array_key_exists()`.
- **Yii already implements `Range` completely** in `Response::sendStreamAsFile()` — 206,
  `Content-Range`, and a 416. Hand-rolling it produced a 200 with a truncated body, which every
  media player treats as a broken file. Let Yii do it.
- **A remote filesystem's stream is not reliably seekable**, so answering a range request by
  seeking it sends the *wrong bytes* under a 206 — silent corruption. Ranged requests against a
  remote volume take a local copy first (`Files::localCopy()`); everything else streams.
- **Protected transforms must not go in the volume's transform filesystem.** It is public on most
  sites, so the thumbnail of a protected image would be published at a derivable URL. They are
  generated with `ImageTransforms::generateTransform()` into Craft's runtime path instead.
- **`hashData()` output is not URL-safe** — it prepends a hex hash to raw JSON, braces and quotes
  included. Signed references are base64url-encoded on top.
- **Craft's project config strips empty arrays**, so a rule whose `userGroupUids` resolved to `[]`
  (because a group's UID was empty at save time) stores no key at all and reads back as "no group
  requirement" — which then trips the fail-closed path and denies everybody. Symptom: a rule that
  refuses the very people it names.
- **Project config writes are buffered in a bare script**, so `UserGroups::saveGroup()` leaves
  `$group->id` null until the config is flushed, and `assignUserToGroups()` silently assigns
  nothing. Flush and re-fetch between the two. Deleting a group without flushing, then deleting it
  again, orphans the database row and the next save fails on uniqueness.
- **`craft\services\Users` has no `setPassword()`** — set `$user->newPassword` and save the element.
- **`StringHelper::lastIndexOf()` does not exist** in Craft; `mb_strrpos()`.
- **Piping curl into `grep -q` under `set -o pipefail`** fails intermittently: grep exits on the
  first match, curl dies of SIGPIPE, and the pipeline reports failure. The HTTP probe fetches to a
  file first.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bouncer/tests/integration/checks.php   # 104 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-bouncer/src -name "*.php" -print0 | xargs -0 -n1 php -l'
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bouncer/tests/manual/seed-demo.php
bash tests/manual/http-probe.sh                                                                                # 31 checks
```

`tests/manual/seed-demo.php --clean` removes the demo. The demo also needs these harness templates,
which are not in this repo: `bouncer-test.twig`, `bouncer-gate/index.twig`,
`bouncer-paywall/index.twig`, `bouncer-paywall/_locked.twig`, `bouncer-redirect/index.twig`.

The integration checks are idempotent, self-cleaning, and deliberately **do not assume a pristine
harness** — they pick unprotected comparison content by asking Bouncer, because the demo seed puts
real rules on real sections.

**A `php -l` failure straight after writing a file is usually a lie.** The repo reaches the
container through a bind mount, and the container can read a half-synced file for a moment after
the host write — which shows up as "Unclosed '{'" or "Unterminated comment" in a file that is
perfectly fine. Re-run the lint before believing it.

**Always test uninstall → reinstall before tagging.** `afterUninstall()` removes the top-level
`bouncer` project-config key; without it the key outlives the plugin and reinstalling resurrects
old rules. Verified clean on 2026-08-18.

`ddev` on this machine is flaky: containers get removed mid-session and `ddev start` can fail on a
missing network. `docker exec ddev-plugin-testing-web …` is more reliable than `ddev exec`, which
runs under `set -u`.

## Coding conventions

- `Craft::t('bouncer', '…')` for user-facing strings; `src/translations/en/bouncer.php`
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- When in doubt about access, **deny**
