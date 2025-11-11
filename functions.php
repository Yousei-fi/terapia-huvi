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

    /**
     * Cached meta definitions for the editor sidebar.
     *
     * @var array<string, array>
     */
    private array $pageMetaDefinitions = [];

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
        add_action('init', [$this, 'register_meta_fields']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
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
                $post = \Timber\Timber::get_post($page);

                if ($post instanceof \Timber\Post) {
                    return $post;
                }
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
            ['slug' => 'frontpage-header', 'title' => 'Header Actions', 'status' => 'draft'],
            ['slug' => 'frontpage-hero', 'title' => 'Frontpage Hero', 'status' => 'draft'],
            ['slug' => 'frontpage-social', 'title' => 'Frontpage Social', 'status' => 'draft'],
            ['slug' => 'frontpage-video', 'title' => 'Frontpage Video', 'status' => 'draft'],
            ['slug' => 'frontpage-facebook', 'title' => 'Frontpage Facebook', 'status' => 'draft'],
            ['slug' => 'frontpage-news', 'title' => 'Frontpage News', 'status' => 'draft'],
            ['slug' => 'frontpage-overview', 'title' => 'Frontpage Overview', 'status' => 'draft'],
            ['slug' => 'frontpage-contact', 'title' => 'Frontpage Contact', 'status' => 'draft'],
            ['slug' => 'site-navigation', 'title' => 'Site Navigation', 'status' => 'draft'],
            ['slug' => 'site-footer', 'title' => 'Site Footer', 'status' => 'draft'],
            ['slug' => 'mission-page', 'title' => 'Mission Page', 'status' => 'draft'],
            ['slug' => 'mission-page-activities', 'title' => 'Mission Page Activities', 'status' => 'draft'],
            ['slug' => 'mission-page-principles', 'title' => 'Mission Page Principles', 'status' => 'draft'],
            ['slug' => 'requests-page', 'title' => 'Information Requests Page', 'status' => 'draft'],
            ['slug' => 'involvement-page', 'title' => 'Involvement Page', 'status' => 'draft'],
            ['slug' => 'statutes-page', 'title' => 'Statutes Page', 'status' => 'draft'],
            ['slug' => 'privacy-page', 'title' => 'Privacy Page', 'status' => 'draft'],
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

    public function register_meta_fields(): void {
        $definitions = $this->get_page_meta_definitions();

        if (empty($definitions)) {
            return;
        }

        $registered = [];

        foreach ($definitions as $definition) {
            foreach ($definition['fields'] as $field) {
                $key = $field['key'];

                if (isset($registered[$key])) {
                    continue;
                }

                register_post_meta(
                    'page',
                    $key,
                    [
                        'single' => true,
                        'type' => 'string',
                        'show_in_rest' => true,
                        'auth_callback' => static function (): bool {
                            return current_user_can('edit_pages');
                        },
                    ]
                );

                $registered[$key] = true;
            }
        }
    }

    public function enqueue_editor_assets(): void {
        if (!is_admin()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || 'page' !== $screen->post_type) {
            return;
        }

        $script_path = get_template_directory() . '/admin/page-meta-sidebar.js';

        if (!file_exists($script_path)) {
            return;
        }

        wp_enqueue_script(
            'hpp-page-meta-sidebar',
            get_template_directory_uri() . '/admin/page-meta-sidebar.js',
            ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'],
            (string) filemtime($script_path),
            true
        );

        wp_localize_script(
            'hpp-page-meta-sidebar',
            'hppPageMetaConfig',
            $this->prepare_editor_localization()
        );
    }

    private function prepare_editor_localization(): array {
        $config = [];

        foreach ($this->get_page_meta_definitions() as $slug => $definition) {
            $config[$slug] = [
                'title' => $definition['title'],
                'description' => $definition['description'] ?? '',
                'fields' => array_map(
                    static function (array $field): array {
                        return [
                            'key' => $field['key'],
                            'label' => $field['label'],
                            'type' => $field['type'] ?? 'text',
                            'help' => $field['help'] ?? '',
                            'rows' => $field['rows'] ?? 0,
                        ];
                    },
                    $definition['fields']
                ),
            ];
        }

        return $config;
    }

    private function get_page_meta_definitions(): array {
        if (!empty($this->pageMetaDefinitions)) {
            return $this->pageMetaDefinitions;
        }

        $richHelp = __('Supports basic HTML. Leave empty to fall back to the default copy.', 'hppry');
        $listHelp = __('Enter one item per line.', 'hppry');
        $pairHelp = __('Enter one item per line using “Title::Description”.', 'hppry');
        $cardHelp = __('Enter one item per line using “Title::Description::CTA label::URL”.', 'hppry');
        $navHelp = __('Enter one item per line using “Label::URL”. URLs can be relative (e.g. /missio) or full.', 'hppry');
        $urlHelp = __('Use a relative path (e.g. /osallistu) or a full URL.', 'hppry');
        $phoneHelp = __('Use the tel: format, e.g. tel:+358401234567.', 'hppry');

        $definitions = [];

        $definitions['frontpage-header'] = [
            'title' => __('Header actions', 'hppry'),
            'description' => __('Edit the call-to-action buttons and intro card in the masthead.', 'hppry'),
            'fields' => [
                $this->make_field('primary_cta_label_fi', __('Primary CTA label (FI)', 'hppry')),
                $this->make_field('primary_cta_label_en', __('Primary CTA label (EN)', 'hppry')),
                $this->make_field('primary_cta_url_fi', __('Primary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('primary_cta_url_en', __('Primary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('secondary_cta_label_fi', __('Secondary CTA label (FI)', 'hppry')),
                $this->make_field('secondary_cta_label_en', __('Secondary CTA label (EN)', 'hppry')),
                $this->make_field('secondary_cta_url_fi', __('Secondary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('secondary_cta_url_en', __('Secondary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('card_title_fi', __('Card title (FI)', 'hppry')),
                $this->make_field('card_title_en', __('Card title (EN)', 'hppry')),
                $this->make_field('card_copy_fi', __('Card copy (FI)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('card_copy_en', __('Card copy (EN)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('card_primary_cta_label_fi', __('Card primary CTA label (FI)', 'hppry')),
                $this->make_field('card_primary_cta_label_en', __('Card primary CTA label (EN)', 'hppry')),
                $this->make_field('card_primary_cta_url_fi', __('Card primary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('card_primary_cta_url_en', __('Card primary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('card_secondary_cta_label_fi', __('Card secondary CTA label (FI)', 'hppry')),
                $this->make_field('card_secondary_cta_label_en', __('Card secondary CTA label (EN)', 'hppry')),
                $this->make_field('card_secondary_cta_url_fi', __('Card secondary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('card_secondary_cta_url_en', __('Card secondary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
            ],
        ];

        $definitions['frontpage-hero'] = [
            'title' => __('Frontpage hero', 'hppry'),
            'description' => __('Content displayed in the hero section on the homepage.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Lead paragraph (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Lead paragraph (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('cta_primary_label_fi', __('Primary CTA label (FI)', 'hppry')),
                $this->make_field('cta_primary_label_en', __('Primary CTA label (EN)', 'hppry')),
                $this->make_field('cta_primary_url_fi', __('Primary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_primary_url_en', __('Primary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_secondary_label_fi', __('Secondary CTA label (FI)', 'hppry')),
                $this->make_field('cta_secondary_label_en', __('Secondary CTA label (EN)', 'hppry')),
                $this->make_field('cta_secondary_url_fi', __('Secondary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_secondary_url_en', __('Secondary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
            ],
        ];

        $definitions['frontpage-social'] = [
            'title' => __('Frontpage social intro', 'hppry'),
            'description' => __('Intro text for the social feed section.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('copy_fi', __('Description (FI)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('copy_en', __('Description (EN)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
            ],
        ];

        $definitions['frontpage-video'] = [
            'title' => __('YouTube spotlight', 'hppry'),
            'description' => __('Content for the YouTube highlight card.', 'hppry'),
            'fields' => [
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('copy_fi', __('Description (FI)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('copy_en', __('Description (EN)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('placeholder_text_fi', __('Placeholder text (FI)', 'hppry'), 'textarea', ['rows' => 2]),
                $this->make_field('placeholder_text_en', __('Placeholder text (EN)', 'hppry'), 'textarea', ['rows' => 2]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'hppry')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'hppry')),
            ],
        ];

        $definitions['frontpage-facebook'] = [
            'title' => __('Facebook spotlight', 'hppry'),
            'description' => __('Content for the Facebook highlight card.', 'hppry'),
            'fields' => [
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('copy_fi', __('Description (FI)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('copy_en', __('Description (EN)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('placeholder_text_fi', __('Placeholder text (FI)', 'hppry'), 'textarea', ['rows' => 2]),
                $this->make_field('placeholder_text_en', __('Placeholder text (EN)', 'hppry'), 'textarea', ['rows' => 2]),
                $this->make_field('primary_label_fi', __('Primary button label (FI)', 'hppry')),
                $this->make_field('primary_label_en', __('Primary button label (EN)', 'hppry')),
                $this->make_field('secondary_label_fi', __('Secondary link label (FI)', 'hppry')),
                $this->make_field('secondary_label_en', __('Secondary link label (EN)', 'hppry')),
            ],
        ];

        $definitions['frontpage-news'] = [
            'title' => __('News section', 'hppry'),
            'description' => __('Heading, description, and fallback message for the news grid.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'hppry')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'hppry')),
                $this->make_field('cta_url_fi', __('CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_url_en', __('CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('empty_message_fi', __('Empty state message (FI)', 'hppry'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
                $this->make_field('empty_message_en', __('Empty state message (EN)', 'hppry'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
            ],
        ];

        $definitions['frontpage-overview'] = [
            'title' => __('Impact highlights', 'hppry'),
            'description' => __('Cards that link deeper into your key content.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('cards_fi', __('Cards (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $cardHelp]),
                $this->make_field('cards_en', __('Cards (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $cardHelp]),
            ],
        ];

        $definitions['frontpage-contact'] = [
            'title' => __('Contact section', 'hppry'),
            'description' => __('Copy and labels for the contact details.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('copy_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('copy_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('general_contact_label_fi', __('General contact label (FI)', 'hppry')),
                $this->make_field('general_contact_label_en', __('General contact label (EN)', 'hppry')),
                $this->make_field('general_contact_email_fi', __('General contact email (FI)', 'hppry')),
                $this->make_field('general_contact_email_en', __('General contact email (EN)', 'hppry')),
                $this->make_field('chair_label_fi', __('Chair label (FI)', 'hppry')),
                $this->make_field('chair_label_en', __('Chair label (EN)', 'hppry')),
                $this->make_field('chair_value_fi', __('Chair value (FI)', 'hppry')),
                $this->make_field('chair_value_en', __('Chair value (EN)', 'hppry')),
                $this->make_field('vice_chair_label_fi', __('Vice chair label (FI)', 'hppry')),
                $this->make_field('vice_chair_label_en', __('Vice chair label (EN)', 'hppry')),
                $this->make_field('vice_chair_value_fi', __('Vice chair value (FI)', 'hppry')),
                $this->make_field('vice_chair_value_en', __('Vice chair value (EN)', 'hppry')),
                $this->make_field('board_members_label_fi', __('Board members label (FI)', 'hppry')),
                $this->make_field('board_members_label_en', __('Board members label (EN)', 'hppry')),
                $this->make_field('board_members_value_fi', __('Board members value (FI)', 'hppry'), 'textarea', ['rows' => 3]),
                $this->make_field('board_members_value_en', __('Board members value (EN)', 'hppry'), 'textarea', ['rows' => 3]),
                $this->make_field('social_heading_fi', __('Social heading (FI)', 'hppry')),
                $this->make_field('social_heading_en', __('Social heading (EN)', 'hppry')),
                $this->make_field('social_facebook_label_fi', __('Facebook label (FI)', 'hppry')),
                $this->make_field('social_facebook_label_en', __('Facebook label (EN)', 'hppry')),
                $this->make_field('social_x_label_fi', __('X / Twitter label (FI)', 'hppry')),
                $this->make_field('social_x_label_en', __('X / Twitter label (EN)', 'hppry')),
                $this->make_field('social_instagram_label_fi', __('Instagram label (FI)', 'hppry')),
                $this->make_field('social_instagram_label_en', __('Instagram label (EN)', 'hppry')),
                $this->make_field('social_youtube_label_fi', __('YouTube label (FI)', 'hppry')),
                $this->make_field('social_youtube_label_en', __('YouTube label (EN)', 'hppry')),
                $this->make_field('social_tiktok_label_fi', __('TikTok label (FI)', 'hppry')),
                $this->make_field('social_tiktok_label_en', __('TikTok label (EN)', 'hppry')),
                $this->make_field('holvi_label_fi', __('Holvi link label (FI)', 'hppry')),
                $this->make_field('holvi_label_en', __('Holvi link label (EN)', 'hppry')),
            ],
        ];

        $definitions['site-navigation'] = [
            'title' => __('Navigation defaults', 'hppry'),
            'description' => __('Fallback labels for the header navigation when no custom menu exists.', 'hppry'),
            'fields' => [
                $this->make_field('links_fi', __('Links (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $navHelp]),
                $this->make_field('links_en', __('Links (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $navHelp]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'hppry')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'hppry')),
                $this->make_field('cta_url_fi', __('CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_url_en', __('CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('toggle_label_fi', __('Mobile toggle label (FI)', 'hppry')),
                $this->make_field('toggle_label_en', __('Mobile toggle label (EN)', 'hppry')),
            ],
        ];

        $definitions['site-footer'] = [
            'title' => __('Footer content', 'hppry'),
            'description' => __('All copy blocks displayed in the footer.', 'hppry'),
            'fields' => [
                $this->make_field('about_text_fi', __('About text (FI)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('about_text_en', __('About text (EN)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('contact_heading_fi', __('Contact heading (FI)', 'hppry')),
                $this->make_field('contact_heading_en', __('Contact heading (EN)', 'hppry')),
                $this->make_field('contact_email_fi', __('Contact email (FI)', 'hppry')),
                $this->make_field('contact_email_en', __('Contact email (EN)', 'hppry')),
                $this->make_field('contact_address_fi', __('Contact address (FI)', 'hppry')),
                $this->make_field('contact_address_en', __('Contact address (EN)', 'hppry')),
                $this->make_field('contact_phone_fi', __('Contact phone (FI)', 'hppry')),
                $this->make_field('contact_phone_en', __('Contact phone (EN)', 'hppry')),
                $this->make_field('contact_phone_href_fi', __('Contact phone link (FI)', 'hppry'), 'text', ['help' => $phoneHelp]),
                $this->make_field('contact_phone_href_en', __('Contact phone link (EN)', 'hppry'), 'text', ['help' => $phoneHelp]),
                $this->make_field('links_heading_fi', __('Links heading (FI)', 'hppry')),
                $this->make_field('links_heading_en', __('Links heading (EN)', 'hppry')),
                $this->make_field('link_1_label_fi', __('Link 1 label (FI)', 'hppry')),
                $this->make_field('link_1_label_en', __('Link 1 label (EN)', 'hppry')),
                $this->make_field('link_1_url_fi', __('Link 1 URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_1_url_en', __('Link 1 URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_2_label_fi', __('Link 2 label (FI)', 'hppry')),
                $this->make_field('link_2_label_en', __('Link 2 label (EN)', 'hppry')),
                $this->make_field('link_2_url_fi', __('Link 2 URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_2_url_en', __('Link 2 URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_3_label_fi', __('Link 3 label (FI)', 'hppry')),
                $this->make_field('link_3_label_en', __('Link 3 label (EN)', 'hppry')),
                $this->make_field('link_3_url_fi', __('Link 3 URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_3_url_en', __('Link 3 URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_4_label_fi', __('Link 4 label (FI)', 'hppry')),
                $this->make_field('link_4_label_en', __('Link 4 label (EN)', 'hppry')),
                $this->make_field('link_4_url_fi', __('Link 4 URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_4_url_en', __('Link 4 URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('newsletter_heading_fi', __('Newsletter heading (FI)', 'hppry')),
                $this->make_field('newsletter_heading_en', __('Newsletter heading (EN)', 'hppry')),
                $this->make_field('newsletter_copy_fi', __('Newsletter description (FI)', 'hppry'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
                $this->make_field('newsletter_copy_en', __('Newsletter description (EN)', 'hppry'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
                $this->make_field('newsletter_label_fi', __('Newsletter field label (FI)', 'hppry')),
                $this->make_field('newsletter_label_en', __('Newsletter field label (EN)', 'hppry')),
                $this->make_field('newsletter_placeholder_fi', __('Newsletter placeholder (FI)', 'hppry')),
                $this->make_field('newsletter_placeholder_en', __('Newsletter placeholder (EN)', 'hppry')),
                $this->make_field('newsletter_button_fi', __('Newsletter button label (FI)', 'hppry')),
                $this->make_field('newsletter_button_en', __('Newsletter button label (EN)', 'hppry')),
                $this->make_field('social_facebook_label_fi', __('Footer Facebook label (FI)', 'hppry')),
                $this->make_field('social_facebook_label_en', __('Footer Facebook label (EN)', 'hppry')),
                $this->make_field('social_instagram_label_fi', __('Footer Instagram label (FI)', 'hppry')),
                $this->make_field('social_instagram_label_en', __('Footer Instagram label (EN)', 'hppry')),
                $this->make_field('social_linkedin_label_fi', __('Footer LinkedIn label (FI)', 'hppry')),
                $this->make_field('social_linkedin_label_en', __('Footer LinkedIn label (EN)', 'hppry')),
                $this->make_field('copyright_fi', __('Copyright (FI)', 'hppry')),
                $this->make_field('copyright_en', __('Copyright (EN)', 'hppry')),
            ],
        ];

        $definitions['mission-page'] = [
            'title' => __('Mission page copy', 'hppry'),
            'description' => __('Intro and focus highlights for the mission page.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('focus_heading_fi', __('Focus heading (FI)', 'hppry')),
                $this->make_field('focus_heading_en', __('Focus heading (EN)', 'hppry')),
                $this->make_field('focus_items_fi', __('Focus items (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('focus_items_en', __('Focus items (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
            ],
        ];

        $definitions['mission-page-activities'] = [
            'title' => __('Mission activities', 'hppry'),
            'description' => __('Content blocks describing focus areas.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('pillars_fi', __('Pillars (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('pillars_en', __('Pillars (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'hppry')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'hppry')),
                $this->make_field('cta_url_fi', __('CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_url_en', __('CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
            ],
        ];

        $definitions['mission-page-principles'] = [
            'title' => __('Mission principles', 'hppry'),
            'description' => __('Ten principles section on the mission page.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('theses_fi', __('Principles list (FI)', 'hppry'), 'textarea', ['rows' => 8, 'help' => $listHelp]),
                $this->make_field('theses_en', __('Principles list (EN)', 'hppry'), 'textarea', ['rows' => 8, 'help' => $listHelp]),
            ],
        ];

        $definitions['requests-page'] = [
            'title' => __('Information requests', 'hppry'),
            'description' => __('Archive of published information requests.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('entries_fi', __('Entry summaries (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('entries_en', __('Entry summaries (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('entry_1_items_fi', __('Entry 1 items (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_1_items_en', __('Entry 1 items (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_2_items_fi', __('Entry 2 items (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_2_items_en', __('Entry 2 items (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_3_items_fi', __('Entry 3 items (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_3_items_en', __('Entry 3 items (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_4_items_fi', __('Entry 4 items (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_4_items_en', __('Entry 4 items (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('footer_text_fi', __('Footer note (FI)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('footer_text_en', __('Footer note (EN)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
            ],
        ];

        $definitions['involvement-page'] = [
            'title' => __('Get involved page', 'hppry'),
            'description' => __('Volunteer opportunities and CTA labels.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('opportunities_fi', __('Opportunities (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $cardHelp]),
                $this->make_field('opportunities_en', __('Opportunities (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $cardHelp]),
                $this->make_field('cta_primary_label_fi', __('Primary CTA label (FI)', 'hppry')),
                $this->make_field('cta_primary_label_en', __('Primary CTA label (EN)', 'hppry')),
                $this->make_field('cta_primary_url_fi', __('Primary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_primary_url_en', __('Primary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_secondary_label_fi', __('Secondary CTA label (FI)', 'hppry')),
                $this->make_field('cta_secondary_label_en', __('Secondary CTA label (EN)', 'hppry')),
                $this->make_field('cta_secondary_url_fi', __('Secondary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_secondary_url_en', __('Secondary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_tertiary_label_fi', __('Tertiary CTA label (FI)', 'hppry')),
                $this->make_field('cta_tertiary_label_en', __('Tertiary CTA label (EN)', 'hppry')),
                $this->make_field('cta_tertiary_url_fi', __('Tertiary CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_tertiary_url_en', __('Tertiary CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('donation_text_fi', __('Donation note (FI)', 'hppry'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
                $this->make_field('donation_text_en', __('Donation note (EN)', 'hppry'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
            ],
        ];

        $definitions['statutes-page'] = [
            'title' => __('Statutes summary', 'hppry'),
            'description' => __('Key points and contact link for the statutes page.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('cards_fi', __('Cards (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('cards_en', __('Cards (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'hppry')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'hppry')),
                $this->make_field('cta_url_fi', __('CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_url_en', __('CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
            ],
        ];

        $definitions['privacy-page'] = [
            'title' => __('Privacy summary', 'hppry'),
            'description' => __('Key privacy copy and contact instructions.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('rights_heading_fi', __('Rights heading (FI)', 'hppry')),
                $this->make_field('rights_heading_en', __('Rights heading (EN)', 'hppry')),
                $this->make_field('rights_fi', __('Rights list (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $listHelp]),
                $this->make_field('rights_en', __('Rights list (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $listHelp]),
                $this->make_field('contact_heading_fi', __('Contact heading (FI)', 'hppry')),
                $this->make_field('contact_heading_en', __('Contact heading (EN)', 'hppry')),
                $this->make_field('contact_copy_fi', __('Contact instructions (FI)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('contact_copy_en', __('Contact instructions (EN)', 'hppry'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
            ],
        ];

        $this->pageMetaDefinitions = $definitions;

        return $this->pageMetaDefinitions;
    }

    private function make_field(string $key, string $label, string $type = 'text', array $extra = []): array {
        return array_merge(
            [
                'key' => $key,
                'label' => $label,
                'type' => $type,
            ],
            $extra
        );
    }
}

new HPPTheme();

