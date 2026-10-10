<?php

namespace Base\Service;

use Base\Database\Type\SetType;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Translation\Translator as SymfonyTranslator;

class Translator implements TranslatorInterface
{
    public const PARSE_EXTENDS = "extends";
    public const PARSE_NAMESPACE = "namespace";

    public const DOMAIN_DEFAULT = "messages";
    public const DOMAIN_BACKEND = "admin";
    public const DOMAIN_ENTITY = "entities";
    public const DOMAIN_ENUM = "enums";

    // A dotted key, its @domain first when it has one: segments of letters, digits and
    // underscores, a hyphen within one (registers.national-archives-uk.name) - never at
    // its edges, never an @ further on (an e-mail address is no key).
    public const STRUCTURE_DOT = "^@?[a-zA-Z0-9_]+(?:-[a-zA-Z0-9_]+)*(?:[.][a-zA-Z0-9_]+(?:-[a-zA-Z0-9_]+)*)+$";
    public const STRUCTURE_DOTBRACKET = "\{[ ]*[@a-zA-Z0-9_.]+[.]{0,1}[a-zA-Z0-9_]+[ ]*\}";
    public const STRUCTURE_BRACKETLIST = ['{}', "[]", "%%"];

    public const TRANSLATION_PROPERTIES = "_properties";

    public const TRANSLATION_NOUN = "noun";
    public const NOUN_SINGULAR = "_singular";
    public const NOUN_PLURAL = "_plural";

    public const TRANSLATION_GENDERNESS = "genderness";
    public const GENDERNESS_INCLUSIVE = "_inclusive";
    public const GENDERNESS_FEMININE = "_feminine";
    public const GENDERNESS_MASCULINE = "_masculine";
    public const GENDERNESS_NEUTRAL = "_neutral";

    /**
     * How somebody is addressed: familiar (tu, du, 常体), polite (vous, Sie,
     * 丁寧語), formal (敬語). A text has one wording in the catalogue - its
     * base text, at whatever level its language was written in - and may
     * have a variant per level, under its key followed by the level:
     * "key._plain", "key._polite", "key._formal".
     *
     * The level is the site's (base.translator.politeness) unless a call
     * gives its own - the TRANSLATION_POLITENESS parameter of trans(), or
     * one of these among the options of transEntity() / transEnum(). What is
     * tried, in order: formal -> polite -> the base text; polite -> the base
     * text; plain -> the base text. See docs/20-architecture/politeness.md.
     */
    public const TRANSLATION_POLITENESS = "politeness";
    public const POLITENESS_PLAIN = "_plain";
    public const POLITENESS_POLITE = "_polite";
    public const POLITENESS_FORMAL = "_formal";
    public const POLITENESS_LEVELS = [self::POLITENESS_PLAIN, self::POLITENESS_POLITE, self::POLITENESS_FORMAL];

    // Guards the "nested translations" while loops against a cyclic
    // catalogue reference (e.g. "foo" => "@bar", "bar" => "@foo") hanging a
    // request — translation content is often editable without code review.
    public const MAX_NESTED_TRANSLATION_DEPTH = 10;

    /**
     * @var ParameterBag
     */
    protected $parameterBag;

    /**
     * @var SymfonyTranslator
     */
    protected $translator;

    /**
     * @var bool
     */
    protected bool $isDebug;

    /** The site's level (one of POLITENESS_*), or null: the texts as they are written. */
    protected ?string $politeness = null;

    /** @var array{0: ?string}|null the level a call gave for itself, while that call is answered */
    private ?array $politenessOfCall = null;

    /** Whether the translator below knows the texts rewritten in the back office (Base\Translation\OverridingTranslator). */
    private bool $asksRewritten = true;

    /**
     * @param array<string, array<string, list<string>>> $applicationTexts the keys the application wrote in its own
     *                                                                     translations/ directory, by domain and locale
     *                                                                     (ApplicationTextsPass; read only when a level is set)
     */
    public function __construct(\Symfony\Contracts\Translation\TranslatorInterface $translator, KernelInterface $kernel, ParameterBagInterface $parameterBag, ?string $politeness = null, array $applicationTexts = [])
    {
        $this->parameterBag = $parameterBag;
        $this->translator = $translator;
        $this->isDebug = $kernel->isDebug();
        $this->politeness = self::politenessLevel($politeness);
        $this->applicationTexts = $applicationTexts;
    }

    /** @var array<string, array<string, list<string>>> */
    private array $applicationTexts = [];

    /** @var array<string, array<string, true>> "domain|locale" => the application's keys there, as a set */
    private array $applicationKeys = [];

    /** The keys the application wrote itself in this domain and locale. */
    private function applicationKeys(string $domain, string $locale): array
    {
        return $this->applicationKeys[$domain . "|" . $locale] ??= array_fill_keys($this->applicationTexts[$domain][$locale] ?? [], true);
    }

    /** The site's level of politeness: one of POLITENESS_*, or null when none is set. */
    public function getPoliteness(): ?string
    {
        return $this->politeness;
    }

    /**
     * Another level from here on - a message written to somebody the site
     * addresses otherwise, a test. "plain", "polite", "formal" (or a
     * POLITENESS_* constant); null for the texts as they are written.
     *
     * @return $this
     */
    public function setPoliteness(?string $level)
    {
        $this->politeness = self::politenessLevel($level);

        return $this;
    }

    /**
     * A level as it may be written - "polite", "_polite", POLITENESS_POLITE -
     * as one of POLITENESS_*; null for none (null, false, "", "none").
     *
     * @throws \InvalidArgumentException for anything else
     */
    public static function politenessLevel(mixed $level): ?string
    {
        if (null === $level || false === $level || "" === $level || "none" === $level) {
            return null;
        }

        $name = \is_string($level) ? "_" . ltrim(mb_strtolower(trim($level)), "_") : null;
        if (!\in_array($name, self::POLITENESS_LEVELS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown level of politeness "%s": expected "plain", "polite" or "formal".', get_debug_type($level) === "string" ? $level : get_debug_type($level)));
        }

        return $name;
    }

    /**
     * The variants tried for a level, the closest first: a formal text that
     * was not written falls back on the polite one.
     *
     * @return list<string>
     */
    public static function politenessChain(?string $level): array
    {
        return match ($level) {
            self::POLITENESS_FORMAL => [self::POLITENESS_FORMAL, self::POLITENESS_POLITE],
            self::POLITENESS_POLITE => [self::POLITENESS_POLITE],
            self::POLITENESS_PLAIN => [self::POLITENESS_PLAIN],
            default => [],
        };
    }

    /**
     * The key of the variant to read in place of $id at this level, with its
     * domain - or null: the base text is the one.
     *
     * Language first, level second: the catalogues are walked as Symfony
     * falls back between them, and in each the variants are looked for before
     * the base text - a German page whose text has no polite variant keeps
     * its German text, it is not given the French polite one.
     *
     * Whose text it is comes next. A text the team rewrote in the back
     * office, at its base key, wins over a variant of the files. So does a
     * text the application wrote in its own catalogue over a bundle's
     * variant: an application that replaced a bundle's "Tu n'as aucun
     * message" by its own "Aucun message." keeps its sentence when the
     * bundle brings a "._polite" of the one it replaced. Either may have a
     * variant of its own, which is then the one.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function politeVariant(string $id, ?string $domain, ?string $locale, string $level): ?array
    {
        $array = explode(".", $id);
        if (str_starts_with($id, "@")) {
            $domain = substr(array_shift($array), 1);
            $id = implode(".", $array);
        }
        $domain = $domain && str_starts_with($domain, "@") ? substr($domain, 1) : ($domain ?? self::DOMAIN_DEFAULT);
        if ("" === $id || \in_array(end($array), self::POLITENESS_LEVELS, true)) {
            return null; // already a variant, asked for by its own key
        }

        $chain = self::politenessChain($level);
        $catalogue = $this->translator->getCatalogue(Localizer::__toLocale($locale ?? $this->getLocale(), "_"));
        for ($depth = 0; $catalogue && $depth < 8; $catalogue = $catalogue->getFallbackCatalogue(), ++$depth) {
            foreach ($chain as $variant) {
                if ($this->isRewritten($id . "." . $variant, $domain, $catalogue->getLocale())) {
                    return [$id . "." . $variant, $domain];
                }
            }
            if ($this->isRewritten($id, $domain, $catalogue->getLocale())) {
                return null;
            }
            $own = $this->applicationKeys($domain, $catalogue->getLocale());
            foreach ($chain as $variant) {
                if (isset($own[$id . "." . $variant])) {
                    return [$id . "." . $variant, $domain];
                }
            }
            if (isset($own[$id])) {
                return null;
            }
            foreach ($chain as $variant) {
                if ($catalogue->defines($id . "." . $variant, $domain)) {
                    return [$id . "." . $variant, $domain];
                }
            }
            if ($catalogue->defines($id, $domain)) {
                return null;
            }
        }

        return null;
    }

    private function isRewritten(string $id, string $domain, string $locale): bool
    {
        if (!$this->asksRewritten) {
            return false;
        }

        try {
            return (bool) $this->translator->isRewritten($id, $domain, $locale);
        } catch (\Error) {
            // No translator below keeps rewritten texts (a bare Symfony translator).
            return $this->asksRewritten = false;
        }
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->translator->getCatalogue($locale ?? $this->getLocale());
    }

    public function getCatalogues(): array
    {
        return $this->translator->getCatalogues();
    }

    public function getLocale(): string
    {
        return $this->translator->getLocale();
    }

    /**
     * @param string $locale
     * @return $this
     */
    public function setLocale(string $locale)
    {
        $this->translator->setLocale($locale);
        return $this;
    }

    public function getFallbackLocales(): array
    {
        return $this->translator->getFallbackLocales();
    }

    public function transQuiet(TranslatableMessage|string $id, array $parameters = array(), ?string $domain = null, ?string $locale = null, bool $recursive = true, bool $nullable = true): ?string
    {
        $id = $this->transExists($id, $domain, $locale) ? $this->trans($id, $parameters, $domain, $locale, $recursive) : ($nullable ? null : $id);
        $id = $id instanceof TranslatableMessage ? $id->getMessage() : $id;
        return $id;
    }

    public function trans(TranslatableMessage|string $id, array $parameters = array(), ?string $domain = null, ?string $locale = null, bool $recursive = true): string
    {
        if (!$id) {
            return $id;
        }

        $domainFallback = null;
        if ($id instanceof TranslatableMessage) {
            $domainFallback = $domain;
            $domain = $id->getDomain();
            $parameters = array_merge($id->getParameters(), $parameters);
            $id = $id->getMessage();
        }

        $id = trim($id instanceof TranslatableMessage ? $id->getMessage() : $id);

        // The level of politeness: this call's own when it gave one (it holds
        // for the texts this one refers to), else the site's. The text is
        // then read under its variant's key when the catalogue has one.
        if (\array_key_exists(self::TRANSLATION_POLITENESS, $parameters)) {
            $own = [self::politenessLevel($parameters[self::TRANSLATION_POLITENESS])];
            unset($parameters[self::TRANSLATION_POLITENESS]);

            $outer = $this->politenessOfCall;
            $this->politenessOfCall = $own;
            try {
                return $this->trans($domainFallback !== null ? new TranslatableMessage($id, [], $domain) : $id, $parameters, $domainFallback ?? $domain, $locale, $recursive);
            } finally {
                $this->politenessOfCall = $outer;
            }
        }

        $level = $this->politenessOfCall ? $this->politenessOfCall[0] : $this->politeness;
        if ($level !== null && ($variant = $this->politeVariant($id, $domain, $locale, $level))) {
            [$id, $domain] = $variant;
        }

        $customId = preg_match("/" . self::STRUCTURE_DOT . "|" . self::STRUCTURE_DOTBRACKET . "/", $id);
        $startsWithDomainTag = str_starts_with($id, "@");

        // transExists() always normalizes its locale to underscore form
        // before checking a catalogue. Every actual catalogue lookup below
        // must use that same normalized form — $locale itself is left alone
        // since it's also compared, as given, against Localizer::getDefaultLocale()
        // further down. Without this, a translation transExists() can reach
        // (it normalizes) but a raw Symfony trans() call can't (it doesn't)
        // could make the "nested translations" while loop spin forever:
        // transExists() keeps reporting the translation exists while $trans
        // never changes.
        $lookupLocale = $locale !== null ? Localizer::__toLocale($locale, "_") : null;

        $domain = $domain && str_starts_with($domain, "@") ? substr($domain, 1) : ($domain ?? null);
        $domainFallback = $domainFallback && str_starts_with($domainFallback, "@") ? substr($domainFallback, 1) : ($domainFallback ?? null);
        if ($id && $customId) {

            $array = explode(".", $id);
            if ($startsWithDomainTag) {
                $domain = substr(array_shift($array), 1);
                $id = implode(".", $array);
            }

        } elseif ($recursive) { // Check if recursive dot structure

            $count = 0;
            $fn = fn($k) => $this->trans($k, $parameters, $domain, $locale, false);
            $ret = preg_replace_callback("/" . self::STRUCTURE_DOT . "|" . self::STRUCTURE_DOTBRACKET . "/", $fn, $id, -1, $count);
            if ($ret != $id) {
                return $ret;
            }

            $ret = $this->translator->trans($ret, $parameters, $domain, $lookupLocale);
            if (preg_match("/^{[a-zA-Z0-9]*}$/", $ret)) {
                $ret = $this->translator->trans($ret, $parameters, $domainFallback, $lookupLocale);
                if (preg_match("/^{[a-zA-Z0-9]*}$/", $ret)) {
                    return $id;
                }
            }

            // Plain-text ids (a humanised field label such as "Published at")
            // take this early path and never reached the default-language
            // fallback at the end of the method - so a language without the
            // key kept the English text while dotted keys fell back to French.
            if ($ret === $id && ($lookupLocale ?? $this->getLocale()) != Localizer::__toLocale(Localizer::getDefaultLocale(), "_")) {
                $fallback = $this->translator->trans($id, $parameters, $domain, Localizer::__toLocale(Localizer::getDefaultLocale(), "_"));
                if ($fallback !== $id && !preg_match("/^{[a-zA-Z0-9]*}$/", $fallback)) {
                    return $fallback;
                }
            }

            return $ret;
        }

        // Replace parameter between brackets
        $bracketList = self::STRUCTURE_BRACKETLIST;
        foreach ($parameters as $key => $element) {
            $brackets = -1;
            if (is_numeric($key)) {
                $brackets = $bracketList[0];
            } elseif (is_string($key)) {
                $pos = in_array($key[0] . $key[strlen($key) - 1], $bracketList);
                if ($pos !== false) {
                    continue;
                } // already formatted
            }

            if (preg_match("/^[a-zA-Z0-9_.]+$/", $key) && $brackets < 0) {
                $brackets = begin($bracketList);
            }

            if ($brackets < 0) {
                continue;
            }
            $leftBracket = $brackets[0];
            $rightBracket = $brackets[1];

            $parameters[$leftBracket . trim($key, $leftBracket . $rightBracket . " ") . $rightBracket] = $element;

            unset($parameters[$key]);
        }

        // Call for translation with parameter bag variables
        $trans = $this->translator->trans($id, $parameters, $domain, $lookupLocale);
        if (preg_match_all("/%([^%]*)%/", $trans, $matches)) {
            foreach ($matches[1] ?? [] as $key) {
                if (($parameter = $this->parameterBag->get($key))) {
                    $parameters["%" . $key . "%"] = $parameter;
                }
            }
        }

        // Lookup for nested translations
        $depth = 0;
        while ($this->transExists($trans, $domain, $lookupLocale) && $recursive && $depth++ < self::MAX_NESTED_TRANSLATION_DEPTH) {
            $trans = $this->trans($trans, $parameters, $domain, $locale, false);
        }

        if ($trans == $id) {
            if ($domainFallback !== false) {
                $depth = 0;
                while ($this->transExists($trans, $domainFallback, $lookupLocale) && $recursive && $depth++ < self::MAX_NESTED_TRANSLATION_DEPTH) {
                    $trans = $this->trans($trans, $parameters, $domainFallback, $locale, false);
                }
            }

            // Fallback to the default language. This used to be production
            // only, on the theory that a raw key is a useful signal while
            // developing - but a back-office switched to a language whose
            // catalogues are partial (German has none for the admin) then
            // shows raw keys on every button and title, on beta, to the
            // people who review it there. debug:translation is the tool for
            // finding missing keys; the pages fall back like production does.
            if ($locale != Localizer::getDefaultLocale()) {
                if ($trans == $id) {
                    $trans = $this->transQuiet($id, $parameters, $domain, Localizer::getDefaultLocale());
                }
                if ($trans == $id && $domainFallback !== false) {
                    $trans = $this->transQuiet($id, $parameters, $domainFallback, Localizer::getDefaultLocale());
                }
            }
        }

        if ($trans == $id && $customId) {
            $trans = $domain && $startsWithDomainTag ? "@" . $domain . "." . $id : $id;
        }

        return trim($trans ?? "");
    }

    /**
     * @param $class
     * @param string $parseBy
     * @return string
     */
    public function parseClass($class, string $parseBy = self::PARSE_NAMESPACE): string
    {
        $class = is_object($class) ? get_class($class) : $class;
        switch ($parseBy) {
            case self::PARSE_EXTENDS:

                $parent = class_exists($class) ? get_parent_class($class) : null;

                $class = class_basename($class);
                if ($parent) {
                    $class .= "." . class_basename($parent);
                }
                while (class_exists($parent) && ($parent = get_parent_class($parent))) {
                    $class .= "." . class_basename($parent);
                }

                return camel2snake($class);

            default:
            case self::PARSE_NAMESPACE:

                $bundleEntityNamespaces = array_map(fn($b) => dirname_namespace($b)."\\Entity\\", \Base\BaseBundle::getInstance()->getBundles());
                $entityNamespaces = array_merge(["Proxies\\__CG__\\", "App\\Entity\\", "Base\\Entity\\"], $bundleEntityNamespaces);

                $entityPrefix = array_fill(0, 3, "");
                foreach($bundleEntityNamespaces as $namespace)
                {
                    $baseNamespace = explode("\\", $namespace)[1] ?? null;
                    if($baseNamespace) $entityPrefix[] = lcfirst($baseNamespace)."\\";
                }

                $class = str_replace($entityNamespaces, $entityPrefix, $class);

                return camel2snake(implode(".", array_unique(explode("\\", $class))));
        }

        return "";
    }

    public function transExists(TranslatableMessage|string $id, ?string $domain = null, ?string $locale = null, bool $localeCountry = true): bool
    {
        $locale ??= $this->getLocale();
        $catalogue = $this->translator->getCatalogue($localeCountry ? Localizer::__toLocale($locale, "_") : Localizer::__toLocaleLang($locale));
        if ($id instanceof TranslatableMessage) {
            $domain ??= $id->getDomain();
            $id = $id->getMessage();
        }

        $id = trim($id);
        $array = explode(".", $id);
        if (str_starts_with($id, "@")) {
            $domain = substr(array_shift($array), 1);
            $id = implode(".", $array);
        }

        $domain = $domain && str_starts_with($domain, "@") ? substr($domain, 1) : ($domain ?? null);
        return $catalogue->has($id, $domain ?? self::DOMAIN_DEFAULT);
    }

    /**
     * @param string $path
     * @return array|array[]
     */
    protected function parsePath(string $path)
    {
        $entries = array_starts_with(explode(".", $path), "_");
        return get_permutations(tail($entries, 3));
    }

    /**
     * The suffixes to try after a key for these options, the most precise
     * first: every ordering of the level of politeness, the gender and the
     * number asked; then, for a level of politeness the call gave, the levels
     * it falls back on (formal -> polite) and the same key without any; then
     * the bare key.
     *
     * @return array{0: list<string>, 1: list<string>, 2: bool} the suffixes, the parts asked, whether the call gave a level of its own
     */
    protected function permutations(array $options): array
    {
        $politeness = null;
        if (in_array(self::POLITENESS_PLAIN, $options)) {
            $politeness = self::POLITENESS_PLAIN;
        } elseif (in_array(self::POLITENESS_POLITE, $options)) {
            $politeness = self::POLITENESS_POLITE;
        } elseif (in_array(self::POLITENESS_FORMAL, $options)) {
            $politeness = self::POLITENESS_FORMAL;
        }

        $genderness = null;
        if (in_array(self::GENDERNESS_INCLUSIVE, $options)) {
            $genderness = self::GENDERNESS_INCLUSIVE;
        } elseif (in_array(self::GENDERNESS_MASCULINE, $options)) {
            $genderness = self::GENDERNESS_MASCULINE;
        } elseif (in_array(self::GENDERNESS_FEMININE, $options)) {
            $genderness = self::GENDERNESS_FEMININE;
        } elseif (in_array(self::GENDERNESS_NEUTRAL, $options)) {
            $genderness = self::GENDERNESS_NEUTRAL;
        }
        $genderness = $genderness ? "." . $genderness : "";

        $noun = null;
        if (in_array(self::NOUN_PLURAL, $options)) {
            $noun = self::NOUN_PLURAL;
        } elseif (in_array(self::NOUN_SINGULAR, $options)) {
            $noun = self::NOUN_SINGULAR;
        }
        $noun = $noun ? "." . $noun : "";

        $others = array_filter([$genderness, $noun]);
        $permutations = [];
        foreach (self::politenessChain($politeness) as $level) {
            foreach (get_permutations(array_merge(["." . $level], $others)) as $permutation) {
                $permutations[] = implode("", $permutation);
            }
        }
        foreach (get_permutations($others) as $permutation) {
            $permutations[] = implode("", $permutation);
        }
        $permutations[] = "";

        return [array_values(array_unique($permutations)), array_filter([$politeness ? "." . $politeness : "", $genderness, $noun]), $politeness !== null];
    }

    /**
     * @param string $id
     * @param array|string $options
     * @param array|null $parameters
     * @param string|null $domain
     * @param string|null $locale
     * @return array|false|string|string[]|null
     */
    protected function transPerms(string $id, array|string $options = [], ?array $parameters = [], ?string $domain = null, ?string $locale = null)
    {
        if (!is_array($options)) {
            $options = array_filter([$options]);
        }

        [$permutations, $in, $ownLevel] = $this->permutations($options);

        $trans = null;
        // A level the call gave is the one: the site's is not asked on top of it.
        $parameters = $ownLevel ? [self::TRANSLATION_POLITENESS => "none"] + ($parameters ?? []) : $parameters;
        foreach ($permutations as $permutation) {
            $trans = $this->transQuiet(mb_strtolower($id . $permutation), $parameters, $domain, $locale);
            if ($trans !== null) {
                break;
            }
        }

        if (!$trans && empty($options)) {
            throw new \LogicException("No translation found for \"@" . $domain . "." . $id . "\" and no permutation option provided");
        }

        return $trans ? mb_ucfirst($trans) : mb_strtolower($id . implode("", $in));
    }

    /**
     * @param string $id
     * @param array|string $options
     * @param string|null $domain
     * @param string|null $locale
     * @param bool $localeCountry
     * @return bool
     */
    protected function transPermExists(string $id, array|string $options = [], ?string $domain = null, ?string $locale = null, bool $localeCountry = true)
    {
        if (!is_array($options)) {
            $options = [$options];
        }

        [$permutations, $in, $ownLevel] = $this->permutations($options);

        $trans = null;
        foreach ($permutations as $permutation) {

            $trans = $this->transQuiet(mb_strtolower($id . $permutation), [], $domain, $locale, $localeCountry);
            if ($trans !== null) {
                return true;
            }
        }

        return false;
    }

    public function transRoute(string $routeName, ?string $domain = null): ?string
    {
        $domain = $domain ? $domain . "." : "@controllers.";
        return $this->trans($domain . $routeName . ".title");
    }

    public function transRouteExists(string $routeName, ?string $domain = null): bool
    {
        $domain = $domain ? $domain . "." : "@controllers.";
        return $this->transExists($domain . $routeName . ".title");
    }

    public function transEnum(?string $value, string $class, null|string|array $options = self::NOUN_SINGULAR): ?string
    {
        if (class_exists($class)) {
            $declaringClass = $class;
        } elseif (Type::hasType($class)) {
            $declaringClass = get_class(Type::getType($class));
        } else {
            return $value;
        }

        while ((count(array_filter($declaringClass::getPermittedValues(false), fn($c) => $c === $value)) == 0)) {
            $declaringClass = get_parent_class($declaringClass);
            if ($declaringClass === Type::class) {
                $declaringClass = $class;
                break;
            }
        }

        $value = $value ? "." . $value : "";
        $offset = is_subclass_of($class, SetType::class) ? -3 : -2;
        $class = $this->parseClass($declaringClass, self::PARSE_EXTENDS);
        $class = implode(".", array_slice(explode(".", $class), 0, $offset));

        return $class ? $this->transPerms($class . $value, $options, [], self::DOMAIN_ENUM) : null;
    }

    public function transEnumExists(string $value, string $class, string|array $options = self::NOUN_SINGULAR): bool
    {
        $declaringClass = $class;
        while ((count(array_filter($declaringClass::getPermittedValues(false), fn($c) => $c === $value)) == 0)) {
            $declaringClass = get_parent_class($declaringClass);
            if ($declaringClass === Type::class) {
                $declaringClass = $class;
                break;
            }
        }

        $value = $value ? "." . $value : "";
        $offset = is_subclass_of($class, SetType::class) ? -3 : -2;
        $class = $this->parseClass($declaringClass, self::PARSE_EXTENDS);
        $class = implode(".", array_slice(explode(".", $class), 0, $offset));

        return $class && $this->transPermExists($class . $value, $options, self::DOMAIN_ENUM);
    }

    public function transEntity(mixed $entityOrClassName, ?string $property = null, string|array $options = self::NOUN_SINGULAR): ?string
    {
        if (!is_array($options)) {
            $options = array_filter([$options]);
        }
        if (is_object($entityOrClassName)) {
            $entityOrClassName = get_class($entityOrClassName);
        }

        $entityOrClassName = $this->parseClass($entityOrClassName);
        $property = $property ? ".".self::TRANSLATION_PROPERTIES."." . camel2snake($property) : "";
        return $entityOrClassName ? $this->transPerms($entityOrClassName . $property, $options, [], self::DOMAIN_ENTITY) : null;
    }

    public function transEntityExists(mixed $entityOrClassName, ?string $property = null, string|array $options = self::NOUN_SINGULAR): bool
    {
        if (!is_array($options)) {
            $options = [$options];
        }
        if (is_object($entityOrClassName)) {
            $entityOrClassName = get_class($entityOrClassName);
        }

        $entityOrClassName = $this->parseClass($entityOrClassName);
        $property = $property ? ".".self::TRANSLATION_PROPERTIES."." . camel2snake($property) : "";
 
       return $this->transPermExists($entityOrClassName . $property, $options, self::DOMAIN_ENTITY);
    }

    public function transTime(int $time): string
    {
        if ($time > 0) {
            $seconds = fmod($time, 60);
            $time = intdiv($time, 60);
            $minutes = fmod($time, 60);
            $time = intdiv($time, 60);
            $hours = fmod($time, 24);
            $time = intdiv($time, 24);
            $days = fmod($time, 30);
            $time = intdiv($time, 30);
            $months = fmod($time, 12);
            $years = intdiv($time, 12);

            $str =
                ($years ?: "") . " " . $this->trans("base.years", [$years]) . " " .
                ($months ?: "") . " " . $this->trans("base.months", [$months]) . " " .
                ($days ?: "") . " " . $this->trans("base.days", [$days]) . " " .
                ($hours ?: "") . " " . $this->trans("base.hours", [$hours]) . " " .
                ($minutes ?: "") . " " . $this->trans("base.minutes", [$minutes]) . " " .
                ($seconds ?: "") . " " . $this->trans("base.seconds", [$seconds]);

            return trim($str);
        }

        return "";
    }
}
