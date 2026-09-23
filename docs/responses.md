---
title: Responses
slug: responses
order: 40
summary: Login, redirect, template, password, 403 or 404 — and how to choose.
---

A refusal is a page somebody sees. It is worth designing rather than defaulting.

## The six

| Response | What happens | Use it when |
| --- | --- | --- |
| **Login** | Redirects to Craft's login page with a return URL, so they land back where they were. | The rule requires an account and the visitor could plausibly have one. |
| **Redirect** | Sends them to a URL you nominate. | You have a signup, pricing or "members only" page that explains the situation. |
| **Template** | Renders one of your templates in place, at the original URL, with the verdict available to it. | A paywall or a teaser. The best option in most cases. |
| **Password** (Pro) | Shows a password prompt; a correct answer unlocks the rule for the session. | One shared secret, no accounts — a client review area, an embargoed page. |
| **403** | Forbidden. | The honest answer when the visitor is logged in and simply is not allowed. |
| **404** | Not found. | You do not want to admit the URL exists at all. |

## 403 or 404?

403 is truthful and 404 hides the existence of the resource. Neither is universally right: 403
tells an attacker that something is there, and 404 tells a legitimate member that their bookmark is
broken when in fact they just need to log in.

Use 404 when the existence of the URL is itself the secret. Otherwise prefer login, redirect or a
template — all three tell the visitor something they can act on.

## Ordering decides whose response wins

[Rules combine with AND](rules#how-multiple-rules-combine): every rule targeting an element must
pass. When more than one denies, the **first matching rule in the list** supplies the response. So
if a section has both a "must be logged in" rule and an "embargoed until March" rule, put whichever
produces the more useful page first.

## The password gate (Pro)

Unlocking is per session and per rule, so one password does not unlock a different password-gated
rule. Failed attempts are rate-limited per IP and per rule. If you need to know in a template
whether the visitor has already unlocked something:

```twig
{% if craft.bouncer.isUnlocked('researchArchive') %}…{% endif %}
```

The verdict also carries `passwordWouldUnlock`, so a template response can offer the prompt itself
rather than sending the visitor somewhere else to find it.
