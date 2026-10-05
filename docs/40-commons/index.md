---
title: Shared bricks
order: 40
---

# Shared bricks

What several sites need and used to copy, written once in `glitchr/omnibase`.
An application or an `omnibase/*` bundle uses these; it does not keep a copy.

| Brick | Where | Page |
|---|---|---|
| Opening hours, special days, per place, JSON-LD | `Base\Entity\Hours\*`, `Base\Service\OpeningHours` | [Opening hours](opening-hours.md) |
| JSON-LD `LocalBusiness` (address, phone, hours) | `Base\Service\LocalBusiness`, Twig `local_business_jsonld()` | [LocalBusiness JSON-LD](local-business.md) |
| Redirections of a site's old addresses | `Base\Entity\Layout\Redirection`, `Base\Service\Redirections` | [Redirections](redirections.md) |
| The sitemap: declared routes, the pages of records | `#[Sitemap]`, `Base\Service\Sitemapper`, `SitemapEvent` | [Sitemap](sitemap.md) |
| ICS file, Google Calendar link | `Base\Service\Calendar\{CalendarEntry, Ics, GoogleCalendarLink}` | [Calendar](calendar.md) |
| Invitation by token | `Base\Entity\User\Invitation`, `Base\Service\Invitations` | [Invitations](invitations.md) |
| Signed download links | `Base\Service\DownloadLinks` | [Downloads and QR codes](downloads-and-qr.md) |
| QR codes, A4 and Avery sheets | `Base\Service\Qr\{QrCode, QrSheet}` | [Downloads and QR codes](downloads-and-qr.md) |
| An HTML page answered as a PDF | `Base\Response\PdfResponse` (dompdf/dompdf, suggested) | [PDF responses](pdf.md) |
| The e-mail frame, a subject written in the template | `@Base/notifier/email.html.twig`, `{% block subject %}` | [E-mails](emails.md) |
| EU allergens | `Base\Enum\Allergen` | [Allergens](allergens.md) |
| Comments | `Base\Entity\Thread\Comment`, `CommentGuard`, `CommentType` | [Comments](comments.md) |
| Framing an address | Twig `embed_url()` | [Pages and texts](pages-and-texts.md) |
| Contact form, data-protection notice and consent box | `Base\Form\Type\ContactType`, `ContactModel`, `PrivacyType` | [Pages and texts](pages-and-texts.md) |
| Pictures, texts rewritten in the back office | Twig `picture`, `Base\Entity\Layout\TextOverride` | [Pages and texts](pages-and-texts.md) |
| Glitch Art's signature | `@Base/partials/_credits.html.twig` | [Front end](front-end.md) |
| `Base.boot()`, Stimulus `poll` | `assets/boot.js`, `assets/controllers/poll_controller.js` | [Front end](front-end.md) |
| One thing sounds at a time: the `media:play` event | `assets/media/media.js` (`bundles/base/js/media.js`) | [Front end](front-end.md) |

## Installation

Nothing to enable: the services are registered by the bundle
(`config/services/commons.php`), the entities mapped with the others. A site
that updates `glitchr/omnibase` gets these tables on its next migration
(`hoursWeekDay`, `hoursSpecialDay`, `hoursScopedWeek`, `threadComment`,
`layoutTextOverride`, `layoutRedirection`):

```
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

Their names differ from the copies some sites kept (`weekDayHours`,
`specialDay`, `blog_comment`, `textOverride`): a site that has not moved yet
is not disturbed, and moving is a `RENAME TABLE` (or, for the blog's comments,
an `INSERT … SELECT`) in the migration that drops its copy. See
[Scaffolding a site](../scaffold.md) for what a new site copies.

## Configuration

```yaml
# config/packages/base.yaml
base:
    opening_hours:
        timezone: Europe/Paris       # the hours are given in it
        cutoff: '18:00'              # past it, orderDay() is the next open day (null: the day's last closing)
        week:                        # until a week is saved in hoursWeekDay
            3: [['09:00', '13:00'], ['16:30', '19:00']]
            6: [['09:00', '18:00']]
    local_business:                  # see LocalBusiness JSON-LD
        address: { street: '1 rue Lindebuckel', postal_code: '67300', locality: Schiltigheim, country: FR }
    comments:
        min_delay: 4                 # seconds: a form sent faster is a robot
        flood_interval: 60           # seconds between two comments from one address
    download_links:
        ttl: 600                     # seconds a signed link stays valid
```
