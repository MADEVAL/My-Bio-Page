<?php
declare(strict_types=1);

namespace Bio;

/**
 * Renders both languages from the same content tree. Nothing here knows
 * about HTTP, caching or MCP.
 */
final class Renderer
{
    public function __construct(
        private readonly Content $content,
        private readonly string $template,
    ) {
    }

    public function render(string $lang): string
    {
        $data = $this->content->load();
        $ui = $data['ui'][$lang];

        $v = [
            'lang' => $lang,
            'other' => $lang === 'en' ? 'ru' : 'en',
            'otherHref' => $lang === 'en' ? 'ru.html' : './',
            'data' => $data,
            'seo' => Seo::build($data, $lang),
            'ui' => $ui,
            'profile' => $data['profile'][$lang],
            'settings' => $data['settings'],
            'sections' => self::sections($data, $lang),
            'year' => max((int) date('Y'), $data['settings']['footerStart']),
            'i18n' => (string) json_encode([
                'copied' => $ui['copied'],
                'copyFailed' => $ui['copyFailed'],
                'themeToLight' => $ui['themeToLight'],
                'themeToDark' => $ui['themeToDark'],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
        ];

        $template = $this->template;
        ob_start();
        require $template;

        return (string) ob_get_clean();
    }

    /** @return list<array<string, mixed>> */
    private static function sections(array $data, string $lang): array
    {
        $out = [];

        foreach ($data['sections'] as $section) {
            $visible = array_values(array_filter($section['items'], static fn (array $i): bool => $i['visible']));

            $out[] = [
                'id' => $section['id'],
                'label' => $section['label'][$lang],
                'srLabel' => $section['srLabel'][$lang],
                'layout' => $section['layout'],
                'cards' => array_values(array_filter($visible, static fn (array $i): bool => $i['type'] !== 'social')),
                'socials' => array_values(array_filter($visible, static fn (array $i): bool => $i['type'] === 'social')),
            ];
        }

        return $out;
    }

    public static function iconHtml(array $item): string
    {
        if (isset($item['logo'])) {
            return '<img src="' . e($item['logo']) . '" alt="" class="link-logo" width="24" height="24" loading="lazy" decoding="async">';
        }

        return Icons::render($item['icon']);
    }

    public static function card(array $item, string $lang): string
    {
        $class = 'link-card' . match ($item['type']) {
            'featured' => ' featured',
            'project' => ' project-card',
            'email' => ' email-card',
            default => '',
        };

        $target = $item['type'] === 'email' ? '' : ' target="_blank" rel="noopener noreferrer"';
        $subtitle = tr($item['subtitle'], $lang);
        $subtitleHtml = $subtitle === ''
            ? ''
            : "\n                " . '<span class="link-subtitle">' . e($subtitle) . '</span>';

        return '<a href="' . e($item['href']) . '" class="' . $class . '"' . $target . ">\n"
            . '            <div class="link-icon">' . self::iconHtml($item) . "</div>\n"
            . "            <div class=\"link-content\">\n"
            . '                <span class="link-title">' . e(tr($item['title'], $lang)) . '</span>'
            . $subtitleHtml . "\n"
            . "            </div>\n"
            . '            ' . Icons::render('arrow-right', 'link-arrow') . "\n"
            . '        </a>';
    }

    public static function emailBlock(array $item, string $lang, array $ui): string
    {
        $email = str_starts_with($item['href'], 'mailto:') ? substr($item['href'], 7) : $item['href'];

        return "        <div class=\"email-wrapper\">\n"
            . '        ' . self::card($item, $lang) . "\n"
            . '            <button type="button" class="copy-btn" id="copyEmail" data-email="' . e($email)
            . '" aria-label="' . e($ui['copyEmail']) . "\">\n"
            . '                ' . Icons::render('copy') . "\n"
            . "            </button>\n"
            . '        </div>';
    }

    public static function socialButton(array $item, string $lang): string
    {
        return '<a href="' . e($item['href']) . '" class="social-btn" data-brand="' . e($item['icon'])
            . '" target="_blank" rel="noopener noreferrer" aria-label="' . e(tr($item['title'], $lang)) . "\">\n"
            . '                    ' . Icons::render($item['icon']) . "\n"
            . '                </a>';
    }
}
