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

    /**
     * Cached map of default content values per page slug.
     *
     * @var array<string, array<string, string>>
     */
    private array $defaultContentCache = [];

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
        ];

        foreach ($publicPages as $page) {
            $this->create_page_if_missing($page['slug'], $page['title'], 'publish');
            $this->ensure_page_meta_defaults($page['slug']);
        }

        foreach ($contentPages as $page) {
            $this->create_page_if_missing($page['slug'], $page['title'], $page['status']);
            $this->ensure_page_meta_defaults($page['slug']);
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

    private function ensure_page_meta_defaults(string $slug): void {
        $defaults = $this->get_default_content_map();

        if (!isset($defaults[$slug])) {
            return;
        }

        $page = get_page_by_path($slug, OBJECT, 'page');

        if (!$page instanceof \WP_Post) {
            return;
        }

        foreach ($defaults[$slug] as $metaKey => $value) {
            $current = get_post_meta($page->ID, $metaKey, true);

            if ('' === $current || null === $current) {
                update_post_meta($page->ID, $metaKey, $value);
            }
        }
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
            $fields = array_map(
                function (array $field) use ($slug): array {
                    $default = $this->default_value($slug, $field['key']);

                    return [
                        'key' => $field['key'],
                        'label' => $field['label'],
                        'type' => $field['type'] ?? 'text',
                        'help' => $field['help'] ?? '',
                        'rows' => $field['rows'] ?? 0,
                        'default' => $default,
                        'value' => $this->editor_value($slug, $field['key']),
                    ];
                },
                $definition['fields']
            );

            $config[$slug] = [
                'title' => $definition['title'],
                'description' => $definition['description'] ?? '',
                'fields' => $fields,
            ];
        }

        return $config;
    }

    private function default_value(string $slug, string $key): string {
        $map = $this->get_default_content_map();

        return $map[$slug][$key] ?? '';
    }

    private function editor_value(string $slug, string $key): string {
        $page = get_page_by_path($slug, OBJECT, 'page');

        if ($page instanceof \WP_Post) {
            $value = get_post_meta($page->ID, $key, true);

            if ('' !== $value && null !== $value) {
                return (string) $value;
            }
        }

        return $this->default_value($slug, $key);
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

        $definitions['missio'] = [
            'title' => __('Mission page copy', 'hppry'),
            'description' => __('Intro, focus areas, and principles displayed on the mission page.', 'hppry'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Intro eyebrow (FI)', 'hppry')),
                $this->make_field('eyebrow_en', __('Intro eyebrow (EN)', 'hppry')),
                $this->make_field('title_fi', __('Intro heading (FI)', 'hppry')),
                $this->make_field('title_en', __('Intro heading (EN)', 'hppry')),
                $this->make_field('content_fi', __('Intro text (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('highlights_fi', __('Highlights (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $pairHelp]),
                $this->make_field('highlights_en', __('Highlights (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $pairHelp]),
                $this->make_field('focus_heading_fi', __('Focus heading (FI)', 'hppry')),
                $this->make_field('focus_heading_en', __('Focus heading (EN)', 'hppry')),
                $this->make_field('focus_items_fi', __('Focus items (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('focus_items_en', __('Focus items (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('activities_eyebrow_fi', __('Activities eyebrow (FI)', 'hppry')),
                $this->make_field('activities_eyebrow_en', __('Activities eyebrow (EN)', 'hppry')),
                $this->make_field('activities_title_fi', __('Activities heading (FI)', 'hppry')),
                $this->make_field('activities_title_en', __('Activities heading (EN)', 'hppry')),
                $this->make_field('activities_content_fi', __('Activities intro (FI)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('activities_content_en', __('Activities intro (EN)', 'hppry'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('activities_pillars_fi', __('Activities pillars (FI)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('activities_pillars_en', __('Activities pillars (EN)', 'hppry'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('activities_cta_label_fi', __('Activities CTA label (FI)', 'hppry')),
                $this->make_field('activities_cta_label_en', __('Activities CTA label (EN)', 'hppry')),
                $this->make_field('activities_cta_url_fi', __('Activities CTA URL (FI)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('activities_cta_url_en', __('Activities CTA URL (EN)', 'hppry'), 'text', ['help' => $urlHelp]),
                $this->make_field('theses_fi', __('Principles list (FI)', 'hppry'), 'textarea', ['rows' => 8, 'help' => $listHelp]),
                $this->make_field('theses_en', __('Principles list (EN)', 'hppry'), 'textarea', ['rows' => 8, 'help' => $listHelp]),
            ],
        ];

        $definitions['tietopyynnot'] = [
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

        $definitions['osallistu'] = [
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

        $definitions['saannot'] = [
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

        $definitions['tietosuoja'] = [
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

    private function get_default_content_map(): array {
        if (!empty($this->defaultContentCache)) {
            return $this->defaultContentCache;
        }

        $this->defaultContentCache = [
            'frontpage-header' => [
                'primary_cta_label_fi' => 'Liity jäseneksi',
                'primary_cta_label_en' => 'Become a member',
                'primary_cta_url_fi' => '/osallistu',
                'primary_cta_url_en' => '/osallistu?lang=en',
                'secondary_cta_label_fi' => 'Tutustu tietopyyntöihin',
                'secondary_cta_label_en' => 'Explore information requests',
                'secondary_cta_url_fi' => '/tietopyynnot',
                'secondary_cta_url_en' => '/tietopyynnot?lang=en',
                'card_title_fi' => 'Tarvitsemme jokaisen äänen',
                'card_title_en' => 'Every voice matters',
                'card_copy_fi' => 'HPP:n toiminta perustuu vapaaehtoisiin, jotka rakentavat inhimillistä päihdepolitiikkaa yhdessä. Tervetuloa mukaan vaikuttamaan, oppimaan ja jakamaan tietoa.',
                'card_copy_en' => 'Our movement thrives on volunteers who co-create humane drug policy. Join us to influence change, learn together, and share knowledge.',
                'card_primary_cta_label_fi' => 'Tee osasi',
                'card_primary_cta_label_en' => 'Get involved',
                'card_primary_cta_url_fi' => '/osallistu',
                'card_primary_cta_url_en' => '/osallistu?lang=en',
                'card_secondary_cta_label_fi' => 'Lue säännöt',
                'card_secondary_cta_label_en' => 'Read our statutes',
                'card_secondary_cta_url_fi' => '/saannot',
                'card_secondary_cta_url_en' => '/saannot?lang=en',
            ],
            'frontpage-hero' => [
                'eyebrow_fi' => 'Yhdessä humaanin päihdepolitiikan puolesta',
                'eyebrow_en' => 'Together for humane drug policy',
                'title_fi' => 'Rakennamme turvallista päihdepolitiikkaa ihmisarvo edellä',
                'title_en' => 'Building safer drug policy with dignity first',
                'content_fi' => '<p>HPP on vuonna 2001 perustettu päihdepoliittinen järjestö, joka kokoaa yhteen kokemusasiantuntijat, ammattilaiset ja päättäjät, jotta päihdepolitiikka perustuu tietoon ja vähentää haittoja. Olemme International Drug Policy Consortium -verkoston jäsen ja edistämme haittoja minimoivaa käyttöä, turvaavaa palvelujärjestelmää ja oikeudenmukaista politiikkaa.</p>',
                'content_en' => '<p>Founded in 2001, HPP brings together lived experts, professionals, and decision-makers to ensure drug policy is evidence-based and harm-reducing. As a member of the International Drug Policy Consortium, we champion informed use, resilient services, and fair policies.</p>',
                'cta_primary_label_fi' => 'Liity jäseneksi',
                'cta_primary_label_en' => 'Become a member',
                'cta_primary_url_fi' => '/osallistu',
                'cta_primary_url_en' => '/osallistu?lang=en',
                'cta_secondary_label_fi' => 'Lue ajankohtaista',
                'cta_secondary_label_en' => 'Latest updates',
                'cta_secondary_url_fi' => '#uutiset',
                'cta_secondary_url_en' => '#uutiset',
            ],
            'frontpage-social' => [
                'eyebrow_fi' => 'Seuraa ja osallistu',
                'eyebrow_en' => 'Stay connected',
                'title_fi' => 'Suora yhteys yhteisöön',
                'title_en' => 'Direct line to our community',
                'copy_fi' => 'Poimimme näkyville videomme ja sosiaalisen median syötteet, jotta pysyt mukana työssämme.',
                'copy_en' => 'Watch our latest videos and social feeds to stay close to the work in real time.',
            ],
            'frontpage-video' => [
                'title_fi' => 'YouTube-kanavamme',
                'title_en' => 'Our YouTube channel',
                'copy_fi' => 'Upotamme tähän HPP:n YouTube-syötteen, kun lisäosa on asennettu. Sillä välin voit katsoa uusimmat videot YouTube-kanavaltamme.',
                'copy_en' => 'We’ll embed our YouTube feed here once the integration is active. In the meantime, catch the latest videos on our channel.',
                'placeholder_text_fi' => 'Video-embed lisätään tähän, kun lisäosa otetaan käyttöön.',
                'placeholder_text_en' => 'The embedded feed will surface here once the integration is enabled.',
                'cta_label_fi' => 'Katso YouTubessa',
                'cta_label_en' => 'Watch on YouTube',
            ],
            'frontpage-facebook' => [
                'title_fi' => 'Facebook-syöte',
                'title_en' => 'Facebook feed',
                'copy_fi' => 'Facebook-syöte näkyy tässä heti, kun lisäosa on asennettu ja julkaisut on kytketty sivulle.',
                'copy_en' => 'The Facebook feed appears here as soon as the integration is active and connected.',
                'placeholder_text_fi' => 'Facebook-feed lisätään tähän myöhemmin lisäosan avulla.',
                'placeholder_text_en' => 'The Facebook feed will appear here once the integration is active.',
                'primary_label_fi' => 'Avaa Facebookissa',
                'primary_label_en' => 'Open on Facebook',
                'secondary_label_fi' => 'Seuraa myös X:ssä',
                'secondary_label_en' => 'Follow us on X',
            ],
            'frontpage-news' => [
                'eyebrow_fi' => 'Ajankohtaista',
                'eyebrow_en' => 'Latest',
                'title_fi' => 'Uutiset ja tarinat liikkeestämme',
                'title_en' => 'News and stories from our movement',
                'content_fi' => '<p>Kentältä, politiikasta ja vapaaehtoistyöstä kantautuvat kuulumiset kertovat työn vaikutuksesta. Lue uusimmat päivitykset ja tule mukaan muuttamaan suuntaa.</p>',
                'content_en' => '<p>Field updates, policy breakthroughs, and volunteer stories show the impact we create together. Dive into the latest highlights.</p>',
                'cta_label_fi' => 'Kaikki uutiset',
                'cta_label_en' => 'All articles',
                'cta_url_fi' => '/blogi',
                'cta_url_en' => '/blogi?lang=en',
                'empty_message_fi' => 'Tarinoita on tulossa pian – seuraa meitä somessa tai liity sähköpostilistalle pysyäksesi ajan tasalla.',
                'empty_message_en' => 'Fresh stories are on their way—follow us on social or join our newsletter to stay in the loop.',
            ],
            'frontpage-overview' => [
                'eyebrow_fi' => 'Tutustu syvemmin',
                'eyebrow_en' => 'Explore further',
                'title_fi' => 'Näin vaikutamme',
                'title_en' => 'Where our impact happens',
                'cards_fi' => implode("\n", [
                    'Missio::Miten rakennamme yhteiskuntaa, jossa apu on saavutettavaa ja inhimillistä.::Lue lisää::/missio',
                    'Teesit::Kymmenen periaatetta, jotka ohjaavat humaania päihdepolitiikkaamme.::Tutustu teesihin::/teesit',
                    'Tietopyynnöt::Avoimuutta viranomaistoimintaan – julkaistut tietopyynnöt ja vastaukset.::Avaa arkisto::/tietopyynnot',
                ]),
                'cards_en' => implode("\n", [
                    'Mission::See how we build services and policies that centre dignity.::Read the mission::/missio?lang=en',
                    'Principles::Ten principles that anchor humane drug policy reform.::View the principles::/missio?lang=en',
                    'Information requests::Transparency in practice—discover our published requests and responses.::Open the archive::/tietopyynnot?lang=en',
                ]),
            ],
            'frontpage-contact' => [
                'eyebrow_fi' => 'Yhteystiedot',
                'eyebrow_en' => 'Contact',
                'title_fi' => 'Ota yhteyttä',
                'title_en' => 'Get in touch',
                'copy_fi' => 'Tavoitat meidät nopeiten sähköpostitse. Hallituksen jäsenten osoitteet ovat muodossa etunimi.sukunimi@hppry.fi.',
                'copy_en' => 'Email is the fastest way to reach us. Board members use the format firstname.lastname@hppry.fi.',
                'general_contact_label_fi' => 'Yleinen yhteys:',
                'general_contact_label_en' => 'General inquiries:',
                'general_contact_email_fi' => 'hpp@hppry.fi',
                'general_contact_email_en' => 'hpp@hppry.fi',
                'chair_label_fi' => 'Puheenjohtaja:',
                'chair_label_en' => 'Chairperson:',
                'chair_value_fi' => 'Timo Pasanen',
                'chair_value_en' => 'Timo Pasanen',
                'vice_chair_label_fi' => 'Varapuheenjohtaja:',
                'vice_chair_label_en' => 'Vice Chair:',
                'vice_chair_value_fi' => 'Tiina Poutiainen',
                'vice_chair_value_en' => 'Tiina Poutiainen',
                'board_members_label_fi' => 'Hallituksen jäsenet:',
                'board_members_label_en' => 'Board members:',
                'board_members_value_fi' => 'Aleksi Hupli, Miina Kajos, Mikko Neuvo',
                'board_members_value_en' => 'Aleksi Hupli, Miina Kajos, Mikko Neuvo',
                'social_heading_fi' => 'Seuraa meitä',
                'social_heading_en' => 'Follow us',
                'social_facebook_label_fi' => 'Facebook',
                'social_facebook_label_en' => 'Facebook',
                'social_x_label_fi' => 'X (Twitter)',
                'social_x_label_en' => 'X (Twitter)',
                'social_instagram_label_fi' => 'Instagram',
                'social_instagram_label_en' => 'Instagram',
                'social_youtube_label_fi' => 'YouTube',
                'social_youtube_label_en' => 'YouTube',
                'social_tiktok_label_fi' => 'TikTok',
                'social_tiktok_label_en' => 'TikTok',
                'holvi_label_fi' => 'Holvi-verkkokauppa',
                'holvi_label_en' => 'Holvi web shop',
            ],
            'site-navigation' => [
                'links_fi' => implode("\n", [
                    'Missio::/missio',
                    'Tietopyynnöt::/tietopyynnot',
                    'Osallistu::/osallistu',
                    'Tietosuoja::/tietosuoja',
                ]),
                'links_en' => implode("\n", [
                    'Mission::/missio?lang=en',
                    'Information requests::/tietopyynnot?lang=en',
                    'Get involved::/osallistu?lang=en',
                    'Privacy::/tietosuoja?lang=en',
                ]),
                'cta_label_fi' => 'Liity mukaan',
                'cta_label_en' => 'Join the movement',
                'cta_url_fi' => '/osallistu',
                'cta_url_en' => '/osallistu?lang=en',
                'toggle_label_fi' => 'Avaa valikko',
                'toggle_label_en' => 'Open menu',
            ],
            'site-footer' => [
                'about_text_fi' => 'Edistämme humaania päihdepolitiikkaa, tuemme yhteisöjä ja rakennamme turvallisempaa tulevaisuutta tutkittuun tietoon nojaten.',
                'about_text_en' => 'We advance humane drug policy, support communities, and build safer futures grounded in evidence.',
                'contact_heading_fi' => 'Yhteystiedot',
                'contact_heading_en' => 'Contact',
                'contact_email_fi' => 'hpp@hppry.fi',
                'contact_email_en' => 'hpp@hppry.fi',
                'contact_address_fi' => 'Hämeentie 34, 00530 Helsinki',
                'contact_address_en' => 'Hämeentie 34, 00530 Helsinki, Finland',
                'contact_phone_fi' => '+358 40 123 4567',
                'contact_phone_en' => '+358 40 123 4567',
                'contact_phone_href_fi' => 'tel:+358401234567',
                'contact_phone_href_en' => 'tel:+358401234567',
                'links_heading_fi' => 'Tärkeimmät sivut',
                'links_heading_en' => 'Key pages',
                'link_1_label_fi' => 'Missiomme',
                'link_1_label_en' => 'Mission',
                'link_1_url_fi' => '/missio',
                'link_1_url_en' => '/missio?lang=en',
                'link_2_label_fi' => 'Toiminta',
                'link_2_label_en' => 'Get involved',
                'link_2_url_fi' => '/osallistu',
                'link_2_url_en' => '/osallistu?lang=en',
                'link_3_label_fi' => 'Tietopyynnöt',
                'link_3_label_en' => 'Information requests',
                'link_3_url_fi' => '/tietopyynnot',
                'link_3_url_en' => '/tietopyynnot?lang=en',
                'link_4_label_fi' => 'Tietosuoja',
                'link_4_label_en' => 'Privacy',
                'link_4_url_fi' => '/tietosuoja',
                'link_4_url_en' => '/tietosuoja?lang=en',
                'newsletter_heading_fi' => 'Pysy ajan tasalla',
                'newsletter_heading_en' => 'Stay in the loop',
                'newsletter_copy_fi' => 'Saat kuukausittain päivityksiä politiikkamuutoksista, yhteisön tarinoista ja vapaaehtoistehtävistä.',
                'newsletter_copy_en' => 'Receive monthly policy updates, community stories, and volunteer opportunities.',
                'newsletter_label_fi' => 'Sähköpostiosoite',
                'newsletter_label_en' => 'Email address',
                'newsletter_placeholder_fi' => 'sina@esimerkki.fi',
                'newsletter_placeholder_en' => 'you@example.com',
                'newsletter_button_fi' => 'Tilaa uutiskirje',
                'newsletter_button_en' => 'Subscribe',
                'social_facebook_label_fi' => 'Facebook',
                'social_facebook_label_en' => 'Facebook',
                'social_instagram_label_fi' => 'Instagram',
                'social_instagram_label_en' => 'Instagram',
                'social_linkedin_label_fi' => 'LinkedIn',
                'social_linkedin_label_en' => 'LinkedIn',
                'copyright_fi' => 'Kaikki oikeudet pidätetään.',
                'copyright_en' => 'All rights reserved.',
            ],
            'missio' => [
                'eyebrow_fi' => 'Missiomme',
                'eyebrow_en' => 'Our mission',
                'title_fi' => 'Ihmisarvoinen päihdepolitiikka syntyy kuuntelemalla',
                'title_en' => 'Humane drug policy grows from listening',
                'content_fi' => '<p>Teemme vaikuttamistyötä ruohonjuuresta eduskuntaan. Yhdistämme tutkimustiedon ja kokemuksen, jotta palvelut, säädökset ja asenteet tukevat ihmistä – eivät rankaise.</p>',
                'content_en' => '<p>From grassroots to parliament, we connect evidence with lived experience so that services, legislation, and attitudes support people rather than punish them.</p>',
                'highlights_fi' => implode("\n", [
                    'Poliittinen vaikuttaminen::Laadimme muistioita, kannanottoja ja verkostoyhteistyötä, jotta päätöksenteko perustuu parhaaseen tietoon.',
                    'Yhteisöjen tukeminen::Vahvistamme vertaistukea, kriisiapua ja perheiden palveluita, joissa kohtaaminen on lempeää.',
                    'Tutkimus ja tieto::Käännämme tutkimustiedon ymmärrettäviksi materiaaleiksi kampanjoihin, koulutuksiin ja päätöksenteon tueksi.',
                ]),
                'highlights_en' => implode("\n", [
                    'Policy advocacy::We prepare briefs, statements, and alliances so that decision-making rests on the best available evidence.',
                    'Supporting communities::We strengthen peer support, crisis help, and family services built on compassionate encounters.',
                    'Research and insight::We translate research into accessible materials for campaigns, training, and policy briefs.',
                ]),
                'focus_heading_fi' => 'Painopisteet',
                'focus_heading_en' => 'Strategic priorities',
                'focus_items_fi' => implode("\n", [
                    'Kiertävä haittoja vähentävä tukikiertue yhdessä paikallisten toimijoiden kanssa.',
                    'Hyvät käytännöt kunnille: yhteiset pelisäännöt inhimilliseen palveluohjaukseen.',
                    'Vertaistoimijoiden koulutusverkosto kaikkiin maakuntiin.',
                ]),
                'focus_items_en' => implode("\n", [
                    'A touring harm reduction roadshow co-created with local partners.',
                    'Shared playbooks for municipalities to build compassionate service pathways.',
                    'A nationwide training network for peer advocates.',
                ]),
                'activities_eyebrow_fi' => 'Toimintamme',
                'activities_eyebrow_en' => 'Our work',
                'activities_title_fi' => 'Vahvistamme osaamista, hyvinvointia ja yhteisöllisyyttä',
                'activities_title_en' => 'We strengthen skills, wellbeing, and community',
                'activities_content_fi' => '<p>Jokainen ohjelmamme syntyy yhteistyössä niiden kanssa, joita päihdepolitiikka koskettaa. Siten luomme ratkaisuja, jotka kestävät arjessa ja päätöksenteossa.</p>',
                'activities_content_en' => '<p>Every programme is co-designed with the people most affected by drug policy, creating solutions that endure in everyday life and decision-making.</p>',
                'activities_pillars_fi' => implode("\n", [
                    'Haittoja vähentävät palvelut::Liikkuvat yksiköt ja turvalliset kohtaamispaikat tuovat tuen, välineet ja terveyspalvelut lähelle.',
                    'Perheiden tuki::Vertaistapaamiset ja ohjattu tuki auttavat läheisiä kohtaamaan päihteiden käyttöä rakentavasti.',
                    'Politiikkavaikuttaminen::Seuraamme lainsäädäntöä, julkaisemme muistioita ja käynnistämme keskusteluja vaihtoehtoisista malleista.',
                ]),
                'activities_pillars_en' => implode("\n", [
                    'Harm reduction services::Mobile units and safe spaces connect people with support, supplies, and health services.',
                    'Support for families::Peer gatherings and guided support help loved ones respond constructively to drug use.',
                    'Policy advocacy::We monitor legislation, publish briefs, and spark dialogue on alternative policy models.',
                ]),
                'activities_cta_label_fi' => 'Tutustu tarkemmin',
                'activities_cta_label_en' => 'Explore the work',
                'activities_cta_url_fi' => '/toiminta',
                'activities_cta_url_en' => '/osallistu?lang=en',
                'theses_fi' => implode("\n", [
                    'Päihdepolitiikan ja -työn on perustuttava parhaaseen saatavilla olevaan tietoon keinojen vaikuttavuudesta.',
                    'Päihteitä käytetään lähes kaikissa yhteiskunnissa. Koska käyttöä ei voida täysin estää, sitä tulee ohjata vastuulliseen ja haittoja vähentävään suuntaan.',
                    'Päihteiden ongelmakäyttöä on ehkäistävä tunnistamalla ja korjaamalla taustalla vaikuttavia yhteiskunnallisia ja yksilöllisiä tekijöitä.',
                    'Apua tarvitseville on taattava inhimillinen ja tutkitusti vaikuttava hoito sekä kuntoutus, jossa psykososiaalinen tuki täydentää lääkehoitoa.',
                    'On huomioitava, että suurin osa käytöstä ei ole ongelmakäyttöä. Päätöksenteossa on huomioitava myös kohtuullinen käyttö.',
                    'Käyttäjiin kohdistuvaa negatiivista asenneilmapiiriä on purettava, sillä leimaaminen lisää haittoja ja vaikeuttaa avun saantia.',
                    'Päihteiden käytön rangaistavuudesta on luovuttava, koska rangaistukset lisäävät haittoja eivätkä vähennä käyttöä.',
                    'Huumemarkkinoiden jättäminen rikollisille on kestämätön ratkaisu – vaihtoehtoja on tarkasteltava avoimesti.',
                    'Päihdepolitiikan keinojen on kunnioitettava ihmisoikeuksia ja huomioitava globaalit vaikutukset vastuullisesti.',
                    'Etsimme yhdessä eri toimijoiden kanssa keinoja päihdepoliittisen reformin toteuttamiseksi.',
                ]),
                'theses_en' => implode("\n", [
                    'Drug policy and practice must be grounded in the best available evidence of what reduces harm.',
                    'Because drug use exists in nearly every society, our task is to steer it toward responsibility and harm reduction rather than punishment.',
                    'Preventing problematic use requires addressing the social and individual factors that shape vulnerability.',
                    'People seeking help deserve humane, evidence-based care that combines psychosocial support with medical treatment.',
                    'Most use is non-problematic—policy must recognise this as it balances public health and liberty.',
                    'Stigma fuels harm and blocks access to help; dismantling it is essential.',
                    'Criminalising use increases harm and fails to reduce consumption; penalties must be replaced with support.',
                    'Leaving drug markets to organised crime is unsustainable—regulated alternatives must be explored openly.',
                    'Drug policy tools must respect human rights and consider global impacts responsibly.',
                    'We work with diverse partners to realise meaningful drug policy reform.',
                ]),
            ],
            'tietopyynnot' => [
                'eyebrow_fi' => 'Tietopyynnöt',
                'eyebrow_en' => 'Information requests',
                'title_fi' => 'Tehdyt tietopyynnöt ja dokumentoitu tieto',
                'title_en' => 'Published requests and documented knowledge',
                'content_fi' => '<p>Julkaisemme viranomaisille tekemämme, julkisuuslakiin perustuvat tietopyynnöt ja niihin saadut vastaukset, jotta tieto olisi avoimesti hyödynnettävissä. Päivitämme listaa aina uusien vastausten valmistuessa.</p>',
                'content_en' => '<p>We publish the freedom-of-information requests we file with authorities and the responses we receive, keeping knowledge open for everyone. The archive grows as new replies arrive.</p>',
                'entries_fi' => implode("\n", [
                    'Seuraukset epäillylle ja tuomitulle::Turvallisuusselvitysten, rikostaustaotteiden ja rekisterimerkintöjen vaikutukset käyttö- ja huumausainerikoksissa.',
                    'Ajoterveys ja turvallisuus::Päihteiden vaikutus ajokuntoon, ajokorttiluvat ja poliisin velvollisuudet liikenneturvallisuuden varmistamisessa.',
                    'Rikoksen vakavuus ja ohjeistukset::Poliisin ja syyttäjän ohjeet, prioriteettilistat sekä oikeuskäytäntö koskien huumausaineiden vaarallisuutta.',
                    'Tilastot ja selvitykset::Tilastokeskuksen, poliisin ja tullin aineistot, jotka valottavat päihdepolitiikan vaikutuksia.',
                ]),
                'entries_en' => implode("\n", [
                    'Impacts on suspects and convicts::Security clearances, criminal records, and database entries linked to use and drug offences.',
                    'Driving fitness and safety::How substances affect driving, licensing decisions, and police obligations in traffic safety.',
                    'Severity and prosecutorial guidance::Police and prosecutor guidelines, priority lists, and case law on drug offence seriousness.',
                    'Statistics and assessments::Datasets from Statistics Finland, police, and customs revealing policy impacts and needs.',
                ]),
                'entry_1_items_fi' => implode("\n", [
                    'Huumausaineen käyttörikos turvallisuusselvityksessä (Suojelupoliisi, 2017)',
                    'Huumausaineen käyttörikos rikostaustaotteessa (Oikeusrekisterikeskus, 2017)',
                    'Merkinnät poliisin rekistereissä ja lastensuojeluilmoitukset (Poliisihallitus, 2020)',
                ]),
                'entry_1_items_en' => implode("\n", [
                    'Drug use offences in security clearances (Finnish Security Intelligence Service, 2017)',
                    'Drug use offences on criminal record extracts (Legal Register Centre, 2017)',
                    'Police registers and child welfare notifications (National Police Board, 2020)',
                ]),
                'entry_2_items_fi' => implode("\n", [
                    'Päihteiden vaikutus ajokuntoon (THL, 2018)',
                    'Ajokieltojen perusteet ja palauttaminen (Poliisihallitus, 2018–2019)',
                    'Ajoterveyden lausuntomääräykset (Poliisihallitus, 2019)',
                ]),
                'entry_2_items_en' => implode("\n", [
                    'Impact of substances on driving (THL, 2018)',
                    'Grounds and reinstatement of driving bans (National Police Board, 2018–2019)',
                    'Guidelines for medical statements on driving fitness (National Police Board, 2019)',
                ]),
                'entry_3_items_fi' => implode("\n", [
                    'Poliisin ohjeet huumausaineasioissa (Poliisihallitus, 2018)',
                    'Huumausaineiden vaarallisuutta koskevat lausunnot (KRP, THL, Fimea, 2017)',
                    'Prioriteettilistat rikosnimikkeistä (Poliisihallitus, 2019)',
                ]),
                'entry_3_items_en' => implode("\n", [
                    'Police guidelines for drug-related cases (National Police Board, 2018)',
                    'Expert statements on drug harmfulness (NBI, THL, Fimea, 2017)',
                    'Priority lists of offence categories (National Police Board, 2019)',
                ]),
                'entry_4_items_fi' => implode("\n", [
                    'Käyttörikosten määrät ja tekijät (Poliisiammattikorkeakoulu, 2018)',
                    'Huumetakavarikot aineittain (KRP, 2019)',
                    'Liikennejuopumustilastot (KRP, 2015–2020)',
                ]),
                'entry_4_items_en' => implode("\n", [
                    'Quantities and profiles of use offences (Police University College, 2018)',
                    'Drug seizures by substance (NBI, 2019)',
                    'Traffic intoxication statistics (NBI, 2015–2020)',
                ]),
                'footer_text_fi' => '<p>Ajantasaiset tietopyynnöt ja vastaukset julkaistaan tietopyynto.fi-palvelussa. Koko listan saat pyytämällä meiltä: <a href="mailto:hpp@hppry.fi" class="text-brand-orange hover:text-brand-orange/80">hpp@hppry.fi</a>.</p>',
                'footer_text_en' => '<p>Current requests and responses appear on tietopyynto.fi. For the full archive, drop us a line: <a href="mailto:hpp@hppry.fi" class="text-brand-orange hover:text-brand-orange/80">hpp@hppry.fi</a>.</p>',
            ],
            'osallistu' => [
                'eyebrow_fi' => 'Tue ja osallistu',
                'eyebrow_en' => 'Support & participate',
                'title_fi' => 'Humaania päihdepolitiikkaa ry toimii vapaaehtoisvoimin',
                'title_en' => 'Humane Drug Policy Association runs on volunteer power',
                'content_fi' => '<p>Toimintamme rahoitetaan pääosin jäsenmaksuilla, lahjoituksilla ja verkkokaupan tuotoilla. Jos pidät parempaa päihdepolitiikkaa tärkeänä, voit tukea työtämme liittymällä jäseneksi tai osallistumalla vapaaehtoiseksi. Tarvitsemme kirjoittajia, kuvaajia, kouluttajia, vaikuttajia ja järjestäjiä – ennen kaikkea ihmisiä, jotka haluavat rakentaa inhimillisempää yhteiskuntaa.</p>',
                'content_en' => '<p>Membership fees, donations, and our online shop fund most of our work. If a more humane drug policy matters to you, join as a member or contribute your skills. We welcome writers, photographers, trainers, advocates, organisers—anyone committed to building a kinder society.</p>',
                'opportunities_fi' => implode("\n", [
                    'Vapaaehtoistehtävät::Tule mukaan viestintään, tapahtumiin, vertaistukeen tai kehittämistyöhön – koulutamme ja tuemme tehtävässä.',
                    'Yritysyhteistyö::Etsimme kumppaneita, joiden arvot kohtaavat omamme. Yhdessä voimme muuttaa rakenteita.',
                    'Vaikuttajaverkosto::Liity politiikan, tutkimuksen ja palveluiden ammattilaisista koostuvaan verkostoomme.',
                ]),
                'opportunities_en' => implode("\n", [
                    'Volunteer roles::Join our communications, events, peer support, or development teams—we train and support you throughout.',
                    'Partnerships::We collaborate with organisations that share our values to shift structures together.',
                    'Advocacy network::Connect with professionals across policy, research, and services to drive reform.',
                ]),
                'cta_primary_label_fi' => 'Verkkokauppa & jäsenyydet',
                'cta_primary_label_en' => 'Online shop & membership',
                'cta_primary_url_fi' => 'https://holvi.com/shop/hpp-ry/',
                'cta_primary_url_en' => 'https://holvi.com/shop/hpp-ry/',
                'cta_secondary_label_fi' => 'Liity jäseneksi',
                'cta_secondary_label_en' => 'Join as a member',
                'cta_secondary_url_fi' => 'https://holvi.com/shop/hpp-ry/',
                'cta_secondary_url_en' => 'https://holvi.com/shop/hpp-ry/',
                'cta_tertiary_label_fi' => 'Tutustu sääntöihin',
                'cta_tertiary_label_en' => 'Read the statutes',
                'cta_tertiary_url_fi' => '/saannot',
                'cta_tertiary_url_en' => '/saannot?lang=en',
                'donation_text_fi' => 'Tilinumero lahjoituksia varten: <span class="font-semibold text-white">IBAN FI40 7997 7997 1034 99</span>, BIC HOLVFIHH.',
                'donation_text_en' => 'Donation account: <span class="font-semibold text-white">IBAN FI40 7997 7997 1034 99</span>, BIC HOLVFIHH.',
            ],
            'saannot' => [
                'eyebrow_fi' => 'Säännöt',
                'eyebrow_en' => 'Statutes',
                'title_fi' => 'Yhdistyksen säännöt tiivistettynä',
                'title_en' => 'Summary of association statutes',
                'content_fi' => '<p>Tutustu yhdistyksen virallisiin sääntöihin ennen jäseneksi liittymistä. Alla on yhteenveto keskeisistä kohdista; täydellinen sääntöteksti on saatavilla pyynnöstä.</p>',
                'content_en' => '<p>Review the key points of our official statutes before joining. The full text is available on request.</p>',
                'cards_fi' => implode("\n", [
                    '1–3. Tarkoitus ja jäsenyys::HPP:n tarkoitus on vähentää päihteiden käytön haittoja, kehittää palveluja ja edistää tietoon perustuvaa päihdepolitiikkaa. Jäsenyys on avoin kaikille tarkoituksen hyväksyville.',
                    '4–7. Hallinto ja kokoukset::Hallitus hoitaa yhdistyksen asioita, ja sen toimikausi on kalenterivuosi. Kevät- ja syyskokouksissa päätetään toiminnan suuntaviivoista.',
                    '8–9. Koolle kutsuminen::Kokoukset kutsutaan koolle vähintään 14 vuorokautta etukäteen sähköpostitse.',
                    '10. Sääntömuutokset::Sääntöjen muuttaminen vaatii 3/4 enemmistön. Yhdistyksen purkamisesta päätetään kahdessa peräkkäisessä kokouksessa.',
                ]),
                'cards_en' => implode("\n", [
                    '1–3. Purpose and membership::The association reduces harms from drug use and advances evidence-based policy. Membership is open to anyone who supports our purpose.',
                    '4–7. Governance and meetings::The board manages operations for the calendar year. Spring and autumn meetings set strategy and elect leadership.',
                    '8–9. Meeting notices::General meetings are called at least 14 days in advance via email.',
                    '10. Amendments::Changing the statutes requires a three-fourths majority. Dissolution decisions are made in two consecutive meetings.',
                ]),
                'cta_label_fi' => 'Pyydä täydelliset säännöt sähköpostitse',
                'cta_label_en' => 'Request the full statutes by email',
                'cta_url_fi' => 'mailto:hpp@hppry.fi',
                'cta_url_en' => 'mailto:hpp@hppry.fi',
            ],
            'tietosuoja' => [
                'eyebrow_fi' => 'Tietosuoja',
                'eyebrow_en' => 'Privacy',
                'title_fi' => 'Rekisteriseloste',
                'title_en' => 'Data protection summary',
                'content_fi' => '<p>HPP ylläpitää jäsenistöstään ja vapaaehtoisistaan rekisteriä, jota käytetään jäsenyyteen liittyvien oikeuksien varmistamiseen, viestintään ja tapahtumien koordinointiin. Tietoja säilytetään suojatuissa järjestelmissä ja poistetaan viimeistään kolmen vuoden kuluttua jäsenyyden päättymisestä.</p>',
                'content_en' => '<p>HPP maintains a register of members and volunteers to safeguard membership rights, coordinate communications, and organise events. Data is stored securely and deleted no later than three years after membership lapses.</p>',
                'rights_heading_fi' => 'Rekisteröidyn oikeudet',
                'rights_heading_en' => 'Your rights',
                'rights_fi' => implode("\n", [
                    'Oikeus tarkastaa, oikaista ja poistaa omat tietonsa.',
                    'Oikeus rajoittaa käsittelyä sekä vastustaa profilointia.',
                    'Oikeus siirtää tiedot järjestelmästä toiseen.',
                ]),
                'rights_en' => implode("\n", [
                    'Access, rectify, and erase your personal data.',
                    'Restrict processing and object to profiling.',
                    'Request data portability to another system.',
                ]),
                'contact_heading_fi' => 'Tietosuojaan liittyvät yhteydenotot',
                'contact_heading_en' => 'Contact for privacy matters',
                'contact_copy_fi' => '<p>Lähetä allekirjoitettu pyyntö osoitteeseen <a href="mailto:hpp@hppry.fi" class="text-brand-orange hover:text-brand-orange/80">hpp@hppry.fi</a>. Käsittelemme pyynnöt kuukauden kuluessa.</p>',
                'contact_copy_en' => '<p>Send a signed request to <a href="mailto:hpp@hppry.fi" class="text-brand-orange hover:text-brand-orange/80">hpp@hppry.fi</a>. We respond within one month.</p>',
            ],
        ];

        return $this->defaultContentCache;
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

