---
title: LocalBusiness JSON-LD
order: 42
---

# LocalBusiness JSON-LD

schema.org's `LocalBusiness` for a page: who the business is, where, how to
reach it, when it is open. Search engines read the address, the phone and the
hours - the days off too - from there.

```twig
{# in the layout's <head>, or on the contact page #}
{{ local_business_jsonld() }}
```

```html
<script type="application/ld+json">{"@context":"https://schema.org","@type":"LocalBusiness","@id":"https://soifdepub.com/#business","name":"PANO Strasbourg","url":"https://soifdepub.com/","telephone":"03 55 40 13 81","address":{"@type":"PostalAddress","streetAddress":"1 rue Lindebuckel","postalCode":"67300","addressLocality":"Schiltigheim","addressCountry":"FR"},"openingHoursSpecification":[…],"specialOpeningHoursSpecification":[…]}</script>
```

## Where each property comes from

In this order: what the template gives, the back office's settings, the
configuration. A property nobody gives is left out.

| Property | Setting | Configuration (`base.local_business`) |
|---|---|---|
| `@type` | | `type` (`LocalBusiness`; `Restaurant`, `Store`, `ProfessionalService`…) |
| `name` | `base.settings.title` | `name` (wins over the title when set) |
| `slogan` | `base.settings.slogan` | |
| `url`, `@id` | | `url` (else the site's home address) |
| `telephone` | `base.settings.phone` | `telephone` |
| `email` | | `email` |
| `image` | | `image` (a path under `public/`, or an address) |
| `address` | `base.settings.address.street`, `.postal_code`, `.locality`, `.region`, `.country` | `address.street`, `.postal_code`, `.locality`, `.region`, `.country` |
| `geo` | | `geo.latitude`, `geo.longitude` |
| `priceRange` | | `price_range` |
| `sameAs`, `areaServed` | | `same_as`, `area_served` |
| anything else | | `extra` (merged as it is) |
| `openingHoursSpecification`, `specialOpeningHoursSpecification` | | [`OpeningHours::schema()`](opening-hours.md) |

```yaml
# config/packages/base.yaml
base:
    local_business:
        type: LocalBusiness
        image: assets/ico/android-chrome-512x512.png
        address: { street: '1 rue Lindebuckel', postal_code: '67300', locality: Schiltigheim, country: FR }
        geo: { latitude: 48.6071, longitude: 7.7399 }
        same_as: ['https://www.facebook.com/panostrasbourg/', 'https://www.instagram.com/pano_strasbourg']
        area_served: [Strasbourg, Schiltigheim, Bas-Rhin]
        extra:
            parentOrganization: { '@type': Organization, name: PANO, url: 'https://pano-group.com' }
```

omnibase/admin's settings page has the phone and the address
(`BusinessSettingsSection`): what is typed there wins over the configuration.

## In a template

```twig
{{ local_business_jsonld({priceRange: '€€', servesCuisine: 'Japanese'}) }}   {# properties of the page's own; null removes one #}
{{ local_business_jsonld({name: store.name}, store) }}                       {# one place: its own hours (OpeningHours::for()) #}
{% set business = local_business() %}                                        {# the properties, to put in a graph of the page's own #}
```

The script is printed as it is (`<`, `>` and `&` are escaped inside the JSON:
a title cannot close the script). In PHP: `Base\Service\LocalBusiness::schema()`
and `jsonLd()`.
