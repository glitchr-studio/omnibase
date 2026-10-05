<?php

namespace Tests\Base\Commons;

use Base\Entity\Layout\Redirection;
use PHPUnit\Framework\TestCase;

class RedirectionTest extends TestCase
{
    public function testAnAddressIsKeptAsItsPath(): void
    {
        $this->assertSame('/produit/enseigne', Redirection::normalize('/produit/enseigne/'));
        $this->assertSame('/produit/enseigne', Redirection::normalize('https://old.example/produit/enseigne/#top'));
        $this->assertSame('/produit/enseigne', Redirection::normalize('produit/enseigne'));
        $this->assertSame('/?p=123', Redirection::normalize('/?p=123'));
        $this->assertSame('/', Redirection::normalize('/'));
        $this->assertSame('/été', Redirection::normalize('/%C3%A9t%C3%A9/'));
        $this->assertSame('', Redirection::normalize('  '));
    }

    public function testAnExactRedirection(): void
    {
        $redirection = new Redirection('/produit/enseigne/', 'savoir-faire/enseigne');

        $this->assertSame('/produit/enseigne', $redirection->getSource());
        $this->assertSame('/savoir-faire/enseigne', $redirection->getTarget());
        $this->assertSame(301, $redirection->getStatus());
        $this->assertSame('/savoir-faire/enseigne', $redirection->resolve('/produit/enseigne'));
        $this->assertNull($redirection->resolve('/produit/enseigne-drapeau'));
        $this->assertNull($redirection->setEnabled(false)->resolve('/produit/enseigne'));
    }

    /**
     * The database finds "/Produit/Enseigne" for "/produit/enseigne" when its
     * collation says so (MySQL's default): the row it found is not refused
     * afterwards for its case - the old address answered 404.
     */
    public function testAnExactRedirectionWhateverTheCase(): void
    {
        $redirection = new Redirection('/produit/enseigne', '/savoir-faire/enseigne');

        $this->assertSame('/savoir-faire/enseigne', $redirection->resolve('/Produit/Enseigne'));
        $this->assertSame('/savoir-faire/enseigne', (new Redirection('/Produit/Été', '/savoir-faire/enseigne'))->resolve('/produit/été'));
        $this->assertNull($redirection->resolve('/Produit/Enseigne-Drapeau'));
    }

    public function testAPrefixCarriesWhatItsStarStoodFor(): void
    {
        $all = new Redirection('/categorie-produit/*', '/savoir-faire');
        $this->assertTrue($all->isPrefix());
        $this->assertSame('/savoir-faire', $all->resolve('/categorie-produit/enseignes/lumineuses'));
        $this->assertSame('/savoir-faire', $all->resolve('/categorie-produit'));
        $this->assertNull($all->resolve('/categories'));

        $carried = new Redirection('/blog/*', '/actualites/*');
        $this->assertSame('/actualites/2024/mon-article', $carried->resolve('/blog/2024/mon-article?utm=x'));
        $this->assertSame('/actualites/', $carried->resolve('/blog'));
    }

    public function testATargetMayBeAnotherSite(): void
    {
        $this->assertSame('https://pano-group.com/charte', (new Redirection('/charte', 'https://pano-group.com/charte', Redirection::TEMPORARY))->getTarget());
    }

    public function testOnly301And302(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Redirection('/a', '/b', 307);
    }
}
