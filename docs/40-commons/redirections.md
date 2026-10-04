---
title: Redirections
order: 49
---

# Redirections

The addresses a site no longer answers, and where they lead now: the pages of
a site taken over (`/produit/enseigne-lumineuse/` → `/savoir-faire/enseigne`),
a page renamed. One row each in `Base\Entity\Layout\Redirection` (table
`layoutRedirection`), written in the back office (omnibase/admin's
"Redirections" screen), by fixtures or by an import.

```php
public function __construct(private Redirections $redirections) {}   // Base\Service\Redirections

$this->redirections->add('/produit/enseigne-lumineuse/', '/savoir-faire/enseigne');          // 301
$this->redirections->add('/soldes', '/boutique', Redirection::TEMPORARY);                    // 302
$this->redirections->add('/?p=123', '/actualites/ouverture');                                // a WordPress address
$this->redirections->add('/categorie-produit/*', '/savoir-faire/*');                         // everything beneath
$this->redirections->add('/charte', 'https://pano-group.com/charte', flush: false);          // another site; flushed by the caller
```

## When

Only when a request ends in "not found" - no route matched, or a controller
found no record (`Base\Subscriber\RedirectionSubscriber`, on the kernel's
exception). A page the site answers is never redirected, whatever the table
says, and costs no query. GET and HEAD only: a form sent to an old address is
not replayed elsewhere.

## Which

| Source | Matches |
|---|---|
| `/produit/enseigne` | that path, with or without a trailing slash, whatever its query |
| `/?p=123` | that path with exactly that query |
| `/categorie-produit/*` | that path and everything beneath it; a `*` in the target takes what the star stood for |

A source is stored as its path (`Redirection::normalize()`: no host, no
trailing slash, no fragment), so a whole address pasted from the old site is
fine. The one written for the address with its query comes first, then the
one for its path, then the longest prefix.

The target is a path of the site or a whole address. When the source has no
query and the target none either, the visitor's query follows (`?utm_source=…`).

## What it keeps

`status` (301 by default: search engines move what they know of the old
address to the new one; 302 for a detour that will not last), `enabled`,
`hits` and `lastHitAt` (counted at each redirection, in UTC): the back office
shows which old addresses are still visited, and which can go.

A site that updates `glitchr/omnibase` gets the table on its next migration.
Until then nothing is redirected and nothing fails.
