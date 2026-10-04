<?php

namespace Tests\Base\Http;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Whole requests through the host application's kernel, signed in or not,
 * for a KernelTestCase: the harness has no browser-kit, and a request handed
 * to the kernel goes through the firewall, the subscribers and the
 * controller as a visitor's does.
 *
 *     $this->bootHost();                                  // skips without a host application
 *     $user = $this->createUser(['ROLE_ADMIN']);
 *     $response = $this->request('/settings', $user);
 *
 * The signed-in user is carried by a session, as after a real sign-in (the
 * "main" firewall reads it back and refreshes the user from the database).
 * Users created here are removed by removeUsers(), to call from tearDown().
 */
trait HttpTestTrait
{
    /** @var object[] */
    private array $createdUsers = [];

    protected function bootHost(): void
    {
        if (!class_exists('App\\Kernel') || !class_exists('App\\Entity\\User')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();

        // What omnibase's UserTracker reads from PHP's globals on a signed-in request.
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] ??= 'phpunit';
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @param string[] $roles */
    protected function createUser(array $roles = ['ROLE_USER']): object
    {
        $class = 'App\\Entity\\User';
        $user = new $class();
        $name = 'test'.bin2hex(random_bytes(4));
        if (method_exists($user, 'setUsername')) {
            $user->setUsername($name);
        }
        $user->setEmail($name.'@example.org');
        $user->setPlainPassword('test-'.$name);
        $user->setRoles($roles);

        $em = $this->entityManager();
        $em->persist($user);
        $em->flush();

        return $this->createdUsers[] = $user;
    }

    protected function removeUsers(): void
    {
        if (!$this->createdUsers) {
            return;
        }
        $em = $this->entityManager();
        foreach ($this->createdUsers as $user) {
            $managed = $em->find($user::class, $user->getId());
            if ($managed) {
                $em->remove($managed);
            }
        }
        $em->flush();
        $this->createdUsers = [];
    }

    /**
     * @param array<string, mixed> $parameters query (GET) or body (POST)
     * @param array<string, string> $server    e.g. ['HTTP_ACCEPT' => 'application/json']
     */
    protected function request(string $path, ?object $user = null, string $method = 'GET', array $parameters = [], array $server = [], string $firewall = 'main'): Response
    {
        $request = Request::create($path, $method, $parameters, [], [], $server);

        if ($user) {
            $session = static::getContainer()->get('session.factory')->createSession();
            $session->set('_security_'.$firewall, serialize(new UsernamePasswordToken($user, $firewall, $user->getRoles())));
            $session->save();
            $request->cookies->set($session->getName(), $session->getId());
        }

        return static::$kernel->handle($request);
    }
}
