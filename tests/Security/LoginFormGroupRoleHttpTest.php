<?php

namespace Tests\Base\Security;

use Base\Entity\User\Group;
use Base\Security\LoginFormAuthenticator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Http\HttpTestTrait;

/**
 * Signing in through the login page's form, as a visitor does (no token put
 * in a session by the test): an account whose role comes from a group -
 * ROLE_PRACTITIONER, a name the UserRole enum does not hold - is signed in
 * with that role. LoginFormAuthenticator::onAuthenticationSuccess() used to
 * call User::removeRole(), which does not exist, on every role outside the
 * enum: such an account met a 500 at each sign-in.
 */
class LoginFormGroupRoleHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    /** @var Group[] */
    private array $createdGroups = [];

    protected function setUp(): void
    {
        $this->bootHost();

        $firewall = static::getContainer()->get('security.firewall.map')->getFirewallConfig(Request::create('/login', 'POST'));
        if (!in_array(LoginFormAuthenticator::class, $firewall?->getAuthenticators() ?? [], true)) {
            self::markTestSkipped('The host application\'s firewall does not sign /login in with Base\Security\LoginFormAuthenticator (the harness does: docker/app/config/packages/security.yaml, to rebuild the image after changing it).');
        }
    }

    protected function tearDown(): void
    {
        $em = $this->entityManager();
        $this->removeUsers();
        foreach ($this->createdGroups as $group) {
            if ($group->getId() && ($managed = $em->find(Group::class, $group->getId()))) {
                $em->remove($managed);
            }
        }
        $em->flush();
        $this->createdGroups = [];

        parent::tearDown();
    }

    /** @param string[] $roles */
    private function createGroup(string $name, array $roles): Group
    {
        $group = (new Group())->setName($name.' '.bin2hex(random_bytes(3)))->setRoles($roles);
        $this->entityManager()->persist($group);
        $this->entityManager()->flush();

        return $this->createdGroups[] = $group;
    }

    /** The login form's own fields, posted where the form posts them. */
    private function signIn(object $user, ?string $password = null): Response
    {
        return static::$kernel->handle(Request::create('/login', 'POST', ['security_login' => [
            'identifier' => $user->getEmail(),
            'password' => $password ?? 'test-'.explode('@', $user->getEmail())[0],
        ]]));
    }

    /** A page asked with the cookies the sign-in answered with. */
    private function follow(Response $signedIn, string $path): Response
    {
        $request = Request::create($path);
        foreach ($signedIn->headers->getCookies() as $cookie) {
            $request->cookies->set($cookie->getName(), $cookie->getValue());
        }

        return static::$kernel->handle($request);
    }

    public function testAnAccountWhoseRoleComesFromAGroupSignsInThroughTheFormWithItsRoles(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $user->addGroup($this->createGroup('Praticiens', ['ROLE_PRACTITIONER']));
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $response = $this->signIn($user);
        $this->assertSame(302, $response->getStatusCode(), 'signed in, sent on - not the 500 of a call to User::removeRole()');
        $this->assertNotEmpty($response->headers->getCookies(), 'the session of the signed-in account');

        // The account's own page, with the session the sign-in opened: it is
        // the one signed in, and holds its group's role.
        $this->assertSame(200, $this->follow($response, '/settings')->getStatusCode());

        $token = static::getContainer()->get('security.token_storage')->getToken();
        $this->assertNotNull($token, 'signed in on the next request');
        $this->assertSame($user->getEmail(), $token->getUser()->getEmail());
        $this->assertContains('ROLE_PRACTITIONER', $token->getRoleNames());
        $this->assertContains('ROLE_USER', $token->getRoleNames());

        // Nothing of the group was written to, or taken from, the account.
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find($user::class, $user->getId());
        $this->assertSame(['ROLE_USER'], array_values($stored->getOwnRoles()));
        $this->assertContains('ROLE_PRACTITIONER', $stored->getRoles());
        $this->assertCount(1, $stored->getGroups());
        $this->assertSame(['ROLE_PRACTITIONER'], $stored->getGroups()->first()->getRoles());
    }

    public function testAnAccountWithoutGroupSignsInThroughTheForm(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $this->entityManager()->clear();

        $response = $this->signIn($user);
        $this->assertSame(302, $response->getStatusCode());

        $this->follow($response, '/settings');
        $this->assertContains('ROLE_ADMIN', static::getContainer()->get('security.token_storage')->getToken()?->getRoleNames() ?? []);
    }

    public function testAWrongPasswordIsSentBackToTheForm(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $user->addGroup($this->createGroup('Secrétariat', ['ROLE_SECRETARY']));
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $response = $this->signIn($user, 'not-the-password');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'));

        // Nobody is signed in on the next request.
        $this->follow($response, '/settings');
        $this->assertNull(static::getContainer()->get('security.token_storage')->getToken()?->getUser());
    }
}
