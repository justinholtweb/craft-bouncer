---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and the two settings worth knowing before the first rule.
---

Two commands. Nothing is guarded until you write a rule, so installing Bouncer changes nothing on
its own.

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

There is no build step and no runtime dependency beyond Craft itself.

## With Composer

```sh
composer require justinholtweb/craft-bouncer
php craft plugin/install bouncer
```

## From the Plugin Store

Or install it from the control panel: **Settings → Plugins**, search for *Bouncer*, and install.

| Edition | Price | What it covers |
| --- | --- | --- |
| Lite | Free | Entries, categories and URIs; login, groups and permissions; the query filter; the Twig API |
| Pro | $59 one-off, $29/year for updates | Everything in Lite, plus protected files, signed links, the exposure audit, passwords, date windows, IP rules and the condition builder |

See [Editions](editions) for the full split — and for the one way this plugin behaves differently
from a normal paid plugin when a licence lapses.

## Check it worked

A **Bouncer** item appears in the control panel sidebar. From the command line:

```sh
php craft bouncer/rules/list
```

which will tell you there are no rules yet. That is the correct state for a fresh install:
**Bouncer denies nothing until a rule says so.**

## Before you write the first rule

Two settings are worth reading now rather than later, because both are about not locking yourself
out. Both are under **Bouncer → Settings**, and both are covered in [Configuration](configuration):

- **Guard front-end requests** — the master switch. Leave it on, but know where it is: turning it
  off is how you stage rules, and how you get back in if a rule locks you out.
- **Admins are exempt** — on by default, and the reason a typo in your first rule will not lock
  the installer out of their own site.

## Uninstalling

```sh
php craft plugin/uninstall bouncer
```

This removes Bouncer's tables *and* its top-level `bouncer` project-config key. That second part
matters: without it the key would outlive the plugin, and reinstalling would resurrect every old
rule.

Protected assets go back to their normal URLs immediately, which is worth thinking about — if you
sealed a volume's filesystem on Bouncer's advice, the files stay sealed and their URLs stay broken
until you unseal them.
