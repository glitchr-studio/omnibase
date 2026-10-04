<?php

namespace Tests\Base\Http;

use Base\Controller\ErrorController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * omnibase's error controller (framework.error_controller:
 * Base\Controller\ErrorController::Main) answers an error under its own
 * status. Its debug page answered 404 for everything: a refusal (403) and a
 * validation failure (422) of a signed-in user read as "not found" in dev and
 * in every application's tests.
 */
class ErrorStatusTest extends KernelTestCase
{
    private ErrorController $controller;

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        // As the kernel does when it handles a request: the debug page takes the output written since.
        $request = Request::create('/somewhere');
        $request->headers->set('X-Php-Ob-Level', (string) ob_get_level());
        static::getContainer()->get('request_stack')->push($request);
        $this->controller = static::getContainer()->get(ErrorController::class);
    }

    public static function errors(): iterable
    {
        yield 'a refusal' => [new AccessDeniedHttpException('No.'), 403];
        yield 'a payload that does not validate' => [new UnprocessableEntityHttpException('Invalid.'), 422];
        yield 'a page that does not exist' => [new NotFoundHttpException('Nowhere.'), 404];
        yield 'anything else' => [new \RuntimeException('Boom.'), 500];
    }

    /** @dataProvider errors */
    public function testTheDebugPageKeepsTheErrorsStatus(\Throwable $error, int $status): void
    {
        $response = $this->controller->Rescue($error);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertStringContainsString($error->getMessage(), (string) $response->getContent());
    }

    public function testMainInDebug(): void
    {
        if (!static::$kernel->isDebug()) {
            self::markTestSkipped('The debug page is the answer in debug only.');
        }

        $this->assertSame(403, $this->controller->Main(new AccessDeniedHttpException('No.'))->getStatusCode());
        $this->assertSame(422, $this->controller->Main(new UnprocessableEntityHttpException('Invalid.'))->getStatusCode());
    }
}
