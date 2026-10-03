<?php

namespace Base\Service\Qr;

use Twig\Environment;

/**
 * QR codes laid out on A4 pages to print: one big code per page (a poster
 * for a counter, a door, a table), or a sheet of Avery labels - each label
 * a code and a caption, at the label's place on the sheet, in millimetres.
 *
 *     return new Response($sheet->render([
 *         ['data' => $url, 'label' => 'Table 4'],
 *         ...
 *     ], 'L7160', 'Tables'));
 *
 * Layouts (LAYOUTS): 'a4' one per page; Avery 'L7160' (21 labels of
 * 63.5 x 38.1 mm), 'L7163' (14 of 99.1 x 38.1 mm), 'L7121' (20 squares of
 * 45 x 45 mm, made for QR codes). The page prints at 100 % (no "fit to
 * page") with @page margins of zero - the template sets both.
 */
class QrSheet
{
    /**
     * In millimetres: label size, columns x rows, the first label's top-left
     * corner, the step from one label to the next.
     */
    public const LAYOUTS = [
        'a4' => ['width' => 210.0, 'height' => 297.0, 'columns' => 1, 'rows' => 1, 'top' => 0.0, 'left' => 0.0, 'pitchX' => 210.0, 'pitchY' => 297.0],
        'L7160' => ['width' => 63.5, 'height' => 38.1, 'columns' => 3, 'rows' => 7, 'top' => 15.1, 'left' => 7.2, 'pitchX' => 66.0, 'pitchY' => 38.1],
        'L7163' => ['width' => 99.1, 'height' => 38.1, 'columns' => 2, 'rows' => 7, 'top' => 15.1, 'left' => 4.65, 'pitchX' => 101.6, 'pitchY' => 38.1],
        'L7121' => ['width' => 45.0, 'height' => 45.0, 'columns' => 4, 'rows' => 5, 'top' => 26.0, 'left' => 7.5, 'pitchX' => 50.0, 'pitchY' => 50.0],
    ];

    public function __construct(
        protected readonly QrCode $qr,
        protected readonly ?Environment $twig = null,
    ) {
    }

    /** @return array{width: float, height: float, columns: int, rows: int, top: float, left: float, pitchX: float, pitchY: float} */
    public static function layout(string $name): array
    {
        return self::LAYOUTS[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown QR sheet layout "%s"; known: %s.', $name, implode(', ', array_keys(self::LAYOUTS))));
    }

    /**
     * The items placed on their pages: each label's position (mm) and its
     * code as a data URI. $skip leaves the first labels of the first sheet
     * empty - those already used on a sheet put back in the printer.
     *
     * @param iterable<array{data: string, label?: ?string}|string> $items
     *
     * @return list<list<array{data: string, label: ?string, qr: string, x: float, y: float}>> pages of labels
     */
    public function pages(iterable $items, string $layout = 'a4', int $skip = 0): array
    {
        $grid = self::layout($layout);
        $perPage = $grid['columns'] * $grid['rows'];
        $codeSize = 'a4' === $layout ? 1200 : 400;

        $pages = [];
        $index = max(0, $skip);
        foreach ($items as $item) {
            $item = \is_string($item) ? ['data' => $item] : $item;
            $page = intdiv($index, $perPage);
            $slot = $index % $perPage;
            $pages[$page][] = [
                'data' => (string) $item['data'],
                'label' => $item['label'] ?? null,
                'qr' => $this->qr->dataUri((string) $item['data'], $codeSize),
                'x' => round($grid['left'] + ($slot % $grid['columns']) * $grid['pitchX'], 2),
                'y' => round($grid['top'] + intdiv($slot, $grid['columns']) * $grid['pitchY'], 2),
            ];
            ++$index;
        }

        return array_values($pages);
    }

    /** The printable HTML page (@Base/qr/sheet.html.twig). */
    public function render(iterable $items, string $layout = 'a4', ?string $title = null, int $skip = 0): string
    {
        if (!$this->twig) {
            throw new \LogicException('QrSheet::render() needs Twig.');
        }

        return $this->twig->render('@Base/qr/sheet.html.twig', [
            'title' => $title,
            'layout' => $layout,
            'grid' => self::layout($layout),
            'pages' => $this->pages($items, $layout, $skip),
        ]);
    }
}
