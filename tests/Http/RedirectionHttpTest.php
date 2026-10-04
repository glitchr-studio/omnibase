<?php

namespace Tests\Base\Http;

use Base\Entity\Layout\Redirection;
use Base\Service\Redirections;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The redirections as a visitor meets them: an old address answers 301 (or
 * 302) to the new one and is counted; an address the site answers is left
 * alone; an unknown one is still not found.
 */
class RedirectionHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    private Redirections $redirections;

    protected function setUp(): void
    {
        $this->bootHost();
        $this->entityManager()->createQuery('DELETE FROM '.Redirection::class.' r')->execute();
        $this->redirections = static::getContainer()->get(Redirections::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager()->clear();
        $this->entityManager()->createQuery('DELETE FROM '.Redirection::class.' r')->execute();
        parent::tearDown();
    }

    public function testAnOldAddressLeadsToTheNewOneAndIsCounted(): void
    {
        $this->redirections->add('/produit/enseigne-lumineuse/', '/savoir-faire/enseigne');

        $response = $this->request('/produit/enseigne-lumineuse/');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/savoir-faire/enseigne', $response->headers->get('Location'));

        // Without its trailing slash, and with a campaign's query, which follows.
        $response = $this->request('/produit/enseigne-lumineuse', null, 'GET', ['utm_source' => 'mail']);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/savoir-faire/enseigne?utm_source=mail', $response->headers->get('Location'));

        $this->entityManager()->clear();
        $redirection = $this->entityManager()->getRepository(Redirection::class)->findOneBy(['source' => '/produit/enseigne-lumineuse']);
        $this->assertSame(2, $redirection->getHits());
        $this->assertNotNull($redirection->getLastHitAt());
    }

    public function testATemporaryOneAWordpressQueryAndAPrefix(): void
    {
        $this->redirections->add('/soldes', '/boutique', Redirection::TEMPORARY);
        $this->redirections->add('/?p=123', '/actualites/ouverture');
        $this->redirections->add('/categorie-produit/*', '/savoir-faire/*');
        $this->redirections->add('/categorie-produit/enseignes/*', '/savoir-faire/enseigne');

        $this->assertSame(302, $this->request('/soldes')->getStatusCode());
        $this->assertSame('/savoir-faire/vitrines', $this->request('/categorie-produit/vitrines/')->headers->get('Location'));
        $this->assertSame('/savoir-faire/enseigne', $this->request('/categorie-produit/enseignes/lumineuses')->headers->get('Location'), 'the longest prefix wins');
    }

    public function testAnUnknownAddressIsStillNotFound(): void
    {
        $this->redirections->add('/ancien', '/nouveau');

        $this->assertSame(404, $this->request('/nowhere-at-all')->getStatusCode());
        $this->assertSame(404, $this->request('/ancien', null, 'POST')->getStatusCode(), 'a form is not replayed elsewhere');

        $disabled = $this->redirections->add('/ferme', '/ouvert');
        $disabled->setEnabled(false);
        $this->entityManager()->flush();
        $this->assertSame(404, $this->request('/ferme')->getStatusCode());
    }

    public function testAPageTheSiteAnswersIsNeverRedirected(): void
    {
        $this->redirections->add('/login', '/somewhere-else');

        $response = $this->request('/login');
        $this->assertNotSame('/somewhere-else', $response->headers->get('Location'));
        $this->assertNotSame(404, $response->getStatusCode());
    }
}
