---
title: Configuration
slug: configuration
order: 90
summary: The settings that change behaviour, and the two that get you out of trouble.
---

Every setting lives under **Bouncer → Settings**, and can also be set in `config/bouncer.php` like
any Craft plugin. None of them is required — install it, write a rule, and the defaults are
sensible.

## Access

| Setting | Default | What it does |
| --- | --- | --- |
| **Guard front-end requests** | On | The main switch. Turn it off to stage rules before they bite — and turn it off first if a rule locks you out. |
| **Filter element queries** | On | Keeps protected titles out of listings, search, feeds, sitemaps and GraphQL. Without it, the pages refuse but every index still names them. |
| Filter control panel queries | Off | Leave it off. The CP has its own permissions, and an author who cannot see the entry they are meant to edit files a bug against you, not against the rule. |
| **Admins are exempt** | On | The alternative is a plugin whose first act on a typo is to lock the installer out of their own site. |
| **Control panel users are exempt** | Off | Leave it off. "Anyone who can access the CP" is a much wider group than it sounds — on most sites it includes every author, and on some it includes customers. |
| Condition ID cap | 5000 | See below. |
| Condition cache duration | 300 s | How long resolved condition IDs are cached. |

## Files (Pro)

| Setting | Default | What it does |
| --- | --- | --- |
| Guard asset URLs | On | Rewrites protected assets' URLs to the guarded route. Off, an asset rule hides the element and does nothing about the file. |
| File route URI | `bouncer/file` | Where the guarded route lives. |
| Signed URL duration | 3600 s | Default lifetime of `signedUrl()` links. |
| File delivery method | PHP | `x-accel-redirect` (nginx) or `x-sendfile` (Apache) hand large files to the web server. |
| Internal path map | — | For hand-off: maps a local filesystem root to the **internal** location the server exposes it at, e.g. `['/var/www/private' => '/internal-files']`. |
| Force download | Off | Send protected files as attachments rather than inline. |

Server hand-off needs the server configured with an *internal* location. Without that, the hand-off
path is a public URL and undoes the whole plugin.

## Password gate and log (Pro)

| Setting | Default | What it does |
| --- | --- | --- |
| Password template | Bouncer's own | The template rendered for the password prompt. |
| Password attempts | 10 per 300 s | Failed attempts allowed per IP, per rule, inside the window. |
| Log denials | Off | Records refusals with the rule, the reason and the requester. |
| Log allowed requests | Off | Also records the ones let through. Noisy, and occasionally exactly the point. |
| Log retention | 30 days | Garbage collection prunes past this. |

## The condition ID cap

Condition-based rules filter listings by resolving which elements the condition matches. On a large
section that resolution has to stop somewhere.

Past the cap, **the rule stops filtering and logs a warning** rather than filtering a truncated
list. That is the deliberate choice: a half-applied exclusion is a leak that looks exactly like a
working one, and silently showing the first N results correctly while leaking the rest is worse than
visibly not filtering at all. The page guard still refuses the pages themselves — it is only the
listing filter that stands down.

If you are hitting the cap regularly, the condition is probably doing work that belongs in the
target: a dedicated section, or an entry type, filters without resolving anything.

## Staging a rule safely

1. Turn **Guard front-end requests** off.
2. Write the rule and save it.
3. Run `php craft bouncer/rules/test` against a URI it should cover — the console command evaluates
   the rule regardless of the front-end switch, so you get the real verdict without exposing it to
   visitors.
4. Turn the switch back on.
