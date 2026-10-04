<?php

namespace Tests\Base\Database\Entity;

use Base\Entity\Layout\Setting;
use Base\Service\Localizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TranslatableTrait::translate(): a read in a language creates nothing, the
 * page's translation falls back on the default language, and a write in a
 * new language is still persisted.
 *
 * A read used to add an empty translation for the language asked about, and
 * a later translate() on a page in that language found it: the default
 * language was lost (omnibase/marketplace's Product::getAttributeValue()
 * worked around it, "resolve(null) is the page's language again, just made
 * empty").
 *
 * Driven on omnibase's Setting (its SettingIntl holds a value), in the host
 * application's kernel and database (the harness: a fresh SQLite one).
 */
class TranslatableReadTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $default;

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->default = Localizer::normalizeLocale(Localizer::getDefaultLocale());
        $this->pageIn($this->default);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->pageIn($this->default);
            $this->em->clear();
            $settings = $this->em->createQuery('SELECT s FROM '.Setting::class." s WHERE s.path LIKE 'test.translatable.%'")->getResult();
            foreach ($settings as $setting) {
                $this->em->remove($setting); // its translations go with it (cascade)
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function pageIn(string $locale): void
    {
        static::getContainer()->get('translator')->setLocale(str_replace('-', '_', $locale));
    }

    private function other(): string
    {
        // A locale other than the default one: Japanese, or French if Japanese is the default.
        return str_starts_with($this->default, 'ja') ? 'fr-FR' : 'ja-JP';
    }

    private function setting(string $value): Setting
    {
        return new Setting('test.translatable.'.bin2hex(random_bytes(4)), $value, $this->default);
    }

    public function testAReadInAnotherLanguageCreatesNoTranslation(): void
    {
        $setting = $this->setting('Margaux');
        $this->assertCount(1, $setting->getTranslations());

        $read = $setting->translate($this->other());

        $this->assertNull($read->getValueRaw(), 'nothing is written in that language');
        $this->assertSame($this->other(), $read->getLocale());
        $this->assertCount(1, $setting->getTranslations(), 'a read adds no translation');
        $this->assertFalse($setting->getTranslations()->containsKey($this->other()));
        // The same one is handed out again, so a read then a write go together.
        $this->assertSame($read, $setting->translate($this->other()));
    }

    public function testThePageTranslationFallsBackOnTheDefaultLanguageAfterARead(): void
    {
        $setting = $this->setting('Margaux');

        $setting->translate($this->other()); // a read, as resolve('ja') does
        $this->pageIn($this->other());

        $this->assertSame('Margaux', $setting->translate()->getValueRaw());
    }

    public function testTheDefaultLanguageIsPreferredToAnyOther(): void
    {
        $setting = $this->setting('Défaut');
        $third = str_starts_with($this->default, 'de') ? 'it-IT' : 'de-DE';
        // Another language written first in the collection's order, the default one after.
        $setting->clearTranslations();
        $setting->translate($third)->setValue('Anders');
        $setting->translate($this->default)->setValue('Défaut');

        $this->pageIn($this->other());
        $this->assertSame('Défaut', $setting->translate()->getValueRaw());
    }

    public function testTheSameLanguageInAnotherRegionComesFirst(): void
    {
        $setting = $this->setting('Défaut');
        $setting->translate('pt-BR')->setValue('Olá');

        $this->pageIn('pt-PT');
        $this->assertSame('Olá', $setting->translate()->getValueRaw());
    }

    public function testAWriteInANewLanguageIsCommittedAndPersisted(): void
    {
        $setting = $this->setting('Margaux');
        $this->em->persist($setting);
        $this->em->flush();
        $id = $setting->getId();

        // On a managed entity: written, never read back through getTranslations() before the flush.
        $setting->translate($this->other())->setValue('マルゴー');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Setting::class, $id);
        $this->assertSame('マルゴー', $reloaded->translate($this->other())->getValueRaw());
        $this->assertSame('Margaux', $reloaded->translate($this->default)->getValueRaw());
        $this->assertCount(2, $reloaded->getTranslations());
    }

    public function testAReadOnlyLanguageIsNeverPersisted(): void
    {
        $setting = $this->setting('Margaux');
        $setting->translate($this->other()); // read only
        $this->em->persist($setting);
        $this->em->flush();
        $id = $setting->getId();
        $this->em->clear();

        $reloaded = $this->em->find(Setting::class, $id);
        $this->assertCount(1, $reloaded->getTranslations());
        $this->assertTrue($reloaded->getTranslations()->containsKey($this->default));
    }
}
