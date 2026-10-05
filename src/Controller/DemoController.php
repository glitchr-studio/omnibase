<?php

namespace Base\Controller;

use App\Repository\UserRepository;
use Base\Demo\DemoAccountRegistry;
use Base\Demo\DemoMode;
use Base\Entity\User\Notification;
use Base\Security\LoginFormAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

/**
 * Signing in as a demonstration account in one click: the buttons of the
 * sign-in page's "Demonstration accounts" panel (@Base/demo/_accounts.html.twig).
 *
 * The route exists in the `demo` environment only. It is a POST carrying a
 * CSRF token - never a link: a GET that signs in is followed by a prefetch,
 * a crawler, a preview. Only a declared account is signed in, and never one
 * whose roles reach ROLE_SUPERADMIN.
 */
class DemoController extends AbstractController
{
    public const CSRF = 'demo_login';

    #[Route('/login/demo', name: 'security_loginDemo', methods: ['POST'], env: DemoMode::ENVIRONMENT)]
    public function Login(Request $request, DemoMode $demo, DemoAccountRegistry $accounts, UserRepository $users, UserAuthenticatorInterface $userAuthenticator, LoginFormAuthenticator $authenticator): Response
    {
        if (!$demo->isActive()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->refuse('@notifications.demo.expired');
        }

        $account = $accounts->get((string) $request->request->get('account'));
        if (null === $account) {
            return $this->refuse('@notifications.demo.unknown');
        }

        try {
            $user = $users->loadUserByIdentifier($account->identifier);
        } catch (UserNotFoundException) {
            return $this->refuse('@notifications.demo.unknown');
        }

        // Declared as a plain account, promoted since (in the back office): not through here.
        if ($accounts->isSuperAdmin($user)) {
            return $this->refuse('@notifications.demo.unknown');
        }

        return $userAuthenticator->authenticateUser($user, $authenticator, $request)
            ?? $this->redirectToRoute($this->getParameter('base.site.index'));
    }

    private function refuse(string $message): Response
    {
        (new Notification($message))->send('warning');

        return $this->redirectToRoute(LoginFormAuthenticator::LOGIN_ROUTE);
    }
}
