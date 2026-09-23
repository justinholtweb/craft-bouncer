---
title: Troubleshooting
slug: troubleshooting
order: 120
summary: Locked out, 404s, leaking listings, and rules that refuse the people they name.
---

## I have locked myself out

Turn **Guard front-end requests** off. It is the master switch and it exists for this.

If you cannot reach the control panel either, the site is not honouring **Admins are exempt** —
check whether you are actually logged in as an admin in that environment. The control panel itself
is never guarded, so a Bouncer rule cannot be what is keeping you out of `/admin`.

## A protected URL 404s instead of showing my response

Expected if the URL never resolved to anything: a URI rule protects URIs that resolve, and a path
that would 404 anyway still 404s. The rule does not create a page in order to refuse from it.

If the URL *does* resolve for an allowed visitor but 404s for a refused one, check the rule's
[response](responses) — 404 is one of the six, and it may simply be set.

## Protected titles are still in my listings

Check **Filter element queries** is on. If it is, check the template is not opting out with
`.bouncer(false)` — that is a documented escape hatch and it does exactly what it says.

If it is a condition-based rule on a large section, you may be hitting the
[condition ID cap](configuration#the-condition-id-cap): past the cap the rule stops filtering the
listing and logs a warning, deliberately, rather than filtering a truncated list. The pages
themselves are still refused.

## A rule refuses the very people it names

The symptom is unmistakable: a rule that says *Subscribers* and turns away subscribers.

This is almost always a group that was saved when its UID was not yet available — the resulting
empty group list is stripped from project config entirely, reads back as "no group requirement", and
trips the fail-closed path. **Open the rule, re-select the groups, and save it again.** Then check
`php craft bouncer/rules/list` shows the groups it should.

## Protected files still download

Almost certainly the filesystem underneath, not the plugin. Run:

```sh
php craft bouncer/audit
```

Bouncer rewrites URLs and re-checks every request, but nothing it does can stop a web server serving
a file it can see on disk. If the audit reports a public filesystem or a local root inside the web
root, that is your answer — see [The exposure audit](exposure) for the snippet that seals it.

## A thumbnail of a protected image is public

Transforms of protected images are generated into Craft's runtime directory, not the volume's
transform filesystem. If you are finding public thumbnails, they are probably left over from before
the rule existed. Clear them:

```sh
php craft bouncer/files/clear-transforms
```

and check the audit is not reporting a public *transform* filesystem, which is a separate finding
from a public originals filesystem.

## A protected video will not scrub

Ranges are supported, including against remote filesystems — those take a local copy first, because
a remote stream is not reliably seekable. If seeking fails, check whether server hand-off
(`X-Accel-Redirect` / `X-Sendfile`) is enabled but not actually configured on the server: the file
is then handed to a web server that does not know what to do with it.

## Everything is denied after a licence change

That is [the fail-closed policy](editions#bouncer-fails-closed) working as designed. Run
`php craft bouncer/audit` — it exits non-zero and names the rules that have nothing evaluable left on
this edition. Rewrite them in Lite terms, or restore the Pro licence.
