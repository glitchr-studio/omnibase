<?php

namespace Tests\Base\Demo;

use Base\Demo\DemoAccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Demo\Fixtures\DemoTestKernel;

/**
 * The host application booted in `demo` or in `prod` (Fixtures\DemoTestKernel)
 * and asked whole requests, cookies kept from one to the next as a browser
 * keeps them: the sign-in page read, its button clicked, the next page asked.
 */
abstract class DemoKernelTestCase extends KernelTestCase
{
    /** @var array<string, string> */
    private array $cookies = [];

    /** @var object[] */
    private array $created = [];

    protected static function getKernelClass(): string
    {
        return DemoTestKernel::class;
    }

    protected function bootIn(string $environment): void
    {
        if (!class_exists('App\\Kernel') || !class_exists('App\\Entity\\User')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        // What omnibase's UserTracker reads from PHP's globals on a signed-in request.
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] ??= 'phpunit';

        self::bootKernel(['environment' => $environment, 'debug' => false]);
        $this->cookies = [];
    }

    protected function tearDown(): void
    {
        if (static::$booted && $this->created) {
            $em = $this->entityManager();
            $em->clear();
            $rows = [];
            foreach ($this->created as $entity) {
                if ($entity->getId() && ($managed = $em->find($entity::class, $entity->getId()))) {
                    $rows[] = [$em->getClassMetadata($entity::class)->getTableName(), $entity->getId()];
                    $em->remove($managed);
                }
            }
            $em->flush();
            // A joined subclass's own row goes with its parent's through the foreign key - which SQLite
            // (the harness) does not enforce: the row stayed, and its unique username with it.
            foreach ($rows as [$table, $id]) {
                $em->getConnection()->executeStatement('DELETE FROM '.$table.' WHERE id = ?', [$id]);
            }
        }
        $this->created = [];
        unset($_SERVER['DEMO_SUPERADMIN_PASSWORD'], $_ENV['DEMO_SUPERADMIN_PASSWORD'], $_SERVER['DEMO_TEST_PRODUCTION_DATABASE'], $_ENV['DEMO_TEST_PRODUCTION_DATABASE']);

        parent::tearDown();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** The declared accounts as rows, as the fixtures create them. @return array<string, object> */
    protected function loadDemoAccounts(): array
    {
        $users = static::getContainer()->get(DemoAccountFactory::class)->load();
        $this->entityManager()->flush();
        foreach ($users as $user) {
            $this->created[] = $user;
        }

        return $users;
    }

    /** @param string[] $roles */
    protected function createUser(string $email, array $roles, string $password): object
    {
        $class = 'App\\Entity\\User';
        $user = new $class();
        if (method_exists($user, 'setUsername')) {
            $user->setUsername(strstr($email, '@', true));
        }
        $user->setEmail($email);
        $user->setPlainPassword($password);
        $user->setRoles($roles);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $this->created[] = $user;
    }

    /** @param array<string, mixed> $parameters */
    protected function browse(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters, $this->cookies);
        $response = static::$kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /**
     * What a controller of the application's own does while it handles a
     * request - a form saved, a row deleted: the request stack holds a
     * request with a session, as the firewall leaves it.
     *
     * @return string[] the warnings flashed meanwhile
     */
    protected function duringARequest(string $path, \Closure $work): array
    {
        $request = Request::create($path, 'POST');
        $request->setSession(static::getContainer()->get('session.factory')->createSession());
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);
        try {
            $work();
        } finally {
            $stack->pop();
        }

        return $request->getSession()->getFlashBag()->get('warning');
    }

    /** The hidden fields of the form whose button signs in as $identifier. @return array<string, string> */
    protected function demoForm(string $html, string $identifier): array
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($document);
        $form = $xpath->query(sprintf('//form[.//button[@data-demo-account="%s"]]', $identifier))->item(0);
        self::assertNotNull($form, sprintf('a button for "%s"', $identifier));
        self::assertSame('post', strtolower($form->getAttribute('method')), 'a form that posts, never a link');

        $fields = ['@action' => $form->getAttribute('action')];
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $fields;
    }
}
