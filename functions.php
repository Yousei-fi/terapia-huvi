<?php

require_once __DIR__ . '/vendor/autoload.php';

\Timber\Timber::init();

use Twig\TwigFunction;

class HPPTheme extends \Timber\Site {
    private const SUPPORTED_LANGUAGES = ['fi', 'en'];

    /**
     * Cache for resolved pages keyed by language and slug.
     *
     * @var array<string, array<string, \Timber\Post|null>>
     */
    private array $pageTextCache = [];

    private string $currentLanguage = 'fi';

    public function __construct() {
        \Timber\Timber::$dirname = ['views', 'src/components'];
        add_action('after_setup_theme', [$this, 'theme_supports']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('timber/context', [$this, 'add_to_context']);
        add_filter('timber/twig', [$this, 'extend_twig']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'lang';

            return $vars;
        });
        add_action('init', [$this, 'detect_language']);
        add_action('after_switch_theme', [$this, 'seed_required_pages']);
        add_action('init', [$this, 'seed_required_pages_once'], 20);
        parent::__construct();
    }

    public function theme_supports(): void {
        add_theme_support('title-tag');
        add_theme_support('post-thumbnails');
        add_theme_support('menus');
        add_theme_support('custom-logo', [
            'height' => 512,
            'width' => 512,
            'flex-height' => true,
            'flex-width' => true,
        ]);
        add_theme_support('site-icon');

        register_nav_menus([
            'main_menu' => __('Main Menu', 'hppry'),
        ]);
    }

    public function add_to_context(array $context): array {
        $context['menu'] = \Timber\Timber::get_menu('main_menu');
        $context['site'] = $this;
        $context['language'] = $this->currentLanguage;
        $context['languages'] = $this->get_language_switcher();

        return $context;
    }

    public function extend_twig(\Twig\Environment $twig): \Twig\Environment {
        $twig->addFunction(new TwigFunction('page_field', [$this, 'get_page_field']));
        $twig->addFunction(new TwigFunction('page_field_list', [$this, 'get_page_field_list']));
        $twig->addFunction(new TwigFunction('page_field_pairs', [$this, 'get_page_field_pairs']));
        $twig->addFunction(new TwigFunction('page_field_cards', [$this, 'get_page_field_cards']));

        return $twig;
    }

    public function enqueue_assets(): void {
        $theme_version = wp_get_theme()->get('Version');

        wp_enqueue_style(
            'hpp-style',
            get_template_directory_uri() . '/dist/style.css',
            [],
            $theme_version
        );

        wp_enqueue_script(
            'hpp-js',
            get_template_directory_uri() . '/dist/app.js',
            [],
            $theme_version,
            true
        );
    }

    /**
     * Resolve a text value from a WordPress page by slug.
     */
    public function get_page_field(string $slug, string $field = 'content', string $default = ''): string {
        $page = $this->resolve_page($slug);

        if (!$page) {
            return $default;
        }

        switch ($field) {
            case 'title':
                $value = $page->title();
                break;
            case 'excerpt':
                $value = $page->excerpt();
                break;
            case 'content':
                $value = apply_filters('the_content', $page->content());
                break;
            default:
                $value = get_post_meta($page->ID, $field, true);
                if ('' === $value) {
                    $value = null;
                }
                break;
        }

        if (is_string($value) && '' !== trim($value)) {
            return $value;
        }

        return $default;
    }

    /**
     * Resolve list data (split by newlines) from a page field.
     *
     * @param array<int, string>|string $default
     *
     * @return array<int, string>
     */
    public function get_page_field_list(string $slug, string $field, array|string $default = []): array {
        $raw = $this->get_page_field($slug, $field, is_string($default) ? $default : '');
        $values = $this->split_lines($raw);

        if (!empty($values)) {
            return $values;
        }

        if (is_array($default)) {
            return $default;
        }

        return $this->split_lines($default);
    }

    /**
     * Resolve paired data (Title::Description per line) from a page field.
     *
     * @param array<int, array{title: string, copy: string}> $default
     *
     * @return array<int, array{title: string, copy: string}>
     */
    public function get_page_field_pairs(string $slug, string $field, array $default = []): array {
        $raw = $this->get_page_field($slug, $field, '');
        $pairs = $this->parse_pairs($raw);

        if (!empty($pairs)) {
            return $pairs;
        }

        return $default;
    }

    /**
     * Resolve CTA cards (Title::Copy::Label::URL per line) from a page field.
     *
     * @param array<int, array{title: string, copy: string, cta_label: string, cta_url: string}> $default
     *
     * @return array<int, array{title: string, copy: string, cta_label: string, cta_url: string}>
     */
    public function get_page_field_cards(string $slug, string $field, array $default = []): array {
        $raw = $this->get_page_field($slug, $field, '');
        $lines = $this->split_lines($raw);
        $cards = [];

        foreach ($lines as $line) {
            [$title, $copy, $ctaLabel, $ctaUrl] = array_pad(array_map('trim', explode('::', $line, 4)), 4, '');

            if ('' === $title && '' === $copy) {
                continue;
            }

            $cards[] = [
                'title' => $title,
                'copy' => $copy,
                'cta_label' => $ctaLabel,
                'cta_url' => $ctaUrl,
            ];
        }

        if (!empty($cards)) {
            return $cards;
        }

        return $default;
    }

    /**
     * Cache and return a Timber\Post for the given slug.
     */
    private function resolve_page(string $slug): ?\Timber\Post {
        if (!array_key_exists($this->currentLanguage, $this->pageTextCache)) {
            $this->pageTextCache[$this->currentLanguage] = [];
        }

        if (!array_key_exists($slug, $this->pageTextCache[$this->currentLanguage])) {
            $this->pageTextCache[$this->currentLanguage][$slug] = $this->locate_page($slug);
        }

        return $this->pageTextCache[$this->currentLanguage][$slug];
    }

    /**
     * @return array<int, string>
     */
    private function split_lines(string $value): array {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn ($line) => '' !== $line));
    }

    /**
     * @return array<int, array{title: string, copy: string}>
     */
    private function parse_pairs(string $value): array {
        $lines = $this->split_lines($value);
        $pairs = [];

        foreach ($lines as $line) {
            [$title, $copy] = array_pad(array_map('trim', explode('::', $line, 2)), 2, '');

            if ('' === $title && '' === $copy) {
                continue;
            }

            $pairs[] = [
                'title' => $title,
                'copy' => $copy,
            ];
        }

        return $pairs;
    }

    public function detect_language(): void {
        $lang = get_query_var('lang', '');

        if (!$lang && isset($_GET['lang'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $lang = sanitize_key((string) wp_unslash($_GET['lang'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if (!is_string($lang) || '' === $lang) {
            $lang = 'fi';
        }

        if (!in_array($lang, self::SUPPORTED_LANGUAGES, true)) {
            $lang = 'fi';
        }

        $this->currentLanguage = $lang;
    }

    /**
     * @return array<int, array{code: string, label: string, url: string, current: bool}>
     */
    private function get_language_switcher(): array {
        global $wp;

        $requestPath = '';

        if ($wp instanceof \WP) {
            $requestPath = $wp->request ? '/' . ltrim($wp->request, '/') : '';
        }

        $baseUrl = home_url($requestPath);
        $queryArgs = [];

        foreach ($_GET as $key => $value) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ('lang' === $key) {
                continue;
            }

            $queryArgs[$key] = sanitize_text_field((string) $value);
        }

        $languages = [
            [
                'code' => 'fi',
                'label' => __('Suomeksi', 'hppry'),
            ],
            [
                'code' => 'en',
                'label' => __('In English', 'hppry'),
            ],
        ];

        return array_map(function (array $language) use ($baseUrl, $queryArgs): array {
            $url = add_query_arg(array_merge($queryArgs, ['lang' => $language['code']]), $baseUrl);

            return array_merge($language, [
                'url' => $url,
                'current' => $language['code'] === $this->currentLanguage,
            ]);
        }, $languages);
    }

    private function locate_page(string $slug): ?\Timber\Post {
        foreach ($this->localized_slugs($slug) as $candidate) {
            $page = get_page_by_path($candidate, OBJECT, 'page');

            if ($page instanceof \WP_Post) {
                return new \Timber\Post($page);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function localized_slugs(string $slug): array {
        $slug = trim($slug);

        if ('' === $slug) {
            return [];
        }

        if ('fi' === $this->currentLanguage) {
            return [$slug];
        }

        return [
            $slug . '-en',
            $slug,
        ];
    }

    public function seed_required_pages_once(): void {
        if (get_option('hpp_seeded_pages')) {
            return;
        }

        $this->seed_required_pages();
        update_option('hpp_seeded_pages', time());
    }

    public function seed_required_pages(): void {
        $publicPages = [
            ['slug' => 'missio', 'title' => __('Missio', 'hppry')],
            ['slug' => 'tietopyynnot', 'title' => __('Tietopyynnöt', 'hppry')],
            ['slug' => 'osallistu', 'title' => __('Osallistu', 'hppry')],
            ['slug' => 'tietosuoja', 'title' => __('Tietosuoja', 'hppry')],
            ['slug' => 'saannot', 'title' => __('Säännöt', 'hppry')],
        ];

        $contentPages = [
            ['slug' => 'frontpage-hero', 'title' => 'Frontpage Hero', 'status' => 'draft'],
            ['slug' => 'frontpage-hero-en', 'title' => 'Frontpage Hero (EN)', 'status' => 'draft'],
            ['slug' => 'frontpage-social', 'title' => 'Frontpage Social', 'status' => 'draft'],
            ['slug' => 'frontpage-social-en', 'title' => 'Frontpage Social (EN)', 'status' => 'draft'],
            ['slug' => 'frontpage-video', 'title' => 'Frontpage Video', 'status' => 'draft'],
            ['slug' => 'frontpage-video-en', 'title' => 'Frontpage Video (EN)', 'status' => 'draft'],
            ['slug' => 'frontpage-facebook', 'title' => 'Frontpage Facebook', 'status' => 'draft'],
            ['slug' => 'frontpage-facebook-en', 'title' => 'Frontpage Facebook (EN)', 'status' => 'draft'],
            ['slug' => 'frontpage-news', 'title' => 'Frontpage News', 'status' => 'draft'],
            ['slug' => 'frontpage-news-en', 'title' => 'Frontpage News (EN)', 'status' => 'draft'],
            ['slug' => 'frontpage-overview', 'title' => 'Frontpage Overview', 'status' => 'draft'],
            ['slug' => 'frontpage-overview-en', 'title' => 'Frontpage Overview (EN)', 'status' => 'draft'],
            ['slug' => 'frontpage-contact', 'title' => 'Frontpage Contact', 'status' => 'draft'],
            ['slug' => 'frontpage-contact-en', 'title' => 'Frontpage Contact (EN)', 'status' => 'draft'],
            ['slug' => 'site-navigation', 'title' => 'Site Navigation', 'status' => 'draft'],
            ['slug' => 'site-footer', 'title' => 'Site Footer', 'status' => 'draft'],
            ['slug' => 'mission-page', 'title' => 'Mission Page', 'status' => 'draft'],
            ['slug' => 'mission-page-en', 'title' => 'Mission Page (EN)', 'status' => 'draft'],
            ['slug' => 'mission-page-activities', 'title' => 'Mission Page Activities', 'status' => 'draft'],
            ['slug' => 'mission-page-activities-en', 'title' => 'Mission Page Activities (EN)', 'status' => 'draft'],
            ['slug' => 'mission-page-principles', 'title' => 'Mission Page Principles', 'status' => 'draft'],
            ['slug' => 'mission-page-principles-en', 'title' => 'Mission Page Principles (EN)', 'status' => 'draft'],
            ['slug' => 'requests-page', 'title' => 'Information Requests Page', 'status' => 'draft'],
            ['slug' => 'requests-page-en', 'title' => 'Information Requests Page (EN)', 'status' => 'draft'],
            ['slug' => 'involvement-page', 'title' => 'Involvement Page', 'status' => 'draft'],
            ['slug' => 'involvement-page-en', 'title' => 'Involvement Page (EN)', 'status' => 'draft'],
            ['slug' => 'statutes-page', 'title' => 'Statutes Page', 'status' => 'draft'],
            ['slug' => 'statutes-page-en', 'title' => 'Statutes Page (EN)', 'status' => 'draft'],
            ['slug' => 'privacy-page', 'title' => 'Privacy Page', 'status' => 'draft'],
            ['slug' => 'privacy-page-en', 'title' => 'Privacy Page (EN)', 'status' => 'draft'],
        ];

        foreach ($publicPages as $page) {
            $this->create_page_if_missing($page['slug'], $page['title'], 'publish');
        }

        foreach ($contentPages as $page) {
            $this->create_page_if_missing($page['slug'], $page['title'], $page['status']);
        }
    }

    private function create_page_if_missing(string $slug, string $title, string $status = 'publish'): void {
        $existing = get_page_by_path($slug, OBJECT, 'page');

        if ($existing instanceof \WP_Post) {
            return;
        }

        wp_insert_post([
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => $status,
            'post_type' => 'page',
            'post_content' => '',
        ]);
    }
}

new HPPTheme();

