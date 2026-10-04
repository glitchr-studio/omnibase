<?php

namespace Base\Service;

use Base\Entity\Layout\Redirection;
use Base\Repository\Layout\RedirectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Where an address that is not found leads now (Base\Entity\Layout\Redirection).
 *
 *     $redirections->responseFor($request);   // a RedirectResponse, or null: really not found
 *     $redirections->add('/produit/enseigne-lumineuse/', '/savoir-faire/enseigne');
 */
class Redirections
{
    public function __construct(
        protected readonly RedirectionRepository $repository,
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function responseFor(Request $request): ?RedirectResponse
    {
        if (!$request->isMethodSafe()) {
            return null; // a form sent to an old address is not replayed elsewhere
        }

        $query = $request->getQueryString();
        $address = Redirection::normalize($request->getPathInfo().(null !== $query ? '?'.$query : ''));

        try {
            $redirection = $this->repository->forAddress($address);
        } catch (\Throwable $e) {
            // No table yet (a site that has not run its migration): not found stays not found.
            $this->logger?->debug('Redirections unavailable: '.$e->getMessage());

            return null;
        }

        $target = $redirection?->resolve($address) ?? $redirection?->resolve(strtok($address, '?'));
        if (null === $redirection || null === $target) {
            return null;
        }

        // A redirection onto itself would turn in circles.
        if (Redirection::normalize($target) === $address && !preg_match('#^https?://#i', $target)) {
            return null;
        }

        // Matched on its path alone, the visitor's query follows (a campaign's utm_*).
        if (null !== $query && !str_contains($redirection->getSource(), '?') && !str_contains($target, '?')) {
            $target .= '?'.$query;
        }
        if (!preg_match('#^https?://#i', $target)) {
            $target = $request->getBaseUrl().$target;
        }

        try {
            $this->repository->hit($redirection);
        } catch (\Throwable $e) {
            $this->logger?->debug('Redirection not counted: '.$e->getMessage());
        }

        return new RedirectResponse($target, $redirection->getStatus());
    }

    /** Adds a redirection, or changes the one already written for that source. */
    public function add(string $source, string $target, int $status = Redirection::PERMANENT, bool $flush = true): Redirection
    {
        $source = Redirection::normalize($source);
        $redirection = $this->repository->findOneBy(['source' => $source]) ?? new Redirection($source, $target, $status);
        $redirection->setTarget($target)->setStatus($status);

        $this->repository->persist($redirection, $flush);

        return $redirection;
    }
}
