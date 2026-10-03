<?php

namespace Base\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signed download links: a route's absolute URL, signed by Symfony's
 * UriSigner and valid for a while (base.download_links.ttl seconds unless
 * told otherwise). Whoever holds one within that time gets the file - who
 * may was checked when it was handed out (a licence, an entitlement, a
 * voter). The one place for it: omnibase/forge's artifacts and
 * omnibase/classroom's resources sign theirs here.
 *
 *     $url = $links->sign('forge_download_file', ['id' => 7, 'filename' => 'app.zip', 'license' => 3], 900);
 *     // in the download action:
 *     if (!$links->verify($request)) { throw $this->createAccessDeniedException(); }
 */
class DownloadLinks
{
    public function __construct(
        protected readonly UriSigner $signer,
        protected readonly UrlGeneratorInterface $router,
        #[Autowire('%base.download_links.ttl%')] protected readonly int $ttl = 600,
    ) {
    }

    /** @param array<string, scalar> $parameters the route's parameters, and who the link was issued to */
    public function sign(string $route, array $parameters = [], ?int $ttl = null): string
    {
        $url = $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateTimeImmutable(sprintf('+%d seconds', $ttl ?? $this->ttl)));
    }

    /** The request's URL is one this site signed, and it has not run out. */
    public function verify(Request $request): bool
    {
        return $this->signer->checkRequest($request);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }
}
