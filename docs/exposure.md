---
title: The exposure audit
slug: exposure
order: 60
summary: Rewriting a URL is not sealing a file. This checks, and hands you the fix. Pro.
---

**Pro.** Rewriting a URL does nothing if the original still works. This is the page that makes the
file half honest.

Bouncer can rewrite every URL on the site and re-check every request, and a protected PDF will still
download if the web server can see it on disk and someone knows the path. **Rewriting URLs while the
originals stay fetchable is the single most likely way to deploy this wrong** — and it looks like it
is working, which is what makes it dangerous.

## What it checks

**Bouncer → Files** examines every volume that a rule protects, and reports three things with a
severity each:

- **The filesystem still has public URLs.** `hasUrls` is on and the base URL resolves, so the
  originals are fetchable.
- **The transform filesystem still has public URLs.** Less obvious and just as bad: the thumbnails
  are published even when the originals are not.
- **A local root sits inside the web root.** The worst of the three, because it is served whether
  or not Craft knows a URL for it — the web server does not consult Craft before handing over a
  file it can see.

## What it gives you

The exact server configuration to seal each finding — the originals plus the transform subpath —
ready to paste:

```sh
php craft bouncer/audit/snippets nginx
```

or `apache` for an `.htaccess` block. The same snippets are on the Files screen with a copy
button.

## Put it in CI

```sh
php craft bouncer/audit    # exits non-zero if anything is still exposed
```

The audit covers rule health as well as file exposure — it fails on an unevaluable rule too, which
is how a [licence downgrade](editions#bouncer-fails-closed) announces itself. A deploy pipeline that
runs this cannot quietly ship an open volume.

## The order to do things in

1. Write the rule that protects the volume.
2. Run the audit. Expect findings — a volume that was public five minutes ago is still public.
3. Apply the snippet, and move the local root out of the web root if that is one of the findings.
4. Run the audit again and get a clean exit.
5. *Then* believe the files are protected.

Step four is the one people skip. A finding you have read and intended to fix is
indistinguishable, from the outside, from one you never saw.
