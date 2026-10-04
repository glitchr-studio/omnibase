<?php

namespace Base\Controller\Client;

use Base\Service\BaseService;
use Base\Service\SecurityPolicy;
use Symfony\Contracts\Translation\TranslatorInterface;

use App\Repository\UserRepository;
use Base\Entity\User\Connection;
use Base\Repository\User\ConnectionRepository;
use Base\Repository\User\PasskeyRepository;

use Base\Form\Type\Login2FAType;
use Base\Attributes\Attribute\Iconize;
use Base\Entity\User\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;

class UserSettingsController extends AbstractController
{
    private const SESSION_PENDING_SECRET = "2fa_pending_totp_secret";

    /** How many of the account's sessions the settings page lists. */
    private const SESSIONS_SHOWN = 20;

    private $baseService;
    private UserRepository $userRepository;
    private SecurityPolicy $securityPolicy;
    private TranslatorInterface $translator;

    public function __construct(BaseService $baseService, UserRepository $userRepository, SecurityPolicy $securityPolicy, TranslatorInterface $translator)
    {
        $this->baseService = $baseService;
        $this->userRepository = $userRepository;
        $this->securityPolicy = $securityPolicy;
        $this->translator = $translator;
    }

    #[Route("/members/qr/totp", name: "qr_code_totp")]
    public function displayTotpQrCode(TotpAuthenticatorInterface $totpAuthenticator): Response
    {
        $user = $this->getUser();
        if (!($user instanceof TotpTwoFactorInterface) || !$user->isTotpAuthenticationEnabled()) {
            throw new NotFoundHttpException('Cannot display QR code');
        }

        return $this->displayQrCode($totpAuthenticator->getQRContent($user));
    }

    private function displayQrCode(string $qrCodeContent): Response
    {
        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            data: $qrCodeContent,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 200,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );
        $result = $builder->build();

        return new Response($result->getString(), 200, ['Content-Type' => 'image/png']);
    }

    /**
     * @return string[] plain-text codes (only ever shown once, right after generation)
     */
    private function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
        }
        return $codes;
    }

    #[Route("/settings", name: "user_settings")]
    #[Iconize("fa-solid fa-fw fa-user-cog")]
    public function Settings(ConnectionRepository $connectionRepository)
    {
        $user = $this->getUser();

        // findByUser() is the finder DSL and hands back a Doctrine Query, not
        // results - passing that straight to Twig is why this page said "no
        // sessions" for an account with hundreds of them. Ask plainly, and
        // keep it to a screenful: the oldest of 600-odd rows tell nobody
        // anything, and the point of the list is to spot a session that
        // should not be there.
        $sessions = $connectionRepository->findBy(["user" => $user], ["updatedAt" => "DESC"], self::SESSIONS_SHOWN);

        return $this->render('client/user/settings.html.twig', [
            'user' => $user,
            'sessions' => $sessions,
            'policy' => $this->securityPolicy,
        ]);
    }

    /**
     * The one-off "your account needs a second factor" prompt.
     *
     * Reached only by being redirected here (see SecurityEnrolmentSubscriber),
     * and only while the administrator requires a second factor this account
     * does not have. It offers the same three methods the settings page does,
     * plus a way out: the skip is deliberate, so nobody is trapped mid-task by
     * a policy that changed under them. While enrolment is mandatory that
     * answer lives in the session and the prompt returns on the next sign-in;
     * SecurityPolicy::canPostponeEnrolmentPermanently() is what decides.
     */
    #[Route("/settings/security-required", name: "user_settings_enrolment")]
    public function Enrolment(Request $request)
    {
        $user = $this->getUser();
        $mandatory = $this->securityPolicy->needsEnrolment($user);
        // The same page doubles as the optional offer made after a sign-in
        // from an unknown browser (NewDeviceSubscriber) - different wording,
        // and a "no thanks" that is remembered for good.
        $offered = !$mandatory && $user
            && $request->getSession()->get(SecurityPolicy::SESSION_NEW_DEVICE_PROMPT)
            && !$this->securityPolicy->hasSecondFactor($user);
        if (!$mandatory && !$offered) {
            $request->getSession()->remove(SecurityPolicy::SESSION_NEW_DEVICE_PROMPT);

            return $this->redirectToRoute('user_settings');
        }

        return $this->render('client/user/settings_enrolment.html.twig', [
            'user' => $user,
            'policy' => $this->securityPolicy,
            'optional' => $offered,
            // No "not now" where the application allows none (base.security.two_factor.postpone: false).
            'skippable' => $offered || $this->securityPolicy->canSkipEnrolment($user),
        ]);
    }

    #[Route("/settings/security-required/skip", name: "user_settings_enrolment_skip", methods: ["POST"])]
    public function EnrolmentSkip(Request $request)
    {
        if (!$this->isCsrfTokenValid('2fa_enrolment_skip', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $session = $request->getSession();
        $offered = (bool) $session->get(SecurityPolicy::SESSION_NEW_DEVICE_PROMPT);

        // A hand-made POST gets the same answer as the missing button.
        if (!$this->securityPolicy->canSkipEnrolment($this->getUser())) {
            return $this->redirectToRoute('user_settings_enrolment');
        }
        $session->remove(SecurityPolicy::SESSION_NEW_DEVICE_PROMPT);
        $session->set(SecurityPolicy::SESSION_ENROLMENT_SKIPPED, true);

        $notification = new Notification("@notifications.settings.enrolmentSkipped");
        $notification->send("info");

        $response = $this->redirectToRoute($offered ? 'user_profile' : 'user_settings');
        if ($offered) {
            // Declined once = not asked again from this browser for a year;
            // the cookie is the only memory there is without a per-user column.
            $response->headers->setCookie(Cookie::create(SecurityPolicy::COOKIE_NEW_DEVICE_PROMPT_DISMISSED, '1', new \DateTimeImmutable('+1 year'), '/', null, null, true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    #[Route("/settings/passkeys/{id}/rename", name: "user_settings_passkey_rename", methods: ["POST"])]
    public function RenamePasskey(int $id, Request $request, PasskeyRepository $passkeyRepository, EntityManagerInterface $entityManager)
    {
        if (!$this->isCsrfTokenValid('passkey_rename_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $passkey = $passkeyRepository->find($id);
        if (!$passkey || $passkey->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $passkey->setLabel(trim((string) $request->request->get('label')) ?: null);
        $entityManager->flush();

        return $this->redirectToRoute('user_settings');
    }

    #[Route("/settings/passkeys/{id}/delete", name: "user_settings_passkey_delete", methods: ["POST"])]
    public function DeletePasskey(int $id, Request $request, PasskeyRepository $passkeyRepository, EntityManagerInterface $entityManager)
    {
        if (!$this->isCsrfTokenValid('passkey_delete_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $passkey = $passkeyRepository->find($id);
        if (!$passkey || $passkey->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $entityManager->remove($passkey);
        $entityManager->flush();

        $notification = new Notification("@notifications.settings.passkeyDeleted");
        $notification->send("success");

        return $this->redirectToRoute('user_settings');
    }

    #[Route("/settings/sessions/{id}/logout", name: "user_settings_session_logout", methods: ["POST"])]
    public function LogoutSession(int $id, Request $request, ConnectionRepository $connectionRepository, EntityManagerInterface $entityManager)
    {
        if (!$this->isCsrfTokenValid('session_logout', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $connection = $connectionRepository->find($id);

        if (!$connection || $connection->getUser() !== $user) {
            throw $this->createNotFoundException();
        }

        $connection->markAsLogout();
        $entityManager->flush();

        return $this->redirectToRoute('user_settings');
    }

    #[Route("/settings/2fa", name: "user_settings_2fa")]
    public function TwoFactorAuthentification(Request $request, EntityManagerInterface $entityManager, TotpAuthenticatorInterface $totpAuthenticator)
    {
        $user = $this->getUser();
        $session = $request->getSession();

        // The administrator can switch the whole feature off. Refuse here as
        // well as hiding the button, so a bookmarked URL is refused too.
        if (!$this->securityPolicy->canEnableTwoFactor()) {
            $notification = new Notification("@notifications.settings.twoFactorUnavailable");
            $notification->send("warning");

            return $this->redirectToRoute('user_settings');
        }

        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('user_settings');
        }

        $pendingSecret = $session->get(self::SESSION_PENDING_SECRET) ?? $totpAuthenticator->generateSecret();
        $session->set(self::SESSION_PENDING_SECRET, $pendingSecret);

        $form = $this->createForm(Login2FAType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Validate against a throwaway clone: the real, tracked $user entity must not carry an
            // unconfirmed secret, since other requests in this app flush() the entity manager as a
            // side effect (e.g. UserTracker::updateConnection()) and would silently persist it.
            $pendingUser = clone $user;
            $pendingUser->setTotpSecret($pendingSecret);

            if ($totpAuthenticator->checkCode($pendingUser, $form->get('code')->getData())) {

                $user->setTotpSecret($pendingSecret);
                $backupCodes = $this->generateBackupCodes();
                $user->setBackupCodes($backupCodes);
                $entityManager->flush();

                $session->remove(self::SESSION_PENDING_SECRET);

                $notification = new Notification("@notifications.settings.twoFactorEnabled");
                $notification->send("success");

                return $this->render('client/user/settings_2fa_backup_codes.html.twig', [
                    'backupCodes' => $backupCodes,
                ]);
            }

            $form->get('code')->addError(new \Symfony\Component\Form\FormError($this->translator->trans('@notifications.settings.twoFactorInvalidCode')));
        }

        return $this->render('client/user/settings_2fa.html.twig', [
            'form' => $form->createView(),
            'user' => $user
        ]);
    }

    #[Route("/settings/2fa/disable", name: "user_settings_2fa_disable", methods: ["POST"])]
    public function TwoFactorAuthentification_Disable(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher)
    {
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('2fa_disable', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // The precedence rule: while the administrator requires a second
        // factor, the user cannot give theirs up - password or no password.
        if (!$this->securityPolicy->canDisableTwoFactor($user)) {
            $notification = new Notification("@notifications.settings.twoFactorMandatory");
            $notification->send("warning");

            return $this->redirectToRoute('user_settings');
        }

        if (!$passwordHasher->isPasswordValid($user, (string) $request->request->get('password'))) {
            $notification = new Notification("@notifications.settings.wrongPassword");
            $notification->send("danger");
            return $this->redirectToRoute('user_settings');
        }

        $user->setTotpSecret(null);
        $user->setBackupCodes([]);
        $user->invalidateTrustedDevices();
        $entityManager->flush();

        $notification = new Notification("@notifications.settings.twoFactorDisabled");
        $notification->send("success");

        return $this->redirectToRoute('user_settings');
    }

    #[Route("/settings/2fa/email", name: "user_settings_2fa_email_toggle", methods: ["POST"])]
    public function TwoFactorAuthentification_ToggleEmail(Request $request, EntityManagerInterface $entityManager)
    {
        if (!$this->isCsrfTokenValid('2fa_email', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $enabling = !$user->isEmailAuthEnabled();

        if ($enabling && !$this->securityPolicy->canEnableTwoFactor()) {
            $notification = new Notification("@notifications.settings.twoFactorUnavailable");
            $notification->send("warning");

            return $this->redirectToRoute('user_settings');
        }

        // Turning email codes off is only a problem when they are the second
        // factor the site insists on - i.e. when nothing else would be left.
        if (!$enabling && !$this->securityPolicy->canDisableTwoFactor($user) && !$user->isTotpAuthenticationEnabled()) {
            $notification = new Notification("@notifications.settings.twoFactorMandatory");
            $notification->send("warning");

            return $this->redirectToRoute('user_settings');
        }

        $user->setEmailAuthEnabled($enabling);
        $entityManager->flush();

        $notification = new Notification($user->isEmailAuthEnabled() ? "@notifications.settings.emailCodeEnabled" : "@notifications.settings.emailCodeDisabled");
        $notification->send("success");

        return $this->redirectToRoute('user_settings');
    }

    #[Route("/settings/2fa/qr-code", name: "user_settings_2fa_qrcode")]
    public function TwoFactorAuthentification_QrCode(Request $request, TotpAuthenticatorInterface $totpAuthenticator)
    {
        $user = $this->getUser();
        $secret = $request->getSession()->get(self::SESSION_PENDING_SECRET);
        if (!$secret) {
            throw new NotFoundHttpException('No pending 2FA setup');
        }

        $pendingUser = clone $user;
        $pendingUser->setTotpSecret($secret);

        return $this->displayQrCode($totpAuthenticator->getQRContent($pendingUser));
    }
}
