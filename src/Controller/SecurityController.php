<?php

namespace Base\Controller;

use App\Entity\User;

use Base\Entity\User\Notification;
use Base\Form\FormProxyInterface;
use Base\Notifier\NotifierInterface;
use Base\Routing\AdvancedRouterInterface;
use Base\Security\LoginFormAuthenticator;

use App\Form\Type\SecurityRegistrationType;
use App\Form\Type\SecurityLoginType;
use Base\Attributes\Attribute\IsGranted;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

use Base\Entity\User\Token;
use Base\Form\Type\SecurityResetPasswordType;
use App\Repository\UserRepository;
use Base\Attributes\Attribute\Iconize;
use Base\Form\FormProxy;
use Base\Form\FormProcessorInterface;
use Base\Form\Type\SecurityLoginTokenType;
use Base\Service\SpeculativeRequest;
use Base\Service\ReferrerInterface;
use Base\Form\Type\SecurityResetPasswordConfirmType;
use Base\Repository\User\TokenRepository;

use Base\Security\RescueFormAuthenticator;
use Base\Service\MaintenanceProviderInterface;
use Base\Service\LauncherInterface;
use Base\Service\ParameterBagInterface;
use Base\Service\SecurityPolicy;
use Base\Service\TranslatorInterface;
use Base\Validator\Constraints\UniqueEntity;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

class SecurityController extends AbstractController
{
    /**
     * @var TranslatorInterface
     */
    protected TranslatorInterface $translator;

    /**
     * @var TokenStorageInterface
     */
    protected TokenStorageInterface $tokenStorage;

    /**
     * @var FormProxyInterface
     */
    protected FormProxyInterface $formProxy;

    /**
     * @var NotifierInterface
     */
    protected NotifierInterface $notifier;

    /**
     * @var AdvancedRouterInterface
     */
    protected AdvancedRouterInterface $router;

    /**
     * @var ParameterBagInterface
     */
    protected ParameterBagInterface $parameterBag;

    /**
     * @var EntityManagerInterface
     */
    protected EntityManagerInterface $entityManager;

    /**
     * @var UserRepository
     */
    protected UserRepository $userRepository;

    /**
     * @var TokenRepository
     */
    protected TokenRepository $tokenRepository;

    public function __construct(
        NotifierInterface       $notifier,
        EntityManagerInterface  $entityManager,
        TokenRepository         $tokenRepository,
        UserRepository          $userRepository,
        AdvancedRouterInterface $router,
        FormProxy               $formProxy,
        TokenStorageInterface   $tokenStorage,
        TranslatorInterface     $translator,
        ParameterBagInterface   $parameterBag)
    {
        $this->router = $router;
        $this->translator = $translator;
        $this->tokenStorage = $tokenStorage;
        $this->formProxy = $formProxy;
        $this->parameterBag = $parameterBag;
        $this->notifier = $notifier;

        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
        $this->tokenRepository = $tokenRepository;
    }

    #[Route("/login", name: "security_login")]
    #[Iconize("fa-solid fa-fw fa-arrow-right-to-bracket")]
    public function Login(Request $request, ReferrerInterface $referrer, AuthenticationUtils $authenticationUtils): Response
    {
        // In case of maintenance, still allow some users to login
        if ($this->isGranted("EXCEPTION_ACCESS")) {
            return $this->redirectToRoute(RescueFormAuthenticator::LOGIN_ROUTE);
        }

        // Redirect to the right page when access denied
        if (($user = $this->getUser())) {

            // Remove expired tokens
            $user->removeExpiredTokens();

            if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
                return $this->redirect($referrer->getUrl() ?? $this->router->getUrlIndex());
            }

            $notification = new Notification("login.partial");
            $notification->send("info");
        }

        // Generate form
        $lastUsername = $authenticationUtils->getLastUsername();
        $formProcessor = $this->formProxy
            ->createProcessor("form:login", SecurityLoginType::class, [
                "identifier" => $lastUsername,
                "allow_login_token" => $this->parameterBag->get("base.user.login_with_token")
            ])
            ->handleRequest($request);

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        return $this->render('security/login.html.twig', [
            "identifier" => $lastUsername,
            "form" => $formProcessor->getForm()->createView(),
            "last_username" => $lastUsername,
            "error" => $error
        ]);
    }

    #[Route("/logout", name: "security_logout")]
    #[Iconize("fa-solid fa-fw fa-right-from-bracket")]
    public function Logout(Request $request, ReferrerInterface $referrer)
    {
        // A prefetch is not a click. Honouring one here would sign the user
        // out while the page keeps drawing itself signed-in, and whatever they
        // then submit posts as an anonymous visitor (prod 2026-09-11: two
        // comment replies lost). Answer with nothing to cache.
        if (SpeculativeRequest::is($request)) {
            return new Response("", Response::HTTP_NO_CONTENT, ["Cache-Control" => "no-store"]);
        }

        // If user is found.. go to the logout request page
        if ($this->getUser()) {
            $response = $this->redirectToRoute(LoginFormAuthenticator::LOGOUT_REQUEST_ROUTE);
            $response->headers->clearCookie('REMEMBERME', "/", $this->router->getDomain());

            return $response;
        }

        // Check if the session is found.. meaning, the user just logged out
        $left = $request->getSession()?->remove("_user");
        // An id (SecuritySubscriber::onLogout); an older session may still hold the entity.
        $user = is_object($left) && method_exists($left, "getId") ? $left->getId() : $left;
        $user = is_scalar($user) ? $this->userRepository->find($user) : null;
        if ($user) {
            if ($user->isKicked()) {

                $notification = new Notification("kickout", [$user]);
                $notification->setUser($user);
                $notification->send("warning");
                $user->kick(0);

            } else {

                $notification = new Notification("logout.success", [$user]);
                $notification->send("info");
            }

            // Remove expired tokens
            $user->removeExpiredTokens();
            $this->entityManager->flush();
        }

        $returnUrl = $referrer->getUrl() ?? $this->router->getUrlIndex();
        if($this->router->isSecured($request)) $returnUrl = $this->router->getUrlIndex();

        // Redirect to previous page
        return $this->redirect($returnUrl);
    }

    #[Route("/logout-request", name: "security_logoutRequest")]
    public function LogoutRequest()
    {
        throw new Exception("This page should not be displayed.. Firewall should take over during logout process. Please check your configuration..");
    }

    #[Route("/login/token/{token}", name: "security_loginWithToken")]
    #[Iconize("fa-solid fa-fw fa-arrow-right-to-bracket")]
    public function LoginTokenRequest(Request $request, ReferrerInterface $referrer, AuthenticationUtils $authenticationUtils, LoginFormAuthenticator $authenticator, UserAuthenticatorInterface $userAuthenticator, ?string $token = null): Response
    {
        if(!$this->parameterBag->get("base.user.login_with_token")) {
            return $this->redirectToRoute(LoginFormAuthenticator::LOGIN_ROUTE);
        }

        // e.g. query the "access token" database to search for this token
        $loginToken = $this->tokenRepository->findOneByNameAndValueAndIsLogginable("login-token", $token, true);
        if ($loginToken !== null && $loginToken->isValid() && $loginToken->getUser() !== null) {

            // and return a UserBadge object containing the user identifier from the found token
            $user = $loginToken->getUser();
            $rememberMeBadge = new RememberMeBadge();
            $rememberMeBadge->enable();

            $userAuthenticator->authenticateUser($user, $authenticator, $request, [$rememberMeBadge]);
            return $this->redirectToRoute($referrer->getUrl() ?? $this->router->getUrlIndex());
        }

        // Generate form
        $formProcessor = $this->formProxy
            ->createProcessor("form:login", SecurityLoginTokenType::class)
            ->onSubmit(function (FormProcessorInterface $formProcessor, Request $request) use ($referrer, $userAuthenticator, $authenticator) {

                $email = $formProcessor->getForm()->get('email')->getData();
                if (($user = $this->userRepository->findOneByEmail($email))) {

                    $user->removeExpiredTokens();

                    $loginToken = $user->getToken("login-token");
                    if(!$loginToken) {

                        $loginToken = new Token("login-token", 30 * 24 * 3600, 3600);
                        $loginToken->markAsLogginable();
                        $loginToken->setUser($user);

                        $this->entityManager->flush();

                        $notification = $this->notifier->sendLoginToken($user, $loginToken);
                        $notification->send("success");
                    }
                }

                return $this->redirectToRoute($referrer->getUrl() ?? $this->router->getUrlIndex());
            })
            ->handleRequest($request);
        
        // last username entered by the user
        return $this->render('security/login_token.html.twig', [
            "form" => $formProcessor->getForm()->createView()
        ]);
    }

    #[Route("/register", name: "security_register")]
    public function Register(Request $request, LoginFormAuthenticator $authenticator, UserAuthenticatorInterface $userAuthenticator): Response
    {
        // If already connected..
        if (($user = $this->getUser()) && $user->isPersistent()) {
            $notification = new Notification("login.already");
            $notification->send("warning");

            return $this->redirectToRoute('user_profile');
        }

        // Prepare registration form
        $formProcessor = $this->formProxy->createProcessor("form:login", SecurityRegistrationType::class, [
            'validation_groups' => ['new'],
            'validation_entity' => User::class
        ])
            ->onSubmit(function (FormProcessorInterface $formProcessor, Request $request) use ($userAuthenticator, $authenticator) {
                
                $newUser = $formProcessor->hydrate((new User()));

                // An account might require to be verified by an admin
                $adminApprovalRequired = !$this->parameterBag->get("base.user.register.autoapprove") ?? false;
                $newUser->approve(!$adminApprovalRequired);
                $newUser->setPlainPassword($formProcessor->getData("plainPassword"));
                $this->nameAccount($newUser);

                // Social account connection
                if (($user = $this->getUser()) && $user->isVerified()) {
                    $newUser->verify($user->isVerified());
                }

                if ($newUser->isVerified() && $this->parameterBag->get("base.user.register.notify_admins")) {
                    $this->notifier->sendUserApprovalRequest($newUser);
                }

                $this->entityManager->persist($newUser);
                $this->entityManager->flush();

                $rememberMeBadge = new RememberMeBadge();
                $rememberMeBadge->enable();

                $userAuthenticator->authenticateUser($newUser, $authenticator, $request, [$rememberMeBadge]);
                return $this->redirectToRoute('user_profile');
            })
            ->onDefault(function (FormProcessorInterface $formProcessor) {
                return $this->render('security/register.html.twig', [
                    'form' => $formProcessor->getForm()->createView(),
                    'user' => $formProcessor->getData(),
                    // The address already has an account: the page says so, and gives the ways in.
                    'account_exists' => $this->addressIsTaken($formProcessor->getForm()),
                ]);
            })
            ->handleRequest($request);

        return $formProcessor->getResponse();
    }

    /**
     * Whether the sign-up was refused because its address already has an
     * account. A sign-up that succeeds signs in at once, so the page cannot
     * hide that an address is taken: it says it, and offers to sign in or to
     * ask for a new password - where the answer is the same for every address.
     */
    private function addressIsTaken(FormInterface $form): bool
    {
        if (!$form->isSubmitted()) {
            return false;
        }

        foreach ($form->getErrors(true) as $error) {
            $cause = $error->getCause();
            $constraint = $cause instanceof ConstraintViolationInterface ? $cause->getConstraint() : null;
            if ($constraint instanceof UniqueEntity && \in_array("email", (array) $constraint->fields, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An application's User usually carries a username - a column of its own,
     * unique and not null - which the sign-up form does not ask for: the
     * account was inserted without one and the sign-up answered 500. It is
     * given the local part of its address, numbered when somebody has it
     * ("anne", "anne2"), as an invitation's or a demonstration's account is
     * named. A form that asks for a username (an application's own type)
     * keeps what was typed; a User without that column is left alone.
     */
    private function nameAccount(User $user): void
    {
        if (!method_exists($user, "setUsername") || !method_exists($user, "getUsername")) {
            return;
        }
        if (!$this->entityManager->getClassMetadata($user::class)->hasField("username") || trim((string) $user->getUsername()) !== "") {
            return;
        }

        $base = preg_replace('/[^\p{L}\p{N}._-]+/u', "", mb_strtolower((string) strstr($user->getEmail() . "@", "@", true)));
        $base = mb_substr($base !== "" && $base !== null ? $base : "user", 0, 200);

        $repository = $this->entityManager->getRepository($user::class);
        $username = $base;
        for ($n = 2; $repository->findOneBy(["username" => $username]); ++$n) {
            $username = $base . $n;
        }

        $user->setUsername($username);
    }

    #[Route("/verify-email", name: "security_verifyEmail")]
    #[IsGranted("ROLE_USER")]
    public function VerifyEmailRequest()
    {
        // Check if accound is already verified..
        $user = $this->getUser();
        if ($user->isVerified()) {
        
            $notification = new Notification("verifyEmail.already");
            $notification->send("info");
        
        } else {

            $verifyEmailToken = $user->getToken("verify-email");
            if ($verifyEmailToken && $verifyEmailToken->hasVeto()) {

                $notification = new Notification("verifyEmail.resend", [$verifyEmailToken->getThrottleTimeStr()]);
                $notification->send("danger");

            } else {

                $verifyEmailToken = new Token("verify-email", 24 * 3600, 3600);
                $verifyEmailToken->setUser($user);

                $notification = $this->notifier->sendVerificationEmail($user, $verifyEmailToken);
                $notification->send("success");
            }
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('user_profile');
    }

    #[Route("/verify-email/{token}", name: "security_verifyEmailWithToken")]
    public function VerifyEmailResponse(Request $request, UserAuthenticatorInterface $userAuthenticator, LoginFormAuthenticator $authenticator, string $token): Response
    {
        $token = $this->tokenRepository->findOneByValueAndName($token, "verify-email");
        if ($token) {
            $userAuthenticator->authenticateUser($token->getUser(), $authenticator, $request);
        }

        $user = $this->getUser();
        if(!$user) {
            $notification = new Notification("verifyEmail.invalidToken");
            $notification->send("danger");

            return $this->redirectToRoute("security_verifyEmail");
       }

        $user->removeExpiredTokens();

        if ($user->isVerified()) {
            $notification = new Notification('verifyEmail.already');
            $notification->setUser($user);
            $notification->send('info');
        } else {
            $verifyEmailToken = $user->getValidToken("verify-email");

            if ($verifyEmailToken === null || $verifyEmailToken->get() != $token->get()) {
                $notification = new Notification("verifyEmail.invalidToken");
                $notification->setUser($user);
                $notification->send("danger");
            } else {
                $user->verify();
                $verifyEmailToken->revoke();

                $notification = new Notification("verifyEmail.success");
                $notification->setUser($user);
                $notification->send('success');

                if (!$user->isApproved()) { // If the account needs further validation by admin..
                    $this->AdminApprovalRequest($request);
                }
            }
        }

        $this->entityManager->flush();
        return $this->redirectToRoute('user_profile');
    }

    #[Route("/admin-approval", name: "security_adminApproval")]
    public function AdminApprovalRequest(Request $request)
    {
        $user = $this->getUser();
        $user->removeExpiredTokens();

        if (!$user->isVerified()) {
            $notification = new Notification("adminApproval.verifyFirst");
            $notification->send("warning");
        } elseif (!$user->isApproved()) {
            if (($adminApprovalToken = $user->getValidToken("admin-approval"))) {
                $notification = new Notification("adminApproval.alreadySent");
                $notification->send("warning");
            } else {
                $adminApprovalToken = new Token("admin-approval");
                $adminApprovalToken->setUser($user);

                $notification = $this->notifier->sendUserApprovalRequest($user);
                $notification->send("success");
            }
        }

        $this->entityManager->flush();
        return $this->redirectToRoute('user_profile');
    }

    #[Route("/account-goodbye", name: "security_accountGoodbye")]
    public function DisableAccountRequest(Request $request, SecurityPolicy $securityPolicy)
    {
        $user = $this->getUser();

        // A demonstration account is everyone's: it does not close itself.
        if (!$securityPolicy->canChangeCredentials($user)) {
            $notification = new Notification("@notifications.demo.locked");
            $notification->send("warning");

            return $this->redirectToRoute($this->router->getRouteIndex());
        }

        if ($user->isDisabled()) {
            $notification = new Notification("accountGoodbye.already");
            $notification->send("warning");

            return $this->redirectToRoute($this->router->getRouteIndex());
        } else {
            $user->disable();
            $user->logout();

            $this->entityManager->flush();
            return $this->redirectToRoute($this->router->getRouteIndex());
        }
    }

    #[Route("/welcome-back/{token}", name: "security_accountWelcomeBackWithToken")]
    public function EnableAccountRequest(Request $request, LoginFormAuthenticator $authenticator, UserAuthenticatorInterface $userAuthenticator, ?string $token = null): Response
    {
        $welcomeBackToken = $this->tokenRepository->findOneByValueAndName($token, "welcome-back");
        $user = $welcomeBackToken ? $welcomeBackToken->getUser() : $this->getUser();

        if ($user && !$user->isDisabled()) {
            $welcomeBackToken->revoke();

            $notification = new Notification("accountWelcomeBack.already");
            $notification->send("warning");
        } elseif ($user && $user->getValidToken("welcome-back")) {
            $user->enable();
            $authenticateUser = $userAuthenticator->authenticateUser($user, $authenticator, $request);

            $this->entityManager->flush();
            return $authenticateUser;
        } else {
            if ($welcomeBackToken) {
                $welcomeBackToken->revoke();
            }

            $notification = new Notification("accountWelcomeBack.invalidToken");
            $notification->send("danger");

            $this->entityManager->flush();
        }

        return $this->redirectToRoute($this->router->getRouteIndex());
    }

    /**
     * Display & process form to request a password reset.
     */
    
    #[Route("/reset-password", name: "security_resetPassword")]
    public function ResetPasswordRequest(Request $request, SecurityPolicy $securityPolicy): Response
    {
        if (($user = $this->getUser()) && $user->isPersistent()) {
            $notification = new Notification("login.already");
            $notification->send("warning");

            return $this->redirectToRoute('user_profile');
        }

        $form = $this->createForm(SecurityResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $notification = new Notification("resetPassword.confirmation");

            $email = $form->get('email')->getData();
            $user = $this->userRepository->findOneByEmail($email);
            if ($user && !$securityPolicy->canChangeCredentials($user)) {

                // A demonstration account keeps its password: said plainly, there is no secret about who it is.
                $notification = new Notification("@notifications.demo.locked");
                $notification->send("warning");

                return $this->redirectToRoute(LoginFormAuthenticator::LOGIN_ROUTE);
            }

            if ($user) {

                $user->removeExpiredTokens("reset-password");
                if (!$user->getToken("reset-password")) {
                    $resetPasswordToken = new Token("reset-password", 3600);
                    $resetPasswordToken->setUser($user);

                    $this->notifier->sendResetPasswordRequest($user, $resetPasswordToken);
                }
            }

            $this->entityManager->flush();
            $notification->send("success");
        }

        return $this->render('security/reset_password_request.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Validates and process the reset URL that the user clicked in their email.
     */
    
    #[Route("/reset-password/{token}", name: "security_resetPasswordWithToken")]
    public function ResetPasswordResponse(Request $request, LoginFormAuthenticator $authenticator, UserAuthenticatorInterface $userAuthenticator, SecurityPolicy $securityPolicy, ?string $token = null): Response
    {
        if (($user = $this->getUser()) && $user->isPersistent()) {
            $notification = new Notification("login.already");
            $notification->send("warning");

            return $this->redirectToRoute('user_profile');
        }

        $resetPasswordToken = $this->tokenRepository->findOneByValue($token);
        if (!$resetPasswordToken) {

            $notification = new Notification("resetPassword.invalidToken");
            $notification->send("danger");

            return $this->redirectToRoute($this->router->getRouteIndex());

        } else {

            $user = $resetPasswordToken->getUser();

            if (!$securityPolicy->canChangeCredentials($user)) {
                $notification = new Notification("@notifications.demo.locked");
                $notification->send("warning");

                return $this->redirectToRoute(LoginFormAuthenticator::LOGIN_ROUTE);
            }

            // The token is valid; allow the user to change their password.
            $form = $this->createForm(SecurityResetPasswordConfirmType::class);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $resetPasswordToken->revoke();
                $user->setPlainPassword($form->get('plainPassword')->getData());

                $notification = new Notification("resetPassword.success");

                $this->entityManager->flush();

                $rememberMeBadge = new RememberMeBadge();
                $rememberMeBadge->enable();

                $userAuthenticator->authenticateUser($user, $authenticator, $request, [$rememberMeBadge]);
                $notification->send("success");

                return $this->redirectToRoute($this->router->getRouteIndex());
            }

            return $this->render('security/reset_password.html.twig', ['form' => $form->createView()]);
        }
    }

    /**
     * Link to this controller to start the maintenance
     */
    
    #[Route("/m", name: "security_maintenance")]
    public function Maintenance(MaintenanceProviderInterface $maintenanceProvider): Response
    {
        return $this->render('security/maintenance.html.twig', [
            'remainingTime' => $maintenanceProvider->getRemainingTime(),
            'percentage' => $maintenanceProvider->getPercentage(),
            'downtime' => $maintenanceProvider->getDowntime(),
            'uptime' => $maintenanceProvider->getUptime()
        ]);
    }

    #[Route(["fr" => "/est/bientot/en/ligne", "en" => "/is/coming/soon"], name: "security_launch")]
    public function Launch(LauncherInterface $launcher): Response
    {
        return $this->render('security/launchdate.html.twig', [
            'launchdate' => $launcher->getLaunchdate(),
            'is_launchedd' => $launcher->isLaunched()
        ]);
    }

    #[Route(["fr" => "/est/bientot/disponible", "en" => "/is/soon/available"], name: "security_pending")]
    public function Pending(): Response
    {
        return $this->render('security/pending.html.twig');
    }

    #[Route(["fr" => "/en/attente/de/validation", "en" => "/waiting/for/approval"], name: "security_pendingForApproval")]
    #[IsGranted("ROLE_USER")]
    public function PendingForApproval(): Response
    {
        if ($this->getUser()->isApproved()) {
            return $this->redirectToRoute("app_index");
        }
        return $this->render('security/pendingForApproval.html.twig');
    }
}
