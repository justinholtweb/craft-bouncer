---
title: Protecting files
slug: files
order: 50
summary: The guarded route, transforms, Range requests, server hand-off and signed links. Pro.
---

**Pro.** This is the half that is usually skipped, and it is the reason the plugin exists.

Craft has the hole WordPress has. `/uploads/2026/contract.pdf` is readable by anyone who guesses the
path, whatever the entry that references it says. Gating the entry does not touch the file.

## What happens when you protect a volume

### 1. URLs are rewritten

A protected asset's `getUrl()` returns `/bouncer/file/<uid>/<name>`, transform and all. **No
template changes anywhere** — existing `asset.url` and `asset.getUrl(transform)` calls keep working
and start being checked.

### 2. Every request is re-checked

The URL is public knowledge: it ends up in HTML, in caches, in browser history and in referrer
headers. It is never treated as the authority. The route resolves the asset and asks the access
service again, on every hit.

### 3. Transforms are generated into Craft's runtime directory

Not into the volume's transform filesystem, which is public on most sites. Generating the thumbnail
of a protected image the normal way would publish it at a derivable URL — a smaller copy of the
thing you just protected, sitting in the open.

### 4. Ranges and conditional requests are answered properly

`Range` gets a real 206 with a `Content-Range`, and a 416 where one is due; `If-None-Match` and
`If-Modified-Since` get a 304. A protected video has to be seekable, and a 200 with a truncated body
is a broken file to every media player.

Ranged requests against a *remote* filesystem take a local copy first. A remote stream is not
reliably seekable, and seeking one to answer a range would send the wrong bytes under a 206 —
silent corruption, which is worse than an error.

### 5. Large files can be handed off

Optional `X-Accel-Redirect` (nginx) or `X-Sendfile` (Apache), so the web server streams the file and
PHP does not hold a worker open for the length of a download. Off by default, because it only works
if the server is configured for it — and configured with an *internal* location, or the hand-off
becomes a public URL. See [Configuration](configuration#files-pro).

## Signed links

```twig
{{ craft.bouncer.signedUrl(asset, 3600) }}
```

An HMAC-signed, expiring URL for sharing one protected file with somebody who has no account. The
signature covers the asset UID, the transform, the expiry and optionally the user. It is
**stateless** — nothing is written, so there is no table of live share links to audit or clean up.

## The part that makes all of this true

None of the above stops a web server serving a file it can still see on disk. Rewriting a URL is not
sealing a file. Read [The exposure audit](exposure) next — it is not an optional extra, it is the
other half of this page.
