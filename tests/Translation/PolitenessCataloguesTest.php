<?php

namespace Tests\Base\Translation;

use Base\Service\Translator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * omnibase's own catalogues, read as files: a variant of politeness
 * ("key._polite"...) stands beside the text it is a variant of, takes the
 * same parameters, and - in French - a polite one no longer says "tu", nor
 * does any text of the core that has no polite variant.
 */
class PolitenessCataloguesTest extends TestCase
{
    /** French words that address somebody as "tu" (the apostrophes straightened before they are looked for). */
    private const TU = '/(?<![\p{L}\p{N}_-])(tu|te|t\'|ton|ta|tes|toi)(?![\p{L}\p{N}_-])/iu';

    /** @return array<string, array<string, string>> file name => flattened key => text */
    private function catalogues(string $language): array
    {
        $catalogues = [];
        foreach (glob(\dirname(__DIR__, 2).'/translations/*.'.$language.'.yaml') as $file) {
            $flat = [];
            $walk = function (array $node, string $prefix) use (&$walk, &$flat): void {
                foreach ($node as $key => $value) {
                    \is_array($value) ? $walk($value, $prefix.$key.'.') : $flat[$prefix.$key] = (string) $value;
                }
            };
            $walk(Yaml::parseFile($file) ?? [], '');
            $catalogues[basename($file)] = $flat;
        }

        return $catalogues;
    }

    /** @return list<string> the parameters of an ICU text: {0}, {name}, {count, plural, ...} */
    private function parameters(string $text): array
    {
        preg_match_all('/\{\s*([A-Za-z0-9_]+)\s*[,}]/', $text, $found);
        $names = array_unique($found[1]);
        sort($names);

        return $names;
    }

    private function level(string $key): ?string
    {
        $last = substr((string) strrchr('.'.$key, '.'), 1);

        return \in_array($last, Translator::POLITENESS_LEVELS, true) ? $last : null;
    }

    public function testAVariantStandsBesideItsTextAndTakesItsParameters(): void
    {
        $variants = 0;
        foreach (['fr', 'en'] as $language) {
            foreach ($this->catalogues($language) as $file => $texts) {
                foreach ($texts as $key => $text) {
                    if (null === $level = $this->level($key)) {
                        continue;
                    }
                    ++$variants;
                    $base = substr($key, 0, -\strlen('.'.$level));
                    $this->assertArrayHasKey($base, $texts, "$file: \"$key\" is a variant of a text that is not there");
                    $this->assertSame($this->parameters($texts[$base]), $this->parameters($text), "$file: \"$key\" does not take the parameters of its text");
                    $this->assertNotSame($texts[$base], $text, "$file: \"$key\" says what its text already says");
                }
            }
        }

        $this->assertGreaterThan(0, $variants, 'omnibase writes its polite French');
    }

    public function testInFrenchAPoliteVariantNoLongerSaysTuAndNoTextSaysItWithoutOne(): void
    {
        $left = [];
        foreach ($this->catalogues('fr') as $file => $texts) {
            foreach ($texts as $key => $text) {
                $says = preg_match(self::TU, str_replace('’', '\'', strip_tags($text)));
                if (Translator::POLITENESS_POLITE === $this->level($key)) {
                    $this->assertSame(0, $says, "$file: \"$key\" still says tu: $text");
                    $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])(je|j\')(?![\p{L}])/iu', str_replace('’', '\'', $text), "$file: \"$key\" speaks in the first person");
                } elseif (null === $this->level($key) && $says && !isset($texts[$key.'.'.Translator::POLITENESS_POLITE])) {
                    $left[] = "$file: $key";
                }
            }
        }

        $this->assertSame([], $left, 'French texts that say "tu" and have no "._polite" variant');
    }
}
