---
title: Politeness
order: 35
---

# How the site addresses people

A site says *tu* or *vous*, *du* or *Sie*, 常体, 丁寧語 or 敬語. That is one
setting of the site, and one variant per text that changes with it.

```yaml
# config/packages/base.yaml
base:
    translator:
        politeness: polite      # plain | polite | formal - unset by default
```

Unset, **nothing changes**: every text is the one written in the catalogue.
The parameter is `base.translator.politeness`; `Base\Service\Translator`
holds it (`getPoliteness()`, `setPoliteness()` for a message written to
somebody the site addresses otherwise).

## The three levels and the key of a variant

| Level | Constant | Suffix | French | German | Japanese |
|---|---|---|---|---|---|
| `plain` | `Translator::POLITENESS_PLAIN` | `_plain` | tu | du | 常体 (だ / する) |
| `polite` | `Translator::POLITENESS_POLITE` | `_polite` | vous | Sie | 丁寧語 (です / ます) |
| `formal` | `Translator::POLITENESS_FORMAL` | `_formal` | - | - | 敬語 (いたします / ございます) |

A text keeps **one key and one base text**. A variant is that key followed by
the level, beside it in the same file:

```yaml
# translations/notifications+intl-icu.fr.yaml
login:
    already: Tu es déjà connecté !
    already._polite: "Vous êtes déjà connecté !"
```

Nothing changes where the text is asked: `'@notifications.login.already'|trans`
answers the base text or its variant, by the site's level.

What is tried, the first found wins:

| Site's level | Tried in order |
|---|---|
| `formal` | `key._formal`, `key._polite`, `key` |
| `polite` | `key._polite`, `key` |
| `plain` | `key._plain`, `key` |
| unset | `key` |

A variant takes the parameters of its text (`{0}`, `{name}`, a plural). It is
a text like another: asked by its own key (`key._polite`) it is answered as
it is, whatever the level.

## The base text is not at the same level in every language

The base text is whatever was written first. What a level needs depends on it:

| Language | The base text | `plain` needs | `polite` needs | `formal` needs |
|---|---|---|---|---|
| French (omnibase) | mostly *tu*; some texts already say *vous* (the contact form, passkeys, comments, the privacy notice) | `_plain` for the texts that say *vous* - none written | `_polite` for the texts that say *tu* - **all written** (78) | nothing more: French has no third level, `formal` reads the polite ones |
| English (omnibase) | *you* | nothing | nothing | nothing |
| German (the bundles that have it: agenda, blog, restaurant...) | *Sie* | `_plain` (*du*) - none written | nothing | nothing |
| Japanese (marketplace, restaurant, consent) | 丁寧語, です / ます; buttons and labels in the dictionary form, as interfaces are | `_plain` for the sentences - none written | nothing | `_formal` for the sentences - none written |

omnibase itself ships French and English. A language's row holds for a
bundle's or an application's catalogue in that language too: say in which
level the base text is written, write the variants of the others.

For a Japanese site in `formal`: a `_formal` variant of each *sentence*
addressed to the visitor (confirmations, errors, e-mails, the checkout's
steps - `保存しました。` becomes `保存いたしました。`, `できません` becomes
`いたしかねます`), in the catalogues of the bundles it uses and its own.
Labels, titles and buttons stay as they are. Until a variant is written the
sentence stays in です / ます, which a 敬語 page tolerates.

## Language first, level second

The catalogues are walked as Symfony falls back between languages, and in
each one the variants are looked for before the base text. A German page
whose text has no `_plain` variant keeps its German text - it is not given
the French one that has it. A text missing in German altogether is read in
the fallback language, at the level asked.

A text rewritten in the back office (omnibase/admin's « Textes du site »)
counts as that language's: rewritten at its base key, it wins over a variant
of the files. To rewrite what a polite site shows, rewrite `key._polite`.

## Where it applies

`Base\Service\Translator` **is** the `translator` service (it decorates
Symfony's), so the level holds on every way a text is asked:

- the service (`trans()`, `transQuiet()`, `transEntity()`, `transEnum()`);
- Twig: `|trans`, `{% trans %}`, the form themes' labels, helps and errors;
- the e-mails and the notifications, which translate through it;
- the validator's messages (a constraint's `message` is a key like another).

A text that refers to another one (`"@notifications.login.success.day.first"`)
is answered at the same level.

## A call that gives its own level

It is answered at that one, the site's is not asked:

```php
$translator->trans('@emails.resetPassword.subject', [Translator::TRANSLATION_POLITENESS => Translator::POLITENESS_PLAIN]);
$translator->trans('@emails.resetPassword.subject', ['politeness' => 'none']);   // the text as written
$translator->transEntity($user, 'email', [Translator::POLITENESS_FORMAL, Translator::NOUN_SINGULAR]);
```

```twig
{{ '@notifications.login.already'|trans({politeness: 'plain'}) }}
```

`politeness` is taken out of the parameters before the text is formatted.

## Adding variants in a bundle or an application

1. Find the texts that address the reader at the base level (in French: *tu,
   te, ton, ta, tes, toi* and the imperatives - *Choisis, Clique* - which no
   pronoun gives away).
2. Write each variant under `<key>._polite` (or `._plain`, `._formal`) right
   after its text, same indentation, same parameters, same meaning.
3. An application overrides a bundle's variant as it overrides any text: the
   same key in its own `translations/<domain>+intl-icu.<lang>.yaml`.

`tests/Translation/PolitenessCataloguesTest` is the check omnibase runs on
its own catalogues: every variant beside its text, with its parameters; in
French no polite variant that still says *tu*, no text that says *tu*
without one. Copy it for a bundle's catalogues.
