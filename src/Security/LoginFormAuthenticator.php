<?php

namespace Base\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Base\Entity\User\Connection;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\ReferrerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Google\Badge\CaptchaBadge;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

use Symfony\Component\Security\Http\SecurityRequestAttributes;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'security_login';
    public const LOGOUT_ROUTE = 'security_logout';
    public const LOGOUT_REQUEST_ROUTE = 'security_logoutRequest';

    /**
     * @var ReferrerInterface
     */
    protected ReferrerInterface $referrer;
    /**
     * @var EntityManagerInterface
     */
    protected EntityManagerInterface $entityManager;
    /**
     * @var AuthorizationCheckerInterface
     */
    protected AuthorizationCheckerInterface $authorizationChecker;
    /**
     * @var AdvancedRouterInterface
     */
    protected AdvancedRouterInterface $router;
    /**
     * @var UserRepository
     */
    protected UserRepository $userRepository;

    public function __construct(ReferrerInterface $referrer, EntityManagerInterface $entityManager, AdvancedRouterInterface $router, AuthorizationCheckerInterface $authorizationChecker)
    {
        $this->referrer = $referrer;
        $this->entityManager = $entityManager;
        $this->authorizationChecker = $authorizationChecker;
        $this->userRepository = $entityManager->getRepository(User::class);

        $this->router = $router;
    }

    /**
     * @param Request|string $routeNameOrRequest
     * @return bool
     */
    public static function isSecurityRoute(Request|string $routeNameOrRequest)
    {
        return in_array(is_string($routeNameOrRequest) ? $routeNameOrRequest : $routeNameOrRequest->attributes->get('_route'), [
            static::LOGIN_ROUTE,
            static::LOGOUT_ROUTE,
            static::LOGOUT_REQUEST_ROUTE
        ]);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $this->referrer->setUrl($request->getUri());
        
        $route = $this->authorizationChecker->isGranted("EXCEPTION_ACCESS") ? RescueFormAuthenticator::LOGIN_ROUTE : static::LOGIN_ROUTE;
        return new RedirectResponse($this->router->generate($route));
    }

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_route') == static::LOGIN_ROUTE && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $loginData = $request->request->all('security_login') ?: $request->request->all('_base_security_login') ?: [];
        $identifier = $loginData["identifier"] ?? $request->request->get("identifier") ?? "";
        $password = $loginData["password"] ?? $request->request->get("password") ?? "";
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $identifier);

        $badges = [];
        if (array_key_exists("_remember_me", $loginData)) {
            $badges[] = new RememberMeBadge();
            if ($loginData["_remember_me"]) {
                end($badges)->enable();
            }
        }

        if (array_key_exists("password", $loginData)) {
            $badges[] = new PasswordUpgradeBadge($password, $this->userRepository);
        }
        if (array_key_exists("_captcha", $loginData) && class_exists(CaptchaBadge::class)) {
            $badges[] = new CaptchaBadge("_captcha", $loginData["_captcha"]);
        }

        return new Passport(
            new UserBadge($identifier),
            new PasswordCredentials($password),
            $badges
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Update client information
        if (($user = $token->getUser())) {

            // A role the application's UserRole enum no longer knows is dropped
            // from the account's own roles - the `roles` column, getOwnRoles().
            // Never from getRoles(): it adds the roles of the account's groups,
            // which are free names (ROLE_PRACTITIONER, ROLE_SECRETARY...), are
            // not stored on the account and are not this method's to take away.
            $ownRoles = $user->getOwnRoles();
            $knownRoles = array_values(array_intersect($ownRoles, UserRole::getPermittedValues()));
            if (count($knownRoles) !== count($ownRoles)) {
                $user->setRoles($knownRoles);
            }

            $user->setTimezone();
            $user->setLocale();
            $user->kick(0);

            $this->entityManager->flush();
        }

        //
        // Check if target path provided via $_POST..
        $targetPath = $this->referrer;
        $targetUrl = $targetPath->getUrl();

        $targetPath->clear();

        if ($targetUrl && $targetPath->sameSite()) {
            return $this->router->redirect($targetUrl);
        }

        $defaultTargetPath = $request->getSession()->get('_security.' . $this->router->getRouteFirewall()->getName() . '.target_path');
        // An application served at the root of its host, or asked without one
        // (a command, a test), has an empty base directory: "/" then, not "".
        return $this->router->redirect($defaultTargetPath ?? ($this->router->getBaseDir() ?: '/'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->router->generate(static::LOGIN_ROUTE);
    }
}
