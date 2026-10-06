<?php

namespace Base\DependencyInjection\Compiler\Pass;

use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Which texts the application wrote itself (its own translations/
 * directory), for the level of politeness (base.translator.politeness,
 * docs/20-architecture/politeness.md).
 *
 * An application that rewrote a bundle's text under its key - a practice's
 * "Aucun message." in place of a forum's "Tu n'as aucun message dans ta
 * boîte" - wrote it as it speaks. When the bundle later brings a polite
 * variant of its own text ("key._polite"), that variant must not come back
 * in place of the application's: once the catalogues are merged nothing tells
 * whose a text is, so the keys are read here, while the container is built,
 * and given to omnibase's translator. Nothing is read when no level is set.
 */
final class ApplicationTextsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('Base\Service\Translator')) {
            return;
        }

        $level = $container->hasParameter('base.translator.politeness') ? $container->getParameter('base.translator.politeness') : null;
        $directory = $container->hasParameter('translator.default_path') ? $container->getParameterBag()->resolveValue($container->getParameter('translator.default_path')) : null;
        if (null === $level || '' === $level || !\is_string($directory) || !is_dir($directory)) {
            return;
        }

        // Rebuilt when a catalogue of the application changes (in debug, as the catalogues themselves are).
        $container->addResource(new DirectoryResource($directory, '/\.(ya?ml|php|json)$/'));
        $container->getDefinition('Base\Service\Translator')->setArgument(4, self::read($directory));
    }

    /**
     * The keys of every catalogue of a directory: "<domain>[+intl-icu].<locale>.<yaml|yml|php|json>".
     *
     * @return array<string, array<string, list<string>>> domain => locale => keys, flattened with dots
     */
    public static function read(string $directory): array
    {
        $texts = [];
        foreach (glob(rtrim($directory, '/').'/*.*.{yaml,yml,php,json}', \GLOB_BRACE) ?: [] as $file) {
            if (!preg_match('/^(?<domain>.+?)(?:\+intl-icu)?\.(?<locale>[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]+)*)\.(?<format>yaml|yml|php|json)$/', basename($file), $name)) {
                continue;
            }

            try {
                $messages = match ($name['format']) {
                    'php' => require $file,
                    'json' => json_decode((string) file_get_contents($file), true),
                    default => Yaml::parseFile($file),
                };
            } catch (\Throwable) {
                continue; // not a catalogue the translator could load either: its own error says so
            }
            if (!\is_array($messages)) {
                continue;
            }

            $keys = [];
            $walk = static function (array $node, string $prefix) use (&$walk, &$keys): void {
                foreach ($node as $key => $value) {
                    \is_array($value) ? $walk($value, $prefix.$key.'.') : $keys[] = $prefix.$key;
                }
            };
            $walk($messages, '');

            $locale = str_replace('-', '_', $name['locale']);
            $texts[$name['domain']][$locale] = array_values(array_unique(array_merge($texts[$name['domain']][$locale] ?? [], $keys)));
        }

        return $texts;
    }
}
