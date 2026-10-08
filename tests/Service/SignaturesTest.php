<?php

namespace Tests\Base\Service;

use Base\Entity\Signature\Envelope;
use Base\Event\SignatureEvent;
use Base\Service\Signatures;
use Doctrine\ORM\EntityManagerInterface;
use Omnisign\Docuseal\DocusealGatewayFactory;
use Omnisign\Model\Envelope as Request;
use Omnisign\Model\Signer;
use Omnisign\Registry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Documents signed for any entity through glitchr/omnisign, on a real
 * database: a lease sent for a member, signed in the site's page, followed
 * until completed, its signed PDF and its evidence kept in the private
 * storage, its events dispatched, the provider's webhook checked. DocuSeal
 * answers as it answered an instance of its open-source edition (the
 * recorded answers of omnisign/docuseal's tests). Skipped without
 * glitchr/omnisign and omnisign/docuseal.
 */
final class SignaturesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel') || !class_exists(Registry::class) || !class_exists(DocusealGatewayFactory::class)) {
            self::markTestSkipped('Requires the host application kernel and glitchr/omnisign with omnisign/docuseal (the omnibase harness).');
        }
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    /** @param list<string> $fetches the submissions DocuSeal answers, in turn */
    private function signatures(array $fetches, ?EventDispatcher $dispatcher = null): Signatures
    {
        $fixtures = \dirname((string) (new \ReflectionClass(DocusealGatewayFactory::class))->getFileName()).'/Tests/Fixtures/';
        $http = new MockHttpClient(function (string $method, string $url) use (&$fetches, $fixtures): MockResponse {
            $this->calls[] = $method.' '.$url;
            if (str_contains($url, '/file/')) {
                return new MockResponse(str_contains($url, 'Audit%20Log') ? '%PDF-1.6 audit' : '%PDF-1.4 signed');
            }
            // The last state answered stays: downloading asks the submission again (its files' addresses).
            $file = 'POST' === $method ? 'submissions-create' : (\count($fetches) > 1 ? array_shift($fetches) : ($fetches[0] ?? throw new \LogicException('No answer for '.$url)));

            return new MockResponse((string) file_get_contents($fixtures.$file.'.json'));
        });
        $registry = new Registry([new DocusealGatewayFactory($http)], ['contracts' => ['factory' => 'docuseal', 'options' => ['url' => 'http://localhost:13300/api', 'api_key' => 'k', 'webhook_secret' => 'whsec_test']]]);

        return new Signatures($this->em, static::getContainer()->get('flysystem'), $registry, $dispatcher, null, null, null, static::getContainer()->getParameter('base.uploader.storage'));
    }

    private function member(): object
    {
        $user = new \App\Entity\User();
        $user->setEmail('tenant-'.bin2hex(random_bytes(4)).'@example.org');
        if (method_exists($user, 'setUsername')) {
            $user->setUsername('tenant-'.bin2hex(random_bytes(4)));
        }
        $user->setPlainPassword(bin2hex(random_bytes(8)));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testTheFamilysServiceIsThereWithItsGateway(): void
    {
        $signatures = static::getContainer()->get(Signatures::class);
        self::assertSame('contracts', $signatures->gateway(), 'the only gateway configured (docker/app/config/packages/omnisign.yaml)');
        self::assertTrue($this->em->getMetadataFactory()->hasMetadataFor(Envelope::class) || !$this->em->getMetadataFactory()->isTransient(Envelope::class), 'an entity, the family installed');
    }

    public function testALeaseIsSentSignedInThePageFollowedAndKept(): void
    {
        $events = [];
        $dispatcher = new EventDispatcher();
        foreach ([SignatureEvent::SIGNED, SignatureEvent::COMPLETED] as $name) {
            $dispatcher->addListener($name, static function (SignatureEvent $e) use (&$events, $name) { $events[] = [$name, $e->getSigner(), $e->getSubject()?->getId()]; });
        }
        $signatures = $this->signatures(['submission-pending', 'submission-pending', 'submission-completed'], $dispatcher);
        $member = $this->member();

        $envelope = $signatures->send($member, new Request('Bail - harness', signers: [new Signer('tenant', 'Camille Érable', 'camille@erable.example')], embedded: true, template: '1', key: 'lease-42'));
        self::assertNotNull($envelope->getId());
        self::assertSame(['contracts', '4', Envelope::STATUS_SENT], [$envelope->getGateway(), $envelope->getReference(), $envelope->getStatus()]);
        self::assertSame([$this->em->getClassMetadata($member::class)->getName(), (string) $member->getId()], [$envelope->getSubjectClass(), $envelope->getSubjectId()], 'about the member: any entity, by its class and id');
        self::assertSame([['key' => 'tenant', 'name' => 'Camille Érable', 'email' => 'camille@erable.example', 'status' => 'waiting', 'signedAt' => null]], $envelope->getSigners());
        self::assertMatchesRegularExpression('~^http://localhost:13300/s/\w+$~', $signatures->signingUrl($envelope, 'tenant'), 'signed in the site\'s page');

        // Asked again from what was kept, in another request.
        $this->em->clear();
        $envelope = $this->em->find(Envelope::class, $envelope->getId());
        $signatures->refresh($envelope);
        self::assertSame(Envelope::STATUS_SENT, $envelope->getStatus(), 'pending');
        self::assertSame([], $events);
        $signatures->refresh($envelope);
        self::assertSame(Envelope::STATUS_COMPLETED, $envelope->getStatus());
        self::assertNotNull($envelope->getCompletedAt());
        self::assertSame([[SignatureEvent::SIGNED, 'tenant', $member->getId()], [SignatureEvent::COMPLETED, null, $member->getId()]], $events);

        // Kept in the private storage, out of public/.
        self::assertSame('%PDF-1.4 signed', $signatures->signed($envelope));
        self::assertSame('%PDF-1.6 audit', $signatures->evidence($envelope));
        self::assertMatchesRegularExpression('~^signatures/\d{4}/[0-9a-f]{16}/lease\.pdf$~', (string) $envelope->getSignedFile());
        $storage = static::getContainer()->getParameter('base.uploader.storage');
        self::assertStringNotContainsString('public', (string) static::getContainer()->get('flysystem')->prefixPath($envelope->getSignedFile(), $storage));
        self::assertSame([$envelope], $signatures->of($member));

        // Completed and kept: nothing more is asked.
        $calls = \count($this->calls);
        $signatures->refresh($envelope);
        self::assertCount($calls, $this->calls);
    }

    public function testTheProvidersWebhookIsCheckedThenTheEnvelopeAskedAgain(): void
    {
        $signatures = $this->signatures(['submission-pending', 'submission-completed']);
        $envelope = $signatures->send($this->member(), new Request('Bail', signers: [new Signer('tenant', 'Camille Érable', 'camille@erable.example')], embedded: true, template: '1'));
        $body = '{"event_type":"form.completed","timestamp":"2026-10-08T01:14:25Z","data":{"id":4,"submission_id":4,"external_id":"tenant","status":"completed"}}';
        $time = (string) time();

        self::assertFalse($signatures->notify('contracts', $body, ['X-Docuseal-Signature' => $time.'.'.hash_hmac('sha256', $time.'.'.$body, 'another')]), 'a signature that does not hold');
        self::assertSame(Envelope::STATUS_SENT, $envelope->getStatus());

        $updated = $signatures->notify('contracts', $body, ['X-Docuseal-Signature' => $time.'.'.hash_hmac('sha256', $time.'.'.$body, 'whsec_test')]);
        self::assertSame($envelope, $updated);
        self::assertSame(Envelope::STATUS_COMPLETED, $envelope->getStatus());
        self::assertNull($signatures->notify('nowhere', $body, []), 'no such gateway');
    }

    public function testTheWebhooksAddressAnswersWithoutTheProvider(): void
    {
        $client = static::getContainer()->get('http_kernel');
        $refused = $client->handle(\Symfony\Component\HttpFoundation\Request::create('/signatures/contracts/webhook', 'POST', [], [], [], ['HTTP_X_DOCUSEAL_SIGNATURE' => time().'.forged'], '{"event_type":"form.completed","data":{"submission_id":4}}'));
        self::assertSame(400, $refused->getStatusCode(), 'its signature checked by the gateway');
        self::assertSame(404, $client->handle(\Symfony\Component\HttpFoundation\Request::create('/signatures/nowhere/webhook', 'POST'))->getStatusCode());
    }
}
