<?php

namespace Base\Service\Model\Wysiwyg;

/**
 * EditorJS's saved document ({"blocks": [...]}) as HTML, on the server: what
 * a page shows without the editor's script, what a crawler, an e-mail or a
 * feed reads. The blocks of omnibase's editor: paragraph, header, list (nested,
 * ordered, checklist), checklist, quote, image, embed, table, code, delimiter,
 * warning, alert, raw, linkTool, attaches. A block of a type it does not know
 * is left out.
 *
 * The text of a block is the editor's inline HTML (bold, links, marks,
 * mentions): kept, minus what could run (tags outside the list below, on*
 * attributes, javascript: addresses).
 */
final class EditorJsRenderer
{
    private const INLINE = '<b><strong><i><em><u><s><del><ins><a><mark><code><br><span><sub><sup><small><abbr><kbd><q><cite><img>';

    public static function render(mixed $document): string
    {
        if (\is_string($document)) {
            $document = json_decode($document, true);
        } elseif (\is_object($document)) {
            $document = json_decode(json_encode($document), true);
        }

        $html = [];
        foreach ($document['blocks'] ?? [] as $block) {
            if (!\is_array($block)) {
                continue;
            }

            $rendered = self::block((string) ($block['type'] ?? ''), (array) ($block['data'] ?? []), (array) ($block['tunes'] ?? []));
            if ('' !== $rendered) {
                $html[] = $rendered;
            }
        }

        return implode("\n", $html);
    }

    private static function block(string $type, array $data, array $tunes): string
    {
        $align = $tunes['alignment']['alignment'] ?? $tunes['alignmentTune']['alignment'] ?? $data['alignment'] ?? $data['align'] ?? null;
        $style = \in_array($align, ['left', 'center', 'right', 'justify'], true) ? ' style="text-align: '.$align.'"' : '';

        switch ($type) {
            case 'paragraph':
                $text = self::inline($data['text'] ?? '');

                return '' === trim(strip_tags($text, '<img>')) ? '' : '<p'.$style.'>'.$text.'</p>';

            case 'header':
                $level = min(6, max(1, (int) ($data['level'] ?? 2)));

                return '<h'.$level.$style.'>'.self::inline($data['text'] ?? '').'</h'.$level.'>';

            case 'list':
                return self::items((array) ($data['items'] ?? []), (string) ($data['style'] ?? 'unordered'));

            case 'checklist':
                return self::items((array) ($data['items'] ?? []), 'checklist');

            case 'quote':
                $caption = self::inline($data['caption'] ?? '');

                return '<blockquote'.$style.'><p>'.self::inline($data['text'] ?? '').'</p>'
                    .('' !== trim(strip_tags($caption)) ? '<footer><cite>'.$caption.'</cite></footer>' : '').'</blockquote>';

            case 'delimiter':
                return '<hr>';

            case 'code':
                return '<pre><code>'.self::escape($data['code'] ?? '').'</code></pre>';

            case 'raw':
                return (string) ($data['html'] ?? '');

            case 'warning':
            case 'alert':
                $kind = preg_replace('/[^a-z0-9_-]/i', '', (string) ($data['type'] ?? 'warning'));
                $title = self::inline($data['title'] ?? '');

                return '<aside class="wysiwyg-alert wysiwyg-alert-'.$kind.'" role="note">'
                    .('' !== trim(strip_tags($title)) ? '<strong>'.$title.'</strong> ' : '')
                    .self::inline($data['message'] ?? '').'</aside>';

            case 'image':
                $url = self::url($data['file']['url'] ?? $data['url'] ?? '');
                if ('' === $url) {
                    return '';
                }
                $caption = self::inline($data['caption'] ?? '');
                $text = trim(strip_tags($caption));
                $classes = array_keys(array_filter([
                    'wysiwyg-image' => true,
                    'is-bordered' => !empty($data['withBorder']),
                    'is-stretched' => !empty($data['stretched']),
                    'has-background' => !empty($data['withBackground']),
                ]));

                return '<figure class="'.implode(' ', $classes).'"><img src="'.self::escape($url).'" alt="'.self::escape($text).'" loading="lazy">'
                    .('' !== $text ? '<figcaption>'.$caption.'</figcaption>' : '').'</figure>';

            case 'embed':
                $url = self::url($data['embed'] ?? '');
                if ('' === $url) {
                    return '';
                }
                $caption = self::inline($data['caption'] ?? '');
                $size = ((int) ($data['width'] ?? 0) > 0 ? ' width="'.(int) $data['width'].'"' : '').((int) ($data['height'] ?? 0) > 0 ? ' height="'.(int) $data['height'].'"' : '');

                return '<figure class="wysiwyg-embed wysiwyg-embed-'.preg_replace('/[^a-z0-9_-]/i', '', (string) ($data['service'] ?? 'frame')).'">'
                    .'<iframe src="'.self::escape($url).'"'.$size.' loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>'
                    .('' !== trim(strip_tags($caption)) ? '<figcaption>'.$caption.'</figcaption>' : '').'</figure>';

            case 'table':
                $rows = array_values(array_filter((array) ($data['content'] ?? []), 'is_array'));
                if (!$rows) {
                    return '';
                }
                $out = '<table>';
                if (!empty($data['withHeadings'])) {
                    $out .= '<thead><tr>'.implode('', array_map(fn ($c) => '<th scope="col">'.self::inline($c).'</th>', array_shift($rows))).'</tr></thead>';
                }
                $out .= '<tbody>';
                foreach ($rows as $row) {
                    $out .= '<tr>'.implode('', array_map(fn ($c) => '<td>'.self::inline($c).'</td>', $row)).'</tr>';
                }

                return $out.'</tbody></table>';

            case 'linkTool':
                $url = self::url($data['link'] ?? '');
                if ('' === $url) {
                    return '';
                }
                $title = trim((string) ($data['meta']['title'] ?? '')) ?: $url;
                $description = trim((string) ($data['meta']['description'] ?? ''));

                return '<p class="wysiwyg-link"><a href="'.self::escape($url).'" rel="noopener">'.self::escape($title).'</a>'
                    .('' !== $description ? ' <span>'.self::escape($description).'</span>' : '').'</p>';

            case 'attaches':
                $url = self::url($data['file']['url'] ?? '');
                if ('' === $url) {
                    return '';
                }

                return '<p class="wysiwyg-attachment"><a href="'.self::escape($url).'" download>'.self::escape(trim((string) ($data['title'] ?? '')) ?: ($data['file']['name'] ?? $url)).'</a></p>';
        }

        return '';
    }

    /** A list: plain strings (the first List tool), or {content, items, meta} (NestedList, List 2). */
    private static function items(array $items, string $style): string
    {
        if (!$items) {
            return '';
        }

        $tag = 'ordered' === $style ? 'ol' : 'ul';
        $out = '<'.$tag.('checklist' === $style ? ' class="wysiwyg-checklist"' : '').'>';
        foreach ($items as $item) {
            if (\is_array($item)) {
                $text = self::inline($item['content'] ?? $item['text'] ?? '');
                $checked = $item['checked'] ?? $item['meta']['checked'] ?? null;
                $children = self::items((array) ($item['items'] ?? []), $style);
            } else {
                [$text, $checked, $children] = [self::inline($item), null, ''];
            }

            if ('checklist' === $style) {
                $out .= '<li class="'.($checked ? 'is-checked' : 'is-unchecked').'"><input type="checkbox" disabled'.($checked ? ' checked' : '').'> '.$text.$children.'</li>';
            } else {
                $out .= '<li>'.$text.$children.'</li>';
            }
        }

        return $out.'</'.$tag.'>';
    }

    /** The editor's inline HTML, minus what could run. */
    private static function inline(mixed $text): string
    {
        if (!\is_scalar($text)) {
            return '';
        }

        $text = strip_tags((string) $text, self::INLINE);
        $text = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $text);

        return preg_replace('/\b(href|src)\s*=\s*(["\']?)\s*(?:javascript|vbscript|data(?!:image\/))\s*:[^"\'>\s]*\2/i', '$1="#"', $text);
    }

    /** An address a page may link or load: relative, http(s), mailto, tel, or an embedded image. */
    private static function url(mixed $url): string
    {
        $url = \is_scalar($url) ? trim((string) $url) : '';
        if ('' === $url) {
            return '';
        }

        return preg_match('#^(?:https?:)?//|^/|^\./|^\.\./|^mailto:|^tel:|^data:image/#i', $url) || !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) ? $url : '';
    }

    private static function escape(mixed $text): string
    {
        return htmlspecialchars(\is_scalar($text) ? (string) $text : '', \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
