<?php

namespace Base\Service\Model;

use Base\Form\Common\NativeEnum;
use Base\Service\MediaServiceInterface;
use Base\Service\Translator;
use Base\Service\TranslatorInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

class Autocomplete
{
    /** @var TranslatorInterface */
    protected TranslatorInterface $translator;

    /** @var MediaServiceInterface|null */
    protected ?MediaServiceInterface $mediaService;

    public function __construct(TranslatorInterface $translator, ?MediaServiceInterface $mediaService = null)
    {
        $this->translator = $translator;
        $this->mediaService = $mediaService;
    }

    /**
     * @param $entry
     * @param $class
     * @param array $entryOptions
     * @return array|null
     */
    public function resolve($entry, $class = null, array $entryOptions = [])
    {
        $entryOptions["format"] ??= FORMAT_IDENTITY;
        $entryOptions["html"] ??= true;
        $entryOptions["avatar"] ??= false;

        if ($entry == null) {
            return null;
        }

        if (NativeEnum::is($class)) {

            // A PHP enum: a case, or what a select holds for one.
            $case = NativeEnum::of($class, $entry);
            if ($case === null) {
                return null;
            }

            $id = NativeEnum::id($case);
            $text = NativeEnum::label($case, $this->translator);
            $icons = $case instanceof IconizeInterface ? ($case->__iconize() ?? []) : [];
            $icon = $icons ? begin($icons) : null;
            $color = null;
            $html = null;
            $data = [];

        } elseif (is_object($entry) && $class !== null) {
            $accessor = PropertyAccess::createPropertyAccessor();
            $id = $accessor->isReadable($entry, "id") ? strval($accessor->getValue($entry, "id")) : null;

            $autocomplete = null;
            $autocompleteData = [];

            $entityStr = is_stringeable($entry) ? $entry->__toString() : null;
            if (class_implements_interface($entry, AutocompleteInterface::class)) {
                $autocomplete = $entry->__autocomplete() ?? null;
                $autocompleteData = $entry->__autocompleteData() ?? [];
            }

            $className = get_class($entry);
            $className = $this->translator->transEntity($className, null, Translator::NOUN_SINGULAR);

            $html = $entryOptions["html"] && is_html($autocomplete) ? $autocomplete : null;
            $text = $entryOptions["html"] && is_html($autocomplete) ? null : $entityStr;
            $data = $autocompleteData;

            // A User (or anything else exposing the same avatar-upload
            // convention) can show its real profile picture instead of a
            // generic icon - most useful exactly where a plain name is hardest
            // to tell apart at a glance: several authors picked on the same
            // entity. Falls through to the icon untouched when there's no
            // media service wired in or no avatar set.
            //
            // OPT-IN, and deliberately so: the icon it replaces is not
            // decoration, it is __iconize() - for a User, the icon of their
            // highest role. Swapping it for a photo silently drops that from
            // every picker, and only for the people who happen to have
            // uploaded one, which is the worst of both. A field that wants
            // faces asks for them (SelectType's `avatar` option); everything
            // else keeps saying what the entity IS.
            //
            // Thumbnail the PUBLIC accessor ("/images/<hash>/image.png"), never
            // getAvatarFile(). On a remote storage (S3) Uploader::get() streams the
            // object down to a per-request temp file, so getAvatarFile() hands back a
            // File pointing at "/tmp/phpXXXXXX". thumbnail() then bakes that absolute
            // temp path into the durable obfuscator token - and the temp file is gone
            // by the time the browser requests the token, so every avatar in every
            // select2 dropdown resolved to a dead path and served the no-image
            // placeholder. The public accessor is storage-independent and re-encodes
            // into a stable token that keeps working across requests.
            if ($this->mediaService !== null && ($entryOptions["avatar"] ?? false)) {
                $avatarSource = match (true) {
                    method_exists($entry, "getAvatar") => $entry->getAvatar(),
                    method_exists($entry, "getAvatarFile") => $entry->getAvatarFile(),
                    default => null,
                };
                if (!empty($avatarSource)) {
                    $avatar = $this->mediaService->thumbnail($avatarSource, 40, 40);
                    if (is_string($avatar)) {
                        $data["avatar"] = $avatar;
                    }
                }
            }

            if (!$text) {
                $text = is_stringeable($entry) ? strip_tags(strval($entry)) : $className . " #" . $entry->getId();
            }

            $icons = [];
            if (class_implements_interface($entry, IconizeInterface::class)) {
                $icons = $entry->__iconize();
            }
            if (empty($icons) && class_implements_interface($entry, IconizeInterface::class)) {
                $icons = $entry::__iconizeStatic();
            }

            $icon = begin($icons);


            $color = [];
            if (class_implements_interface($entry, ColorizeInterface::class)) {
                $color = $entry->__colorize();
            }
            if (empty($color) && class_implements_interface($entry, ColorizeInterface::class)) {
                $color = $entry::__colorizeStatic();
            }
            $color = null;

        } elseif (class_implements_interface($class, SelectInterface::class)) {

            $id = $entry;
            $icon = $class::getIcon($entry, 0);
            $text = $class::getText($entry, $this->translator);
            $html = $class::getHtml($entry);
            $data = $class::getData($entry);

            $color = null;
            if (class_implements_interface($entry, ColorizeInterface::class)) {
                $color = $class::getColor($entry);
            }

        } else {
            
            $icon = is_array($entry) ? ($entry[2] ?? $entry[1] ?? $entry[0]) : null;
            $text = is_array($entry) ? ($entry[1] ?? $entry[0]) : $entry;
            $id = is_array($entry) ? ($entry[0]) : $entry;
            $html = null;
            $data = [];

            $color = null;
        }
        
        return
            [
                "id" => $id ?? null,
                "icon" => $icon,
                "color" => $color,
                "search" => null,
                "text" => is_string($text) ? castcase($text, $entryOptions["format"]) : $text,
                "html" => $entryOptions["html"] ? $html : null,
                "data" => $data,
            ];
    }

    /**
     * @param $entry
     * @param array $entryOptions
     * @return array
     * @throws \Exception
     */
    public function resolveArray($entry, array $entryOptions = [])
    {
        $entryOptions["format"] ??= FORMAT_IDENTITY;

        return array_transforms(function ($k, $v, $callback, $i, $d) use ($entryOptions): ?array {
            if (is_array($v)) {
                $children = array_transforms($callback, $v, ++$d);

                $group = array_pop_key("_self", $children);
                $group["text"] = $k;
                $group["children"] = $children;
                return [null, $group];
            }

            return [null, ["id" => $v, "icon" => $v, "text" => castcase($k, $entryOptions["format"])]];
        }, !empty($entry) ? $entry : []);
    }
}
