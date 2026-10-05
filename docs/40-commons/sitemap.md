---
title: Sitemap
order: 53
---

# Sitemap

`/sitemap.xml` and `/sitemap.txt` (`app_sitemap`) list the pages a site wants
indexed. `Base\Service\Sitemapper` builds the list at each request, in two
passes.

## Pages without a parameter: `#[Sitemap]`

```php
use Base\Attributes\Attribute\Sitemap;

#[Route('/agenda', name: 'agenda_index')]
#[Sitemap(priority: 0.8, changefreq: 'weekly')]
public function index(): Response
```

Every route whose action carries the attribute is listed
(`registerAttributes()`), once per locale. A route with a parameter is listed
too when its requirement spells its values out (`{extension}` with
`xml|txt`): one page per value.

## Pages of records: `SitemapEvent`

A route open to any slug or id is the application's - or the bundle's - to
enumerate, from a listener:

```php
#[AsEventListener(event: SitemapEvent::BUILD)]
final class SitemapListener
{
    public function __invoke(SitemapEvent $event): void
    {
        foreach ($this->posts->findAllPublished() as $post) {
            $event->getSitemapper()->register('blog_post', ['slug' => $post->getSlug()], $post->getUpdatedAt()?->format('c'));
        }
    }
}
```

The route's action still carries `#[Sitemap]`: the attribute says the page
belongs in the sitemap, with which priority; the listener says which pages
there are. `registerUrl('/blog/petit-cours')` does the same from an address.

## A page that cannot be listed is left out, never fatal

`register()` and `registerUrl()` do not throw. A page is left out, and the
reason logged once per request (warning, `Sitemap: page left out, ...`),
when:

- the route does not exist;
- the address, parameters filled in, matches no route;
- the route's action carries no `#[Sitemap]` - `no #[Sitemap] on
  "App\Controller\BlogController::post" (route "blog_post")`.

The last one used to throw `SitemapNotFoundException`: a listener
registering a route nobody had declared put the whole `/sitemap.xml` - every
other page with it - in 500. The fix is still to add the attribute; the log
says where. A route whose controller is given as an array
(`[Controller::class, 'method']`) or a closure is read without failing as
well.

The attributes of a class are read once and kept with the container's cache
(`var/cache/<env>/pools/simple/`, `Base\Attributes\AttributeReader`): a
`#[Sitemap]` just added is not seen until that cache is rebuilt. When the
page stays out after a `cache:clear`, delete `var/cache/<env>` and warm it
again.
