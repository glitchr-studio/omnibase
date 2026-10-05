<?php

namespace Base\Service;

use Base\Imagine\FilterInterface;
use Base\Service\Model\Wysiwyg\EditorJsRenderer;

class EditorEnhancer extends WysiwygEnhancer implements EditorEnhancerInterface
{
    public function supports(mixed $json): bool
    {
        return is_json($json);
    }

    public function render(mixed $json, array $options = []): string
    {
        if (is_string($json)) {
            $json = json_decode($json);
        }

        // The blocks as HTML, written by the server: what the page shows by
        // itself. `hydrate` (true by default) also leaves the document in
        // data-edjs, for the editor's script to take over as a read-only
        // editor where a page loads it; false prints the HTML alone.
        return $this->twig->render("@Base/form/wysiwyg/editor_js.html.twig", [
            "json" => $json,
            "html" => EditorJsRenderer::render($json),
            "hydrate" => $options["hydrate"] ?? true,
            "options" => $options,
        ]);
    }

    public function getTableOfContents(mixed $json, ?int $maxLevel = null): array
    {
        if (is_string($json)) {
            $json = json_decode($json);
        }

        $headlines = [];
        foreach ($json->blocks ?? [] as $block) {

            if ($block->type != "header") continue;
            
            $block->data->text = "<h".$block->data->level.">".strip_tags($block->data->text)."</h".$block->data->level.">";
            $headlines = array_merge($headlines, $this->headingEnhancer->toc($block->data->text, $maxLevel));
        }

        return $headlines;
    }

    public function enhanceHeadings(mixed $json, ?int $maxLevel = null, array $attrs = []): mixed
    {
        if (is_string($json)) {
            $json = json_decode($json);
        }

        foreach ($json->blocks ?? [] as $block) {

            if ($block->type != "header") continue;
    
            $block->data->text = "<h".$block->data->level.">".strip_tags($block->data->text)."</h".$block->data->level.">";
            $block->data->text = $this->headingEnhancer->enhance($block->data->text, $maxLevel, $attrs);
            $block->data->text = str_strip($block->data->text, "<h".$block->data->level.">", "</h".$block->data->level.">");
        }
        
        return $json;
    }

    public function enhanceSemantics(mixed $json, null|array|string $words = null, array $attrs = []): mixed
    {
        if (is_string($json)) {
            $json = json_decode($json);
        }

        $attrs ??= [];
        $attrs["class"] = $attrs["class"] ?? "";
        $attrs["class"] = trim($attrs["class"] . " markdown-semantic");

        foreach (json_leaves($json) as &$block) {
            $block = $this->semanticEnhancer->enhance($block, $words, $attrs);
        }

        return json_encode($json);
    }

    public function enhanceMentions(mixed $json, array $attrs = []): mixed
    {
        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        $attrs ??= [];
        $attrs["class"] = $attrs["class"] ?? "";
        $attrs["class"] = trim($attrs["class"] . " markdown-mention");

        foreach (json_leaves($json) as &$block) {
            $block = $this->mentionEnhancer->enhance($block, $attrs);
        }

        return json_encode($json);
    }

    public function enhanceLinks(mixed $json, array $attrs = []): mixed
    {
        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        foreach (json_leaves($json) as &$block) {
            $block = $this->linkEnhancer->enhance($block, $attrs);
        }

        return json_encode($json);
    }

    public function enhanceMedia(mixed $json, array $config = [], FilterInterface|array $filters = [], array $attrs = []): mixed
    {
        if (is_string($json)) {
            $json = json_decode($json);
        }

        $attrs ??= [];
        $attrs["class"] = $attrs["class"] ?? "";
        $attrs["class"] = trim($attrs["class"] . " markdown-media");

        foreach ($json->blocks ?? [] as $block) {
       
            if ($block->type != "image") continue;

            // `origin` is the immutable raw upload (/wysiwyg/…) EditorJS stores
            // alongside `url`. Re-derive the displayed URL from `origin` when present:
            // `url` is only a derived value and can hold a stale or nested obfuscated
            // encoding (e.g. a viewer /images/… URL that leaked back into the saved
            // content, whose short-lived obfuscator mapping was later wiped by a
            // cache:clear — making the image 404). Deriving from the raw origin every
            // render makes the URL self-healing. Older blocks without `origin` fall
            // back to `url`.
            $source = $block?->data?->file?->origin ?? $block?->data?->file?->url;
            if($source) $block->data->file->url = $this->mediaEnhancer->enhance($source, $config, $filters, $attrs);
        }
        
        return $json;
    }
}
