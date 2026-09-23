---
title: Console commands
slug: console-commands
order: 80
summary: audit, rules/test and friends — and which two belong in a deploy check.
---

Two of these belong in your deploy pipeline. The rest are for when something is wrong.

## The two for CI

```sh
php craft bouncer/audit
php craft bouncer/rules/test /members/handbook
```

`audit` checks rule health and file exposure together, and **exits non-zero** if either is wrong —
an unevaluable rule, a volume whose filesystem is still public, a local root inside the web root. A
pipeline that runs it cannot quietly ship an open volume.

`rules/test` answers the question everybody actually has: *does an anonymous visitor still get the
members index?* Add `--user-id=5` to ask it as a particular person.

## All of them

| Command | What it does |
| --- | --- |
| `bouncer/audit` | Rules and file exposure. Non-zero on a problem. |
| `bouncer/audit/snippets [apache\|nginx]` | Prints the server block that seals each exposed volume. |
| `bouncer/rules/list` | Every rule in order, with its target, requirements and response. |
| `bouncer/rules/test <elementId\|uri>` | The verdict for that subject. `--user-id` to ask as somebody. |
| `bouncer/log/prune [days]` | Trims the access log. |
| `bouncer/log/clear` | Empties the access log. |
| `bouncer/files/clear-transforms` | Clears generated transforms of protected images from the runtime directory. |

## A deploy check

```sh
#!/usr/bin/env bash
set -euo pipefail

php craft bouncer/audit
php craft bouncer/rules/test /members/handbook                # must refuse
php craft bouncer/rules/test /members/handbook --user-id=5    # must allow
```

The pair matters more than either alone. An audit that passes tells you the plumbing is sound; a
rules test tells you the rule says what you meant. It is entirely possible to have a clean audit and
a rule that refuses the very people it names — see [Troubleshooting](troubleshooting).

## When to clear transforms

Protected images' transforms live in Craft's runtime directory rather than the volume's transform
filesystem, so they are not reachable from outside. They are still generated files, though, and if a
volume stops being protected they are stale. `files/clear-transforms` removes them; Craft
regenerates whatever it needs.
