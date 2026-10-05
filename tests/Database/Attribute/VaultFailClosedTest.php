<?php

namespace Tests\Base\Database\Attribute;

use Base\Database\Attribute\Vault;
use Base\Database\Entity\Extension\VaultTrait;
use Base\Exception\VaultKeyNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

/**
 * #[Vault] fails closed: a secured field is refused when the vault has no
 * key pair, where it used to be written in clear without a word; a site can
 * ask for that again (base.vault.allow_plaintext). With the pair, the field
 * is sealed and read back.
 */
class VaultFailClosedTest extends TestCase
{
    private function vault(?string $keypair, bool $allowPlaintext = false): Vault
    {
        return new class($keypair, $allowPlaintext) extends Vault {
            public function __construct(private ?string $keypair, private bool $plain)
            {
                parent::__construct('vault', ['value']);
            }

            public function getKeyPath(?string $vault = null): string
            {
                return '/srv/app/config/secrets/'.$vault.'/'.$vault.'.decrypt.private.php';
            }

            public function loadKeys(?string $vault = null): array
            {
                if (null === $this->keypair) {
                    throw new VaultKeyNotFoundException((string) $vault, $this->getKeyPath($vault));
                }

                return [$this->keypair];
            }

            public function allowsPlaintext(): bool
            {
                return $this->plain;
            }
        };
    }

    private function secret(string $value, ?string $vault = 'prod'): object
    {
        $secret = new class {
            use VaultTrait;

            public mixed $value = null;
        };
        $secret->value = $value;
        $secret->setVault($vault);

        return $secret;
    }

    private function persist(Vault $vault, object $entity): void
    {
        $vault->prePersist($this->createStub(\Doctrine\Persistence\Event\LifecycleEventArgs::class), new ClassMetadata($entity::class), $entity);
    }

    public function testWithoutAKeyPairTheValueIsRefused(): void
    {
        $secret = $this->secret('sk_live_123');

        try {
            $this->persist($this->vault(null), $secret);
            $this->fail('A secured value went through without a key pair.');
        } catch (VaultKeyNotFoundException $e) {
            $this->assertSame('prod', $e->vault);
            $this->assertStringContainsString('secrets:generate-keys --env=prod', $e->getMessage());
            $this->assertStringContainsString('config/secrets/prod/prod.decrypt.private.php', $e->getMessage());
            $this->assertStringContainsString('base.vault.allow_plaintext', $e->getMessage());
            $this->assertStringNotContainsString('sk_live_123', $e->getMessage(), 'the message never carries the value');
        }

        $this->assertSame('sk_live_123', $secret->value, 'nothing was written in its place');
    }

    public function testAFieldThatIsNotSecuredIsLeftAlone(): void
    {
        $plain = $this->secret('Rue du Lac', null);
        $this->persist($this->vault(null), $plain);

        $this->assertSame('Rue du Lac', $plain->value);
    }

    public function testTheFormerBehaviourOnRequest(): void
    {
        $vault = $this->vault(null, allowPlaintext: true);
        $secret = $this->secret('sk_live_123');
        $this->persist($vault, $secret);

        $this->assertSame('sk_live_123', $secret->value, 'stored as it is: what base.vault.allow_plaintext accepts');
        $this->assertTrue($vault->canSeal('prod'));
        $this->assertFalse($this->vault(null)->canSeal('prod'));
    }

    public function testWithTheKeyPairTheValueIsSealedAndReadBack(): void
    {
        if (!\function_exists('sodium_crypto_box_keypair')) {
            $this->markTestSkipped('Requires ext-sodium.');
        }

        $vault = $this->vault(sodium_crypto_box_keypair());
        $secret = $this->secret('sk_live_123');
        $this->persist($vault, $secret);

        $this->assertNotSame('sk_live_123', $secret->value);
        $this->assertStringNotContainsString('sk_live_123', $secret->value);
        $this->assertTrue($vault->canSeal('prod'));

        $vault->postLoad($this->createStub(\Doctrine\Persistence\Event\LifecycleEventArgs::class), new ClassMetadata($secret::class), $secret);
        $this->assertSame('sk_live_123', $secret->value);
    }

    public function testAValueStoredInClearBeforeIsStillRead(): void
    {
        if (!\function_exists('sodium_crypto_box_keypair')) {
            $this->markTestSkipped('Requires ext-sodium.');
        }

        // A row written when the vault had no key: read as it is once the pair exists, sealed at its next save.
        $vault = $this->vault(sodium_crypto_box_keypair());
        $legacy = $this->secret('sk_test_legacy');
        $vault->postLoad($this->createStub(\Doctrine\Persistence\Event\LifecycleEventArgs::class), new ClassMetadata($legacy::class), $legacy);

        $this->assertSame('sk_test_legacy', $legacy->value);
    }
}
