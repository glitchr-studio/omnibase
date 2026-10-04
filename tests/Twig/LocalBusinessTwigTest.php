<?php

namespace Tests\Base\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/** local_business_jsonld() in a template of a host application: the bundle registers it, the script is printed as it is. */
class LocalBusinessTwigTest extends KernelTestCase
{
    public function testTheFunctionPrintsThePagesScript(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        static::getContainer()->get('request_stack')->push(Request::create('https://example.test/'));
        $twig = static::getContainer()->get('twig');

        $html = $twig->createTemplate("{{ local_business_jsonld({name: 'Chez Nous', telephone: '01 02 03 04 05'}) }}")->render();
        $this->assertStringStartsWith('<script type="application/ld+json">', $html);
        $data = json_decode(substr($html, 35, -9), true);
        $this->assertSame('LocalBusiness', $data['@type']);
        $this->assertSame('Chez Nous', $data['name']);
        $this->assertSame('01 02 03 04 05', $data['telephone']);
        $this->assertSame('https://example.test/', $data['url']);

        $this->assertSame('Chez Nous', $twig->createTemplate("{{ local_business({name: 'Chez Nous'}).name }}")->render());
    }
}
