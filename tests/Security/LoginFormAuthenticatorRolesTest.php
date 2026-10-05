<?php

namespace Tests\Base\Security;

use Base\Entity\User;
use Base\Entity\User\Group;
use Base\Routing\AdvancedRouterInterface;
use Base\Security\LoginFormAuthenticator;
use Base\Service\ReferrerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Tests\Base\Http\HttpTestTrait;

/**
 * What a successful sign-in does to the account's roles: a stored role the
 * UserRole enum does not know is dropped from the account's own roles, and a
 * role held through a group is neither a stored role nor removed - the group
 * is left as it is.
 */
class LoginFormAuthenticatorRolesTest extends KernelTestCase
{
    use HttpTestTrait;

    protected function setUp(): void
    {
        // App\Entity\User and App\Enum\UserRole are the host's; an account
        // reads its locale and timezone from the running application.
        $this->bootHost();
    }

    private function signedIn(User $user): void
    {
        $referrer = $this->createMock(ReferrerInterface::class);
        $referrer->method('getUrl')->willReturn('/after');
        $referrer->method('sameSite')->willReturn(true);

        $router = $this->createMock(AdvancedRouterInterface::class);
        $router->method('redirect')->willReturn(new RedirectResponse('/after'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->createMock('App\\Repository\\UserRepository'));
        $entityManager->expects($this->once())->method('flush');

        $request = Request::create('/login', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $authenticator = new LoginFormAuthenticator($referrer, $entityManager, $router, $this->createMock(AuthorizationCheckerInterface::class));
        $response = $authenticator->onAuthenticationSuccess($request, new UsernamePasswordToken($user, 'main', $user->getRoles()), 'main');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    private function user(array $ownRoles, array $groupRoles = []): User
    {
        $class = 'App\\Entity\\User';
        $user = new $class();   // never persisted
        $user->setRoles($ownRoles);
        if ($groupRoles) {
            $user->addGroup((new Group())->setName('Praticiens')->setRoles($groupRoles));
        }

        return $user;
    }

    public function testARoleHeldThroughAGroupIsLeftAlone(): void
    {
        $user = $this->user(['ROLE_USER'], ['ROLE_PRACTITIONER', 'ROLE_SECRETARY']);

        $this->signedIn($user);

        $this->assertSame(['ROLE_USER'], array_values($user->getOwnRoles()));
        $this->assertEqualsCanonicalizing(['ROLE_USER', 'ROLE_PRACTITIONER', 'ROLE_SECRETARY'], $user->getRoles());
        $this->assertSame(['ROLE_PRACTITIONER', 'ROLE_SECRETARY'], $user->getGroups()->first()->getRoles());
    }

    public function testAStoredRoleTheEnumDoesNotKnowIsDroppedFromTheAccountOnly(): void
    {
        $user = $this->user(['ROLE_ADMIN', 'ROLE_NO_LONGER_A_ROLE'], ['ROLE_PRACTITIONER']);

        $this->signedIn($user);

        $this->assertSame(['ROLE_ADMIN'], array_values($user->getOwnRoles()));
        $this->assertEqualsCanonicalizing(['ROLE_ADMIN', 'ROLE_PRACTITIONER'], $user->getRoles());
    }

    public function testARoleHeldBothWaysStaysBothWays(): void
    {
        // ROLE_NO_LONGER_A_ROLE is stored on the account and also given by a
        // group: the account's own copy goes, the group's is the group's.
        $user = $this->user(['ROLE_USER', 'ROLE_NO_LONGER_A_ROLE'], ['ROLE_NO_LONGER_A_ROLE']);

        $this->signedIn($user);

        $this->assertSame(['ROLE_USER'], array_values($user->getOwnRoles()));
        $this->assertContains('ROLE_NO_LONGER_A_ROLE', $user->getRoles());
    }
}
