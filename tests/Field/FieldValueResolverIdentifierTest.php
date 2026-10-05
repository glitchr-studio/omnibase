<?php

namespace Tests\Base\Field;

use Base\Field\FieldValueResolver;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * entityIdentifier() decides what goes in an admin URL, under whatever
 * admin.url_identifier config is in force. Its field list is shared with
 * AbstractCrudController::findEntity() via identifierFieldsFor(), so
 * anything asserted here is also an assertion about what the admin must be
 * able to look up again.
 */
class FieldValueResolverIdentifierTest extends TestCase
{
    /** @param array<class-string, string[]> $byEntity */
    private function resolver(?array $fields = null, array $byEntity = [], bool $lowercase = false): FieldValueResolver
    {
        return new FieldValueResolver(null, $fields, $byEntity, $lowercase);
    }

    private function article(): object
    {
        return new class {
            public function getId(): int { return 7; }
            public function getSlug(): string { return 'my-article'; }
            public function getUuid(): string { return 'a1b2-c3d4'; }
        };
    }

    private function user(): object
    {
        return new class {
            public function getId(): int { return 14; }
            public function getUsername(): string { return 'marki'; }
        };
    }

    // -- defaults ---------------------------------------------------------

    public function testDefaultsPreferSlug(): void
    {
        $this->assertSame('my-article', $this->resolver()->entityIdentifier($this->article()));
    }

    public function testDefaultsFallBackToUuidWithoutASlug(): void
    {
        $this->assertSame('a1b2-c3d4', $this->resolver()->entityIdentifier(new class {
            public function getId(): int { return 7; }
            public function getUuid(): string { return 'a1b2-c3d4'; }
        }));
    }

    /**
     * Username is an application-level idea, so it must NOT leak into URLs
     * for an app that never asked for it - the base app identifies a User
     * by id.
     */
    public function testUsernameIsNotADefault(): void
    {
        $this->assertSame(14, $this->resolver()->entityIdentifier($this->user()));
    }

    public function testFallsBackToIdWhenNoConfiguredFieldExists(): void
    {
        $this->assertSame(14, $this->resolver()->entityIdentifier(new class {
            public function getId(): int { return 14; }
        }));
    }

    // -- configuration ----------------------------------------------------

    public function testGlobalFieldsAreConfigurable(): void
    {
        $this->assertSame('marki', $this->resolver(['username'])->entityIdentifier($this->user()));
    }

    /** The documented escape hatch for slugs long enough to make URLs unwieldy. */
    public function testEmptyFieldListForcesNumericIdsEverywhere(): void
    {
        $resolver = $this->resolver([]);

        $this->assertSame(7, $resolver->entityIdentifier($this->article()));
        $this->assertSame(14, $resolver->entityIdentifier($this->user()));
        $this->assertSame([], $resolver->identifierFieldsFor($this->article()));
    }

    public function testConfiguredOrderIsPriorityOrder(): void
    {
        $this->assertSame('a1b2-c3d4', $this->resolver(['uuid', 'slug'])->entityIdentifier($this->article()));
    }

    public function testPerEntityOverrideBeatsTheGlobalList(): void
    {
        $user = $this->user();
        $resolver = $this->resolver(['slug', 'uuid'], [$user::class => ['username']]);

        $this->assertSame('marki', $resolver->entityIdentifier($user));
        // ...and leaves every other entity on the global list.
        $this->assertSame('my-article', $resolver->entityIdentifier($this->article()));
    }

    public function testPerEntityOverrideCanForceIdsForOneEntityOnly(): void
    {
        $article = $this->article();
        $resolver = $this->resolver(['slug'], [$article::class => []]);

        $this->assertSame(7, $resolver->entityIdentifier($article));
    }

    /**
     * A Doctrine runtime proxy is a subclass of the entity, so an override
     * configured on the entity has to cover it - otherwise a proxied
     * instance would silently generate a different URL than a loaded one.
     */
    public function testOverrideOnAParentClassCoversSubclasses(): void
    {
        $resolver = $this->resolver(['slug'], [IdentifierParentFixture::class => ['username']]);

        $this->assertSame('marki', $resolver->entityIdentifier(new IdentifierChildFixture()));
        $this->assertSame(['username'], $resolver->identifierFieldsFor(IdentifierChildFixture::class));
    }

    public function testIdentifierFieldsForAcceptsAClassName(): void
    {
        $this->assertSame(['slug', 'uuid'], $this->resolver()->identifierFieldsFor(IdentifierParentFixture::class));
    }

    // -- resolution is wider than generation ------------------------------

    /**
     * Turning slugs off must shorten NEW urls without 404ing slug links
     * already in bookmarks, history or an email.
     */
    public function testDisabledFieldsAreStillResolvable(): void
    {
        $resolver = $this->resolver([]);

        $this->assertSame([], $resolver->identifierFieldsFor($this->article()));
        $this->assertContains('slug', $resolver->resolvableIdentifierFieldsFor($this->article()));
    }

    /** A field enabled for one entity stays understood everywhere. */
    public function testResolutionCoversPerEntityFieldsGlobally(): void
    {
        $resolver = $this->resolver(['slug'], [IdentifierParentFixture::class => ['username']]);

        $this->assertContains('username', $resolver->resolvableIdentifierFieldsFor($this->article()));
    }

    public function testConfiguredFieldsAreTriedFirstWhenResolving(): void
    {
        $resolver = $this->resolver(['uuid'], []);

        $this->assertSame('uuid', $resolver->resolvableIdentifierFieldsFor($this->article())[0]);
    }

    public function testResolvableFieldsAreNotDuplicated(): void
    {
        $fields = $this->resolver(['slug', 'uuid'], [IdentifierParentFixture::class => ['slug']])
            ->resolvableIdentifierFieldsFor($this->article());

        $this->assertSame(array_values(array_unique($fields)), $fields);
    }

    // -- lowercasing ------------------------------------------------------

    /**
     * Off by default because it is only SAFE where the lookup resolving the
     * URL back is case-insensitive; on a case-sensitive database a
     * lowercased link would 404.
     */
    public function testStoredCasingIsKeptByDefault(): void
    {
        $this->assertSame('Marki', $this->resolver(['username'])->entityIdentifier(new class {
            public function getId(): int { return 14; }
            public function getUsername(): string { return 'Marki'; }
        }));
    }

    public function testLowercasesWhenEnabled(): void
    {
        $this->assertSame('marki', $this->resolver(['username'], [], true)->entityIdentifier(new class {
            public function getId(): int { return 14; }
            public function getUsername(): string { return 'Marki'; }
        }));
    }

    /** A numeric id is not a string to fold - it must survive as an int. */
    public function testLowercasingLeavesTheIdFallbackAlone(): void
    {
        $this->assertSame(14, $this->resolver(['username'], [], true)->entityIdentifier(new class {
            public function getId(): int { return 14; }
        }));
    }

    /** mb_, not strtolower: a username may be non-latin (or accented). */
    public function testLowercasingIsMultibyteAware(): void
    {
        $this->assertSame('éloïse', $this->resolver(['username'], [], true)->entityIdentifier(new class {
            public function getId(): int { return 14; }
            public function getUsername(): string { return 'Éloïse'; }
        }));
    }

    // -- value guards -----------------------------------------------------

    /**
     * A blank candidate would generate "/admin/users/" - the collection,
     * not the record.
     */
    public function testIgnoresBlankAndWhitespaceOnlyCandidates(): void
    {
        $this->assertSame(14, $this->resolver(['slug', 'username'])->entityIdentifier(new class {
            public function getId(): int { return 14; }
            public function getSlug(): string { return '   '; }
            public function getUsername(): ?string { return null; }
        }));
    }

    /**
     * The collision this guards: resolution reads a numeric segment as a
     * primary key FIRST, so linking user "12345" by name would land on
     * whoever holds id 12345. The username validator allows digits-only,
     * so this is reachable, not hypothetical.
     */
    public function testRejectsAllDigitCandidatesAsAmbiguous(): void
    {
        $this->assertSame(14, $this->resolver(['username'])->entityIdentifier(new class {
            public function getId(): int { return 14; }
            public function getUsername(): string { return '12345'; }
        }));
    }

    public function testDigitsMixedWithOtherCharactersAreStillUsable(): void
    {
        $this->assertSame('user42', $this->resolver(['username'])->entityIdentifier(new class {
            public function getId(): int { return 14; }
            public function getUsername(): string { return 'user42'; }
        }));
    }

    public function testCandidateIsTrimmed(): void
    {
        $this->assertSame('marki', $this->resolver(['username'])->entityIdentifier(new class {
            public function getId(): int { return 14; }
            public function getUsername(): string { return "  marki\n"; }
        }));
    }

    public function testReturnsNullForAnObjectWithNoIdentifierAtAll(): void
    {
        $this->assertNull($this->resolver()->entityIdentifier(new \stdClass()));
    }

    public function testDefaultFieldsAreOrderedMostReadableFirst(): void
    {
        $this->assertSame(['slug', 'uuid'], FieldValueResolver::DEFAULT_IDENTIFIER_FIELDS);
    }

    // -- a field that is not a column -------------------------------------

    /**
     * Doctrine as the resolver sees it: IdentifierParentFixture and its
     * child are entities with the given columns, nothing else is managed.
     *
     * @param string[] $columns
     */
    private function doctrine(array $columns): ManagerRegistry
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturnCallback(fn (string $field) => in_array($field, $columns, true));

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturnCallback(
            fn (string $class) => is_a($class, IdentifierParentFixture::class, true) ? $manager : null
        );

        return $doctrine;
    }

    /**
     * getSlug() computed from the title, no slug column (a scholar's
     * Publication): the address made of it could not be looked up again -
     * /admin/publications/2/edit redirected to a slug that answered 404.
     */
    public function testAComputedSlugIsNotAnIdentifier(): void
    {
        $resolver = new FieldValueResolver(null, null, [], false, $this->doctrine(['id', 'title']));

        $this->assertSame(14, $resolver->entityIdentifier(new IdentifierComputedSlugFixture()));
    }

    public function testAStoredSlugStillIs(): void
    {
        $resolver = new FieldValueResolver(null, null, [], false, $this->doctrine(['id', 'title', 'slug']));

        $this->assertSame('acid-sensitive-photoswitches', $resolver->entityIdentifier(new IdentifierComputedSlugFixture()));
    }

    public function testTheNextStoredFieldIsTakenInstead(): void
    {
        $resolver = new FieldValueResolver(null, ['slug', 'username'], [], false, $this->doctrine(['id', 'username']));

        $this->assertSame('marki', $resolver->entityIdentifier(new IdentifierComputedSlugFixture()));
    }

    public function testAnObjectDoctrineDoesNotManageKeepsItsAccessors(): void
    {
        $resolver = new FieldValueResolver(null, null, [], false, $this->doctrine([]));

        $this->assertSame('my-article', $resolver->entityIdentifier($this->article()));
    }
}

class IdentifierComputedSlugFixture extends IdentifierParentFixture
{
    public function getSlug(): string { return 'acid-sensitive-photoswitches'; }
}

class IdentifierParentFixture
{
    public function getId(): int { return 14; }
    public function getUsername(): string { return 'marki'; }
}

class IdentifierChildFixture extends IdentifierParentFixture
{
}
