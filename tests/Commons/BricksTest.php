<?php

namespace Tests\Base\Commons;

use Base\Enum\Allergen;
use Base\Service\DownloadLinks;
use Base\Service\Embeds;
use Base\Service\Invitations;
use Base\Service\Qr\QrCode;
use Base\Service\Qr\QrSheet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class BricksTest extends TestCase
{
    public function testTheFourteenAllergensInTheAnnexOrder(): void
    {
        $this->assertCount(14, Allergen::cases());
        $this->assertSame(1, Allergen::GLUTEN->number());
        $this->assertSame(14, Allergen::MOLLUSCS->number());
        $this->assertSame([Allergen::GLUTEN, Allergen::MILK], Allergen::fromList(['milk', 'nonsense', 'gluten']));
        $this->assertSame('milk', Allergen::choices()['allergen.milk']);
    }

    public function testADownloadLinkIsSignedAndRunsOut(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn ($route, $parameters) => 'https://example.org/download/'.$parameters['id'].'?license='.$parameters['license']);
        $links = new DownloadLinks(new UriSigner('secret'), $router, 600);

        $url = $links->sign('forge_download_file', ['id' => 7, 'license' => 3]);
        $this->assertStringContainsString('_expiration=', $url);
        $this->assertTrue($links->verify(Request::create($url)));
        $this->assertFalse($links->verify(Request::create(str_replace('license=3', 'license=4', $url))));
    }

    public function testKnownProvidersAreFramedWithoutARequest(): void
    {
        $embeds = new Embeds(remote: false);
        $this->assertSame('https://www.youtube-nocookie.com/embed/abc123', $embeds->resolve('https://www.youtube.com/watch?v=abc123')['src']);
        $this->assertSame('https://player.vimeo.com/video/42', $embeds->resolve('https://vimeo.com/42')['src']);
        $this->assertSame('padlet', $embeds->resolve('https://padlet.com/maitresse/mur-abcdef1234')['provider']);
        $this->assertTrue($embeds->resolve('https://youtu.be/xyz')['known']);
        $this->assertNull($embeds->resolve('https://example.org/page'));
        $this->assertNull($embeds->resolve('javascript:alert(1)'));
    }

    public function testInvitationTokensAreOnlyKeptHashed(): void
    {
        $token = Invitations::token();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $token);
        $this->assertSame(hash('sha256', $token), Invitations::hash($token));
        $this->assertGreaterThan(new \DateTimeImmutable('+13 days'), Invitations::expiry(14));
    }

    public function testASheetOfLabelsPlacesEachCode(): void
    {
        $sheet = new QrSheet(new QrCode());
        $pages = $sheet->pages(array_map(fn ($i) => ['data' => 'https://example.org/t/'.$i, 'label' => 'Table '.$i], range(1, 23)), 'L7160');

        $this->assertCount(2, $pages);
        $this->assertCount(21, $pages[0]);
        $this->assertSame([7.2, 15.1], [$pages[0][0]['x'], $pages[0][0]['y']]);
        $this->assertSame([73.2, 15.1], [$pages[0][1]['x'], $pages[0][1]['y']]);
        $this->assertSame([7.2, 53.2], [$pages[0][3]['x'], $pages[0][3]['y']]);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $pages[0][0]['qr']);

        // Two labels already used: the first code goes on the third.
        $this->assertSame(139.2, $sheet->pages(['a'], 'L7160', 2)[0][0]['x']);
        $this->assertCount(20, QrSheet::layout('L7121')['columns'] * QrSheet::layout('L7121')['rows'] === 20 ? range(1, 20) : []);
        $this->assertStringContainsString('<svg', (new QrCode())->svg('hello'));
    }
}
