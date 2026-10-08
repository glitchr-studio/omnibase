<?php

namespace Base\Controller;

use Base\Service\Signatures;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A signing provider's callback (glitchr/omnisign: Yousign, DocuSign,
 * DocuSeal...): its signature checked by the gateway, the envelope it
 * names asked again and kept (Base\Service\Signatures::notify()). Without
 * glitchr/omnisign there is nothing to update: 404.
 */
final class SignatureController
{
    public function __construct(private readonly ?Signatures $signatures = null)
    {
    }

    #[Route('/signatures/{gateway}/webhook', name: 'base_signature_webhook', methods: ['POST'], requirements: ['gateway' => '[a-z0-9_-]+'])]
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        if (null === $this->signatures || !$this->signatures->isEnabled($gateway)) {
            return new JsonResponse(['error' => 'No such gateway.'], 404);
        }
        $envelope = $this->signatures->notify($gateway, $request->getContent(), $request->headers->all());
        if (false === $envelope) {
            return new JsonResponse(['error' => 'Invalid signature.'], 400);
        }

        return new JsonResponse(null === $envelope ? ['ignored' => true] : ['envelope' => $envelope->getId(), 'status' => $envelope->getStatus()]);
    }
}
