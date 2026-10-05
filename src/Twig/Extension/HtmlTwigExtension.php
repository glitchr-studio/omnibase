<?php

namespace Base\Twig\Extension;

use Base\Service\BaseService;

use Base\Service\EditorEnhancerInterface;
use Base\Service\WysiwygEnhancerInterface;
use Base\Twig\Renderer\Adapter\HtmlTagRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class HtmlTwigExtension extends AbstractExtension
{
    protected HtmlTagRenderer $htmlTagRenderer;

    protected WysiwygEnhancerInterface $wysiwygEnhancer;
    protected EditorEnhancerInterface $editorEnhancer;

    public function __construct(HtmlTagRenderer $htmlTagRenderer, WysiwygEnhancerInterface $wysiwygEnhancer, EditorEnhancerInterface $editorEnhancer)
    {
        $this->htmlTagRenderer = $htmlTagRenderer;
        $this->wysiwygEnhancer = $wysiwygEnhancer;
        $this->editorEnhancer = $editorEnhancer;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('wysiwyg', [$this, 'renderWysiwyg']),
            new TwigFilter('wysiwyg_toc', [$this, 'getTableOfContents']),
        ];
    }

    /**
     * @param string|null $htmlOrJson
     * @param array $options
     * @param int|null $maxLevel
     * @return string
     */
    public function renderWysiwyg(?string $htmlOrJson, array $options = [])
    {
        if ($this->editorEnhancer->supports($htmlOrJson)) {
            $enhancer = $this->editorEnhancer;
        } elseif ($this->wysiwygEnhancer->supports($htmlOrJson)) {
            $enhancer = $this->wysiwygEnhancer;
        } else {
            throw new \RuntimeException('Unsupported wysiwyg input');
        }
        
        $htmlOrJson = $enhancer->enhanceMentions($htmlOrJson);
        $htmlOrJson = $enhancer->enhanceLinks($htmlOrJson);

        $applySemantics = array_pop_key('semantics', $options) ?? false;
        if ($applySemantics) {
            $htmlOrJson = $enhancer->enhanceSemantics($htmlOrJson);
        }

        $mediaConfig = array_pop_key('media', $options) ?? true;
        if ($mediaConfig) {
            
            $mediaConfig = is_array($mediaConfig) ? $mediaConfig : [];
            $mediaConfig["storage"] ??= BaseService::getParameterBag("base.twig.editor.storage");

            $htmlOrJson = $enhancer->enhanceMedia($htmlOrJson, $mediaConfig);
        }

        $maxHeadings = array_pop_key('headings', $options) ?? 0;
        if ($maxHeadings) {

            $htmlOrJson = $enhancer->enhanceHeadings($htmlOrJson, $maxHeadings === true ? null : $maxHeadings);
        }

        return $enhancer->render($htmlOrJson, ['attr' => $options['row_attr'] ?? [], 'hydrate' => $options['hydrate'] ?? true]);
    }

    public function getTableOfContents(?string $htmlOrJson, ?int $maxLevel = null): array
    {
        if ($this->editorEnhancer->supports($htmlOrJson)) {
            $enhancer = $this->editorEnhancer;
        } elseif ($this->wysiwygEnhancer->supports($htmlOrJson)) {
            $enhancer = $this->wysiwygEnhancer;
        } else {
            throw new \RuntimeException('Unsupported wysiwyg input');
        }

        return $enhancer->getTableOfContents($htmlOrJson, $maxLevel);
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('html_entry_link_tags', [$this, 'renderLinkTags'], ['is_safe' => ['all'], 'needs_environment' => true, 'raw' => true]),
            new TwigFunction('html_entry_script_tags', [$this, 'renderScriptTags'], ['is_safe' => ['all'], 'needs_environment' => true, 'raw' => true]),

            new TwigFunction('html_entry_head_tags', [$this, 'renderHeadTags'], ['is_safe' => ['all'], 'needs_environment' => true, 'raw' => true]),
            new TwigFunction('html_entry_noscript_tags', [$this, 'renderNoscriptTags'], ['is_safe' => ['all'], 'needs_environment' => true, 'raw' => true]),
            new TwigFunction('html_entry_body_tags', [$this, 'renderBodyTags'], ['is_safe' => ['all'], 'needs_environment' => true, 'raw' => true]),
        ];
    }

    public function renderLinkTags(): string
    {
        return $this->htmlTagRenderer->renderHtmlContent('stylesheet:before') .
            $this->htmlTagRenderer->renderHtmlContent('stylesheet') .
            $this->htmlTagRenderer->renderHtmlContent('stylesheet:after');
    }

    public function renderScriptTags(): string
    {
        return $this->htmlTagRenderer->renderHtmlContent('javascript:head') .
            $this->htmlTagRenderer->renderHtmlContent('javascript') .
            $this->htmlTagRenderer->renderHtmlContent('javascript:body');
    }

    public function renderHeadTags(): string
    {
        return $this->htmlTagRenderer->renderHtmlContent('stylesheet:before') .
            $this->htmlTagRenderer->renderHtmlContent('stylesheet') .
            $this->htmlTagRenderer->renderHtmlContent('stylesheet:after') .

            $this->htmlTagRenderer->renderHtmlContent('javascript:head') .
            $this->htmlTagRenderer->renderHtmlContent('javascript');
    }

    public function renderNoscriptTags(): string
    {
        return $this->htmlTagRenderer->renderHtmlContent('noscripts');
    }

    public function renderBodyTags(): string
    {
        return $this->htmlTagRenderer->renderHtmlContent('javascript:body');
    }
}
