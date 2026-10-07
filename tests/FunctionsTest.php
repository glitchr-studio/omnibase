<?php

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * Covers pure helpers from src/Resources/Functions.php (composer "files" autoload).
 */
class FunctionsTest extends TestCase
{
    /** A thread published without an excerpt nor a text: its e-mail shortens nothing, without a PHP deprecation. */
    public function testStrShortenTakesATextThatIsNotThere(): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });
        try {
            $this->assertSame('', str_shorten(null));
            $this->assertSame('Bonjour', str_shorten('<b>Bonjour</b>'));
        } finally {
            restore_error_handler();
        }
    }

    public function testSign(): void
    {
        $this->assertSame('+', sign(0));
        $this->assertSame('+', sign(42.5));
        $this->assertSame('-', sign(-1));
    }

    public function testIsHex(): void
    {
        $this->assertTrue(is_hex('deadBEEF'));
        $this->assertTrue(is_hex('0xff00'));
        $this->assertFalse(is_hex('xyz'));
        $this->assertFalse(is_hex(''));
    }

    public function testStrtobool(): void
    {
        $this->assertTrue(strtobool(true));
        $this->assertTrue(strtobool('Enabled'));
        $this->assertTrue(strtobool('yes'));
        $this->assertTrue(strtobool('1'));
        $this->assertFalse(strtobool('off'));
        $this->assertFalse(strtobool('0'));
        $this->assertFalse(strtobool('anything-else'));
    }

    public function testFormatBytes(): void
    {
        $this->assertSame('0 B', format_bytes(0));
        $this->assertSame('1 KB', format_bytes(1024));
        $this->assertSame('1.5 KB', format_bytes(1536));
        $this->assertSame('1 MB', format_bytes(1024 ** 2));
        $this->assertSame('1 TB', format_bytes(1024 ** 4));
    }

    public function testFormatUuid(): void
    {
        $this->assertSame(
            '01234567-89ab-cdef-0123-456789abcdef',
            format_uuid('0123456789abcdef0123456789abcdef')
        );
        // Separators are stripped before matching.
        $this->assertSame(
            '01234567-89ab-cdef-0123-456789abcdef',
            format_uuid('01234567-89ab-cdef-0123-456789abcdef')
        );
        $this->assertFalse(format_uuid('not-a-uuid'));
        // Strict mode refuses surrounding garbage.
        $this->assertFalse(format_uuid('zz0123456789abcdef0123456789abcdefzz', true));
    }

    public function testShrinkhex(): void
    {
        $this->assertSame('#FFF', shrinkhex('#FFFFFF'));
        $this->assertSame('#ABC', shrinkhex('#AABBCC'));
        $this->assertSame('#ABC', shrinkhex('#AABBCCFF'));
        $this->assertSame('#123456', shrinkhex('#123456FF'));
        $this->assertSame('#A1B2C3', shrinkhex('#A1B2C3'));
        $this->assertNull(shrinkhex(null));
    }

    public function testExpandhex(): void
    {
        $this->assertSame('#AABBCC', expandhex('#ABC'));
        $this->assertSame('#AABBCCFF', expandhex('#ABC', true));
        $this->assertSame('#AABBCCDD', expandhex('#ABCD'));
        $this->assertSame('#AABBCC', expandhex('#AABBCC'));
        $this->assertNull(expandhex(null));
    }

    /**
     * Regression: usort_column()'s $array parameter used to be by-value, so
     * its internal usort($array, ...) call sorted a local copy that was
     * discarded the moment the function returned — the caller's array was
     * silently left untouched (usort_column() always returned true, having
     * done nothing observable). Found via typesense-bundle's
     * Response::getFacetCounts(), whose sortByName option relied on this to
     * actually reorder facet counts; also left Aco::sortByColor() in this
     * bundle completely non-functional.
     */
    public function testUsortColumnSortsTheCallersArrayInPlace(): void
    {
        $rows = [['value' => 'b'], ['value' => 'a'], ['value' => 'c']];

        $result = usort_column($rows, 'value', fn($a, $b) => strcmp($a, $b));

        $this->assertTrue($result);
        $this->assertSame(['a', 'b', 'c'], array_column($rows, 'value'));
    }

    // ── Regressions for bugs found in a 2026-07-08 audit of this file ──
    // Each of these was a confirmed defect, verified against actual behavior
    // before the fix.

    /** while(array_pop($a)){} stopped at the first falsy element. */
    public function testArrayClearEmptiesEvenWithFalsyElements(): void
    {
        $a = [3, 0, 5, '', null];
        array_clear($a);
        $this->assertSame([], $a);
    }

    /** ARRAY_FILTER_USE_BOTH callback is ($value,$key); params were bound backwards. */
    public function testArrayContainsMatchesKeysByDefaultAndValuesOnDemand(): void
    {
        $this->assertSame(['foo' => 'bar'], array_contains(['foo' => 'bar'], 'foo'));
        $this->assertSame([], array_contains(['foo' => 'bar'], 'bar'));
        $this->assertSame(['foo' => 'bar'], array_contains(['foo' => 'bar'], 'bar', ARRAY_USE_VALUES));
    }

    /** getimagesize() returns false on a non-image → array_key_exists(...,false) TypeError. */
    public function testIsRgbReturnsFalseForANonImageInsteadOfThrowing(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'notimg');
        file_put_contents($tmp, 'not an image');
        try {
            $this->assertFalse(is_rgb($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    /** Fragment was read from an undefined $query instead of $parse → always stripped. */
    public function testFormatUrlPreservesTheFragment(): void
    {
        $this->assertStringContainsString('#section', format_url('http://ex.com/p#section'));
    }

    /** Walked consecutive indices ($i/$i+1) instead of stepping by 2. */
    public function testMakePairStepsByTwo(): void
    {
        $this->assertSame(['a' => 'b', 'c' => 'd'], make_pair(['a', 'b', 'c', 'd']));
        $this->assertFalse(make_pair(['a', 'b', 'c']));
    }

    /** SHORTEN_FRONT started the substring at end-of-string ($nChr) → "". */
    public function testStrShortenFrontKeepsTheTail(): void
    {
        $out = str_shorten('Lorem ipsum dolor sit amet', 10, SHORTEN_FRONT);
        $this->assertStringContainsString('amet', $out);
    }

    /** array_unique on a list-of-arrays (SORT_STRING) collapsed everything to one. */
    public function testGetPermutationsWithoutDuplicatesKeepsDistinctTuples(): void
    {
        $perms = get_permutations([1, 2], false);
        $this->assertCount(4, $perms);
        $this->assertContainsEquals([1, 2], $perms);
        $this->assertContainsEquals([2, 1], $perms);
    }

    /** $array was by value → the splice was discarded. */
    public function testArrayOccurrenceRemovesMutatesTheCaller(): void
    {
        $a = [1, 2, 2, 3];
        array_occurrence_removes($a, 2);
        $this->assertSame([1, 2, 3], array_values($a));
    }

    /** str_replace's 4th arg is an output count, not a limit → replaced all. */
    public function testStrReplacePrefixAndSuffixReplaceOnlyTheEnds(): void
    {
        $this->assertSame('Xan', str_replace_prefix('an', 'X', 'anan'));
        $this->assertSame('anX', str_replace_suffix('an', 'X', 'anan'));
        $this->assertSame('anan', str_replace_prefix('zz', 'X', 'anan'));
    }

    /** by-value → the usort sorted a discarded copy. */
    public function testUsortEndsWithReordersTheCaller(): void
    {
        $a = ['xa', 'xb', 'ya'];
        usort_endsWith($a, 'a');
        // Elements NOT ending in 'a' sort before those that do.
        $this->assertSame('xb', $a[0]);
    }

    /**
     * Same by-value discarded-sort bug (its one caller, TranslatableWalker,
     * relied on the no-op; verified via SQL diff that activating the sort
     * leaves real translated-query SQL byte-identical, since Doctrine already
     * emits statements in the FROM/INNER/LEFT priority order this produces).
     * The multi-needle order defines the priority: FROM first, then INNER JOIN,
     * then LEFT JOIN.
     */
    public function testUsortStartsWithReordersTheCallerByNeedlePriority(): void
    {
        $a = [' LEFT JOIN a', ' FROM x', ' INNER JOIN b'];
        usort_startsWith($a, [' FROM', ' INNER JOIN', ' LEFT JOIN']);

        $this->assertSame([' FROM x', ' INNER JOIN b', ' LEFT JOIN a'], $a);
    }

    /** cast_datetime(null) is null; ->setTime()/->setDate() on the null defaults fataled. */
    public function testDateAndTimeIsBetweenDoNotFatalOnDefaultBounds(): void
    {
        $this->assertFalse(date_is_between('2026-01-01'));
        $this->assertFalse(time_is_between('12:00:00'));
        $this->assertIsBool(time_is_between('07:00:00', '05:00:00', '10:00:00'));
    }

    /** ($alpha * 0xFF) concatenated a raw decimal instead of a hex byte. */
    public function testAlpha2hexProducesATwoDigitHexByte(): void
    {
        $this->assertSame('#FF', alpha2hex(1.0));
        $this->assertSame('#80', alpha2hex(0.5));
        $this->assertSame('#00', alpha2hex(0.0));
        $this->assertSame('FF', alpha2hex(1.0, false));
    }

    // ── Second audit batch: "risky" helpers with real callers, verified
    // against the live app before fixing (see base-bundle-functions-audit). ──

    /**
     * The class-name match required BOTH bounds to be non-empty
     * (($ends && …) && ($starts && …)), so a prefix-only or suffix-only call
     * could never match. Passing both (the only real caller) is unchanged.
     */
    public function testCheckBacktraceHonoursEitherBoundIndividually(): void
    {
        $bt = [['class' => 'Doctrine\\ORM\\UnitOfWork'], ['class' => 'App\\Foo']];

        $this->assertTrue(check_backtrace('Doctrine', 'UnitOfWork', $bt));
        $this->assertTrue(check_backtrace('Doctrine', '', $bt));
        $this->assertTrue(check_backtrace('', 'UnitOfWork', $bt));
        $this->assertFalse(check_backtrace('Nope', '', $bt));
        $this->assertFalse(check_backtrace('', '', $bt));
    }

    /**
     * A sub-array that is itself an identity used to fall through to
     * `elseif ($key !== $value)` (int key !== array), so any array containing
     * a sub-array was never an identity. Flat arrays (the real caller) are
     * unaffected.
     */
    public function testIsIdentityRecursesIntoSubArrays(): void
    {
        $this->assertTrue(is_identity([0, 1, 2, 3]));
        $this->assertFalse(is_identity([0 => 0, 1 => 5]));
        $this->assertTrue(is_identity([0 => [0 => 0], 1 => 1]));
        $this->assertFalse(is_identity([0 => [0 => 9], 1 => 1]));
    }

    /** Declared `: int` but returned a string (TypeError); now the inverse of abc2dec. */
    public function testDec2abcIsTheInverseOfAbc2dec(): void
    {
        $this->assertIsString(dec2abc(53));
        $this->assertSame(53, abc2dec(dec2abc(53)));
        $this->assertSame(5, abc2dec(dec2abc(5)));
    }

    /**
     * Swapped str_starts_with args asked whether the NEEDLE started with the
     * key instead of the key with the needle, so e.g. "filters[state]" was
     * never stripped by the prefix "filters[".
     */
    public function testArrayKeyRemovesStartsWithStripsKeysByPrefix(): void
    {
        $q = ['filters[state]' => 'x', 'page' => '2', 'sort[name]' => 'asc', 'destination' => 'paris'];

        $this->assertSame(
            ['destination' => 'paris'],
            array_key_removes_startsWith($q, true, 'filters[', 'page', 'sort[')
        );
    }

    public function testArrayKeyRemovesEndsWithStripsKeysBySuffix(): void
    {
        $this->assertSame(
            ['name' => 2],
            array_key_removes_endsWith(['a_id' => 1, 'name' => 2], true, '_id')
        );
    }

    /**
     * The $scheme argument was unconditionally overwritten by $_SERVER; a
     * caller passing "http"/"https" (AdvancedUrlGenerator, RouterSubscriber)
     * had no effect. No-arg still derives the scheme from $_SERVER.
     */
    public function testGetUrlHonoursAnExplicitScheme(): void
    {
        $serverBackup = $_SERVER;
        try {
            $_SERVER['HTTP_HOST'] = 'example.com';
            $_SERVER['REQUEST_URI'] = '/x';
            $_SERVER['HTTPS'] = 'on'; // request is https
            unset($_SERVER['USE_HTTPS'], $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

            $this->assertStringStartsWith('https://', get_url());            // derived
            $this->assertStringStartsWith('http://', get_url('http'));       // explicit overrides $_SERVER
            $this->assertStringStartsWith('https://', get_url('https'));
            $this->assertStringStartsWith('https://', get_url('on'));        // normalised
        } finally {
            $_SERVER = $serverBackup;
        }
    }

    /**
     * The old body required "{"…"}" (JSON-object shape) so it rejected ALL real
     * serialize() output; Vault::reveal() and AssociationType gate unserialize()
     * on it. Now it detects real serialize() strings — and the detection itself
     * never instantiates an object (allowed_classes: false), so it carries no
     * object-injection side effect.
     */
    public function testIsSerializedDetectsRealSerializeOutput(): void
    {
        $this->assertTrue(is_serialized(serialize([1, 2, 3])));
        $this->assertTrue(is_serialized(serialize('hello')));
        $this->assertTrue(is_serialized(serialize(42)));
        $this->assertTrue(is_serialized('N;'));      // null
        $this->assertTrue(is_serialized('b:0;'));    // false
        $this->assertTrue(is_serialized(serialize(true)));

        $this->assertFalse(is_serialized('randomsecret123'));
        $this->assertFalse(is_serialized(''));
        $this->assertFalse(is_serialized('{"a":1}'));
        $this->assertFalse(is_serialized(42));
    }

    public function testIsSerializedDoesNotInstantiateAnObjectWhileDetecting(): void
    {
        $serialized = serialize(new FunctionsTestNoInstantiateProbe());
        FunctionsTestNoInstantiateProbe::$woken = false;

        $this->assertTrue(is_serialized($serialized));
        $this->assertFalse(
            FunctionsTestNoInstantiateProbe::$woken,
            'is_serialized() must not run __wakeup / instantiate the class while checking.'
        );
    }
}

class FunctionsTestNoInstantiateProbe
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
