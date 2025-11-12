<?php

require_once __DIR__ . '/vendor/autoload.php';

\Timber\Timber::init();

use Twig\TwigFunction;

class TerapiaHuviTheme extends \Timber\Site {
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
            'main_menu' => __('Main Menu', 'terapia-huvi'),
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
            'terapia-huvi-style',
            get_template_directory_uri() . '/dist/style.css',
            [],
            $theme_version
        );

        wp_enqueue_script(
            'terapia-huvi-js',
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
                'label' => __('Suomeksi', 'terapia-huvi'),
            ],
            [
                'code' => 'en',
                'label' => __('In English', 'terapia-huvi'),
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
        $seededAt = get_option('terapia_huvi_seeded_pages');
        $needsRefresh = $this->has_missing_required_pages();

        if (!$seededAt || $needsRefresh) {
            $this->seed_required_pages();
            update_option('terapia_huvi_seeded_pages', time());
        }
    }

    public function seed_required_pages(): void {
        foreach ($this->get_public_page_definitions() as $page) {
            $this->create_page_if_missing($page['slug'], $page['title'], 'publish');
            $this->ensure_page_meta_defaults($page['slug']);
        }

        foreach ($this->get_content_page_definitions() as $page) {
            $this->create_page_if_missing($page['slug'], $page['title'], $page['status']);
            $this->ensure_page_meta_defaults($page['slug']);
        }
    }

    private function has_missing_required_pages(): bool {
        foreach (array_merge($this->get_public_page_definitions(), $this->get_content_page_definitions()) as $page) {
            $existing = get_page_by_path($page['slug'], OBJECT, 'page');

            if (!$existing instanceof \WP_Post) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{slug: string, title: string}>
     */
    private function get_public_page_definitions(): array {
        return [
            ['slug' => 'terapia', 'title' => __('Terapia', 'terapia-huvi')],
            ['slug' => 'hinnasto', 'title' => __('Hinnasto', 'terapia-huvi')],
            ['slug' => 'minusta', 'title' => __('Minusta', 'terapia-huvi')],
            ['slug' => 'yhteystiedot', 'title' => __('Yhteystiedot', 'terapia-huvi')],
            ['slug' => 'lahjakortit', 'title' => __('Lahjakortit', 'terapia-huvi')],
            ['slug' => 'tietosuoja', 'title' => __('Tietosuoja', 'terapia-huvi')],
        ];
    }

    /**
     * @return array<int, array{slug: string, title: string, status: string}>
     */
    private function get_content_page_definitions(): array {
        return [
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
            'terapia-huvi-page-meta-sidebar',
            get_template_directory_uri() . '/admin/page-meta-sidebar.js',
            ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'],
            (string) filemtime($script_path),
            true
        );

        wp_localize_script(
            'terapia-huvi-page-meta-sidebar',
            'terapiaHuviPageMetaConfig',
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

        $richHelp = __('Supports basic HTML. Leave empty to fall back to the default copy.', 'terapia-huvi');
        $listHelp = __('Enter one item per line.', 'terapia-huvi');
        $pairHelp = __('Enter one item per line using “Title::Description”.', 'terapia-huvi');
        $cardHelp = __('Enter one item per line using “Title::Description::CTA label::URL”.', 'terapia-huvi');
        $navHelp = __('Enter one item per line using “Label::URL”. URLs can be relative (e.g. /missio) or full.', 'terapia-huvi');
        $urlHelp = __('Use a relative path (e.g. /terapia) or a full URL.', 'terapia-huvi');
        $phoneHelp = __('Use the tel: format, e.g. tel:+358401234567.', 'terapia-huvi');

        $definitions = [];

        $definitions['frontpage-header'] = [
            'title' => __('Header actions', 'terapia-huvi'),
            'description' => __('Edit the call-to-action buttons and intro card in the masthead.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('primary_cta_label_fi', __('Primary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('primary_cta_label_en', __('Primary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('primary_cta_url_fi', __('Primary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('primary_cta_url_en', __('Primary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('secondary_cta_label_fi', __('Secondary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('secondary_cta_label_en', __('Secondary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('secondary_cta_url_fi', __('Secondary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('secondary_cta_url_en', __('Secondary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('card_title_fi', __('Card title (FI)', 'terapia-huvi')),
                $this->make_field('card_title_en', __('Card title (EN)', 'terapia-huvi')),
                $this->make_field('card_copy_fi', __('Card copy (FI)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('card_copy_en', __('Card copy (EN)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('card_primary_cta_label_fi', __('Card primary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('card_primary_cta_label_en', __('Card primary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('card_primary_cta_url_fi', __('Card primary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('card_primary_cta_url_en', __('Card primary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('card_secondary_cta_label_fi', __('Card secondary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('card_secondary_cta_label_en', __('Card secondary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('card_secondary_cta_url_fi', __('Card secondary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('card_secondary_cta_url_en', __('Card secondary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
            ],
        ];

        $definitions['frontpage-hero'] = [
            'title' => __('Frontpage hero', 'terapia-huvi'),
            'description' => __('Content displayed in the hero section on the homepage.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('content_fi', __('Lead paragraph (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Lead paragraph (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('cta_primary_label_fi', __('Primary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_primary_label_en', __('Primary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('cta_primary_url_fi', __('Primary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_primary_url_en', __('Primary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_secondary_label_fi', __('Secondary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_secondary_label_en', __('Secondary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('cta_secondary_url_fi', __('Secondary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_secondary_url_en', __('Secondary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
            ],
        ];

        $definitions['frontpage-social'] = [
            'title' => __('Frontpage social intro', 'terapia-huvi'),
            'description' => __('Intro text for the social feed section.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('copy_fi', __('Description (FI)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('copy_en', __('Description (EN)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
            ],
        ];

        $definitions['frontpage-video'] = [
            'title' => __('YouTube spotlight', 'terapia-huvi'),
            'description' => __('Content for the YouTube highlight card.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('copy_fi', __('Description (FI)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('copy_en', __('Description (EN)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('placeholder_text_fi', __('Placeholder text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 2]),
                $this->make_field('placeholder_text_en', __('Placeholder text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 2]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'terapia-huvi')),
            ],
        ];

        $definitions['frontpage-facebook'] = [
            'title' => __('Facebook spotlight', 'terapia-huvi'),
            'description' => __('Content for the Facebook highlight card.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('copy_fi', __('Description (FI)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('copy_en', __('Description (EN)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('placeholder_text_fi', __('Placeholder text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 2]),
                $this->make_field('placeholder_text_en', __('Placeholder text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 2]),
                $this->make_field('primary_label_fi', __('Primary button label (FI)', 'terapia-huvi')),
                $this->make_field('primary_label_en', __('Primary button label (EN)', 'terapia-huvi')),
                $this->make_field('secondary_label_fi', __('Secondary link label (FI)', 'terapia-huvi')),
                $this->make_field('secondary_label_en', __('Secondary link label (EN)', 'terapia-huvi')),
            ],
        ];

        $definitions['frontpage-news'] = [
            'title' => __('News section', 'terapia-huvi'),
            'description' => __('Heading, description, and fallback message for the news grid.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('content_fi', __('Intro text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'terapia-huvi')),
                $this->make_field('cta_url_fi', __('CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_url_en', __('CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('empty_message_fi', __('Empty state message (FI)', 'terapia-huvi'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
                $this->make_field('empty_message_en', __('Empty state message (EN)', 'terapia-huvi'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
            ],
        ];

        $definitions['frontpage-overview'] = [
            'title' => __('Impact highlights', 'terapia-huvi'),
            'description' => __('Cards that link deeper into your key content.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('cards_fi', __('Cards (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $cardHelp]),
                $this->make_field('cards_en', __('Cards (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $cardHelp]),
            ],
        ];

        $definitions['frontpage-contact'] = [
            'title' => __('Contact section', 'terapia-huvi'),
            'description' => __('Copy and labels for the contact details.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('copy_fi', __('Intro text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('copy_en', __('Intro text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('general_contact_label_fi', __('General contact label (FI)', 'terapia-huvi')),
                $this->make_field('general_contact_label_en', __('General contact label (EN)', 'terapia-huvi')),
                $this->make_field('general_contact_email_fi', __('General contact email (FI)', 'terapia-huvi')),
                $this->make_field('general_contact_email_en', __('General contact email (EN)', 'terapia-huvi')),
                $this->make_field('chair_label_fi', __('Chair label (FI)', 'terapia-huvi')),
                $this->make_field('chair_label_en', __('Chair label (EN)', 'terapia-huvi')),
                $this->make_field('chair_value_fi', __('Chair value (FI)', 'terapia-huvi')),
                $this->make_field('chair_value_en', __('Chair value (EN)', 'terapia-huvi')),
                $this->make_field('vice_chair_label_fi', __('Vice chair label (FI)', 'terapia-huvi')),
                $this->make_field('vice_chair_label_en', __('Vice chair label (EN)', 'terapia-huvi')),
                $this->make_field('vice_chair_value_fi', __('Vice chair value (FI)', 'terapia-huvi')),
                $this->make_field('vice_chair_value_en', __('Vice chair value (EN)', 'terapia-huvi')),
                $this->make_field('board_members_label_fi', __('Board members label (FI)', 'terapia-huvi')),
                $this->make_field('board_members_label_en', __('Board members label (EN)', 'terapia-huvi')),
                $this->make_field('board_members_value_fi', __('Board members value (FI)', 'terapia-huvi'), 'textarea', ['rows' => 3]),
                $this->make_field('board_members_value_en', __('Board members value (EN)', 'terapia-huvi'), 'textarea', ['rows' => 3]),
                $this->make_field('social_heading_fi', __('Social heading (FI)', 'terapia-huvi')),
                $this->make_field('social_heading_en', __('Social heading (EN)', 'terapia-huvi')),
                $this->make_field('social_facebook_label_fi', __('Facebook label (FI)', 'terapia-huvi')),
                $this->make_field('social_facebook_label_en', __('Facebook label (EN)', 'terapia-huvi')),
                $this->make_field('social_x_label_fi', __('X / Twitter label (FI)', 'terapia-huvi')),
                $this->make_field('social_x_label_en', __('X / Twitter label (EN)', 'terapia-huvi')),
                $this->make_field('social_instagram_label_fi', __('Instagram label (FI)', 'terapia-huvi')),
                $this->make_field('social_instagram_label_en', __('Instagram label (EN)', 'terapia-huvi')),
                $this->make_field('social_youtube_label_fi', __('YouTube label (FI)', 'terapia-huvi')),
                $this->make_field('social_youtube_label_en', __('YouTube label (EN)', 'terapia-huvi')),
                $this->make_field('social_tiktok_label_fi', __('TikTok label (FI)', 'terapia-huvi')),
                $this->make_field('social_tiktok_label_en', __('TikTok label (EN)', 'terapia-huvi')),
                $this->make_field('holvi_label_fi', __('Holvi link label (FI)', 'terapia-huvi')),
                $this->make_field('holvi_label_en', __('Holvi link label (EN)', 'terapia-huvi')),
            ],
        ];

        $definitions['site-navigation'] = [
            'title' => __('Navigation defaults', 'terapia-huvi'),
            'description' => __('Fallback labels for the header navigation when no custom menu exists.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('links_fi', __('Links (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $navHelp]),
                $this->make_field('links_en', __('Links (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $navHelp]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'terapia-huvi')),
                $this->make_field('cta_url_fi', __('CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_url_en', __('CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('toggle_label_fi', __('Mobile toggle label (FI)', 'terapia-huvi')),
                $this->make_field('toggle_label_en', __('Mobile toggle label (EN)', 'terapia-huvi')),
            ],
        ];

        $definitions['site-footer'] = [
            'title' => __('Footer content', 'terapia-huvi'),
            'description' => __('All copy blocks displayed in the footer.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('about_text_fi', __('About text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('about_text_en', __('About text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('contact_heading_fi', __('Contact heading (FI)', 'terapia-huvi')),
                $this->make_field('contact_heading_en', __('Contact heading (EN)', 'terapia-huvi')),
                $this->make_field('contact_email_fi', __('Contact email (FI)', 'terapia-huvi')),
                $this->make_field('contact_email_en', __('Contact email (EN)', 'terapia-huvi')),
                $this->make_field('contact_address_fi', __('Contact address (FI)', 'terapia-huvi')),
                $this->make_field('contact_address_en', __('Contact address (EN)', 'terapia-huvi')),
                $this->make_field('contact_phone_fi', __('Contact phone (FI)', 'terapia-huvi')),
                $this->make_field('contact_phone_en', __('Contact phone (EN)', 'terapia-huvi')),
                $this->make_field('contact_phone_href_fi', __('Contact phone link (FI)', 'terapia-huvi'), 'text', ['help' => $phoneHelp]),
                $this->make_field('contact_phone_href_en', __('Contact phone link (EN)', 'terapia-huvi'), 'text', ['help' => $phoneHelp]),
                $this->make_field('links_heading_fi', __('Links heading (FI)', 'terapia-huvi')),
                $this->make_field('links_heading_en', __('Links heading (EN)', 'terapia-huvi')),
                $this->make_field('link_1_label_fi', __('Link 1 label (FI)', 'terapia-huvi')),
                $this->make_field('link_1_label_en', __('Link 1 label (EN)', 'terapia-huvi')),
                $this->make_field('link_1_url_fi', __('Link 1 URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_1_url_en', __('Link 1 URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_2_label_fi', __('Link 2 label (FI)', 'terapia-huvi')),
                $this->make_field('link_2_label_en', __('Link 2 label (EN)', 'terapia-huvi')),
                $this->make_field('link_2_url_fi', __('Link 2 URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_2_url_en', __('Link 2 URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_3_label_fi', __('Link 3 label (FI)', 'terapia-huvi')),
                $this->make_field('link_3_label_en', __('Link 3 label (EN)', 'terapia-huvi')),
                $this->make_field('link_3_url_fi', __('Link 3 URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_3_url_en', __('Link 3 URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_4_label_fi', __('Link 4 label (FI)', 'terapia-huvi')),
                $this->make_field('link_4_label_en', __('Link 4 label (EN)', 'terapia-huvi')),
                $this->make_field('link_4_url_fi', __('Link 4 URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('link_4_url_en', __('Link 4 URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('newsletter_heading_fi', __('Newsletter heading (FI)', 'terapia-huvi')),
                $this->make_field('newsletter_heading_en', __('Newsletter heading (EN)', 'terapia-huvi')),
                $this->make_field('newsletter_copy_fi', __('Newsletter description (FI)', 'terapia-huvi'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
                $this->make_field('newsletter_copy_en', __('Newsletter description (EN)', 'terapia-huvi'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
                $this->make_field('newsletter_label_fi', __('Newsletter field label (FI)', 'terapia-huvi')),
                $this->make_field('newsletter_label_en', __('Newsletter field label (EN)', 'terapia-huvi')),
                $this->make_field('newsletter_placeholder_fi', __('Newsletter placeholder (FI)', 'terapia-huvi')),
                $this->make_field('newsletter_placeholder_en', __('Newsletter placeholder (EN)', 'terapia-huvi')),
                $this->make_field('newsletter_button_fi', __('Newsletter button label (FI)', 'terapia-huvi')),
                $this->make_field('newsletter_button_en', __('Newsletter button label (EN)', 'terapia-huvi')),
                $this->make_field('social_facebook_label_fi', __('Footer Facebook label (FI)', 'terapia-huvi')),
                $this->make_field('social_facebook_label_en', __('Footer Facebook label (EN)', 'terapia-huvi')),
                $this->make_field('social_instagram_label_fi', __('Footer Instagram label (FI)', 'terapia-huvi')),
                $this->make_field('social_instagram_label_en', __('Footer Instagram label (EN)', 'terapia-huvi')),
                $this->make_field('social_linkedin_label_fi', __('Footer LinkedIn label (FI)', 'terapia-huvi')),
                $this->make_field('social_linkedin_label_en', __('Footer LinkedIn label (EN)', 'terapia-huvi')),
                $this->make_field('copyright_fi', __('Copyright (FI)', 'terapia-huvi')),
                $this->make_field('copyright_en', __('Copyright (EN)', 'terapia-huvi')),
            ],
        ];

        $definitions['missio'] = [
            'title' => __('Mission page copy', 'terapia-huvi'),
            'description' => __('Intro, focus areas, and principles displayed on the mission page.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Intro eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Intro eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Intro heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Intro heading (EN)', 'terapia-huvi')),
                $this->make_field('content_fi', __('Intro text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('highlights_fi', __('Highlights (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $pairHelp]),
                $this->make_field('highlights_en', __('Highlights (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $pairHelp]),
                $this->make_field('focus_heading_fi', __('Focus heading (FI)', 'terapia-huvi')),
                $this->make_field('focus_heading_en', __('Focus heading (EN)', 'terapia-huvi')),
                $this->make_field('focus_items_fi', __('Focus items (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('focus_items_en', __('Focus items (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('activities_eyebrow_fi', __('Activities eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('activities_eyebrow_en', __('Activities eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('activities_title_fi', __('Activities heading (FI)', 'terapia-huvi')),
                $this->make_field('activities_title_en', __('Activities heading (EN)', 'terapia-huvi')),
                $this->make_field('activities_content_fi', __('Activities intro (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('activities_content_en', __('Activities intro (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('activities_pillars_fi', __('Activities pillars (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('activities_pillars_en', __('Activities pillars (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('activities_cta_label_fi', __('Activities CTA label (FI)', 'terapia-huvi')),
                $this->make_field('activities_cta_label_en', __('Activities CTA label (EN)', 'terapia-huvi')),
                $this->make_field('activities_cta_url_fi', __('Activities CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('activities_cta_url_en', __('Activities CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('theses_fi', __('Principles list (FI)', 'terapia-huvi'), 'textarea', ['rows' => 8, 'help' => $listHelp]),
                $this->make_field('theses_en', __('Principles list (EN)', 'terapia-huvi'), 'textarea', ['rows' => 8, 'help' => $listHelp]),
            ],
        ];

        $definitions['tietopyynnot'] = [
            'title' => __('Information requests', 'terapia-huvi'),
            'description' => __('Archive of published information requests.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('content_fi', __('Intro text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('entries_fi', __('Entry summaries (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('entries_en', __('Entry summaries (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('entry_1_items_fi', __('Entry 1 items (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_1_items_en', __('Entry 1 items (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_2_items_fi', __('Entry 2 items (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_2_items_en', __('Entry 2 items (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_3_items_fi', __('Entry 3 items (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_3_items_en', __('Entry 3 items (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_4_items_fi', __('Entry 4 items (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('entry_4_items_en', __('Entry 4 items (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $listHelp]),
                $this->make_field('footer_text_fi', __('Footer note (FI)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('footer_text_en', __('Footer note (EN)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
            ],
        ];

        $definitions['osallistu'] = [
            'title' => __('Get involved page', 'terapia-huvi'),
            'description' => __('Volunteer opportunities and CTA labels.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('content_fi', __('Intro text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('opportunities_fi', __('Opportunities (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $cardHelp]),
                $this->make_field('opportunities_en', __('Opportunities (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $cardHelp]),
                $this->make_field('cta_primary_label_fi', __('Primary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_primary_label_en', __('Primary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('cta_primary_url_fi', __('Primary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_primary_url_en', __('Primary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_secondary_label_fi', __('Secondary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_secondary_label_en', __('Secondary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('cta_secondary_url_fi', __('Secondary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_secondary_url_en', __('Secondary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_tertiary_label_fi', __('Tertiary CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_tertiary_label_en', __('Tertiary CTA label (EN)', 'terapia-huvi')),
                $this->make_field('cta_tertiary_url_fi', __('Tertiary CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_tertiary_url_en', __('Tertiary CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('donation_text_fi', __('Donation note (FI)', 'terapia-huvi'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
                $this->make_field('donation_text_en', __('Donation note (EN)', 'terapia-huvi'), 'textarea', ['rows' => 3, 'help' => $richHelp]),
            ],
        ];

        $definitions['saannot'] = [
            'title' => __('Statutes summary', 'terapia-huvi'),
            'description' => __('Key points and contact link for the statutes page.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('content_fi', __('Intro text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 5, 'help' => $richHelp]),
                $this->make_field('cards_fi', __('Cards (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('cards_en', __('Cards (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $pairHelp]),
                $this->make_field('cta_label_fi', __('CTA label (FI)', 'terapia-huvi')),
                $this->make_field('cta_label_en', __('CTA label (EN)', 'terapia-huvi')),
                $this->make_field('cta_url_fi', __('CTA URL (FI)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
                $this->make_field('cta_url_en', __('CTA URL (EN)', 'terapia-huvi'), 'text', ['help' => $urlHelp]),
            ],
        ];

        $definitions['tietosuoja'] = [
            'title' => __('Privacy summary', 'terapia-huvi'),
            'description' => __('Key privacy copy and contact instructions.', 'terapia-huvi'),
            'fields' => [
                $this->make_field('eyebrow_fi', __('Eyebrow (FI)', 'terapia-huvi')),
                $this->make_field('eyebrow_en', __('Eyebrow (EN)', 'terapia-huvi')),
                $this->make_field('title_fi', __('Heading (FI)', 'terapia-huvi')),
                $this->make_field('title_en', __('Heading (EN)', 'terapia-huvi')),
                $this->make_field('content_fi', __('Intro text (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('content_en', __('Intro text (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $richHelp]),
                $this->make_field('rights_heading_fi', __('Rights heading (FI)', 'terapia-huvi')),
                $this->make_field('rights_heading_en', __('Rights heading (EN)', 'terapia-huvi')),
                $this->make_field('rights_fi', __('Rights list (FI)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $listHelp]),
                $this->make_field('rights_en', __('Rights list (EN)', 'terapia-huvi'), 'textarea', ['rows' => 6, 'help' => $listHelp]),
                $this->make_field('contact_heading_fi', __('Contact heading (FI)', 'terapia-huvi')),
                $this->make_field('contact_heading_en', __('Contact heading (EN)', 'terapia-huvi')),
                $this->make_field('contact_copy_fi', __('Contact instructions (FI)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
                $this->make_field('contact_copy_en', __('Contact instructions (EN)', 'terapia-huvi'), 'textarea', ['rows' => 4, 'help' => $richHelp]),
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
                'primary_cta_label_fi' => 'Varaa aika',
                'primary_cta_label_en' => 'Book appointment',
                'primary_cta_url_fi' => '#',
                'primary_cta_url_en' => '#',
                'secondary_cta_label_fi' => 'Lue lisää terapiasta',
                'secondary_cta_label_en' => 'Learn about therapy',
                'secondary_cta_url_fi' => '/terapia',
                'secondary_cta_url_en' => '/terapia?lang=en',
                'card_title_fi' => 'Ratkaisukeskeinen lähestymistapa',
                'card_title_en' => 'Solution-focused approach',
                'card_copy_fi' => 'Keskitymme vahvuuksiin ja tulevaisuuteen. Yhdessä löydämme tapoja edetä kohti toivottua muutosta ja parempaa hyvinvointia.',
                'card_copy_en' => 'We focus on strengths and the future. Together we find ways to move toward desired change and better wellbeing.',
                'card_primary_cta_label_fi' => 'Varaa aika',
                'card_primary_cta_label_en' => 'Book appointment',
                'card_primary_cta_url_fi' => '#',
                'card_primary_cta_url_en' => '#',
                'card_secondary_cta_label_fi' => 'Tutustu minuun',
                'card_secondary_cta_label_en' => 'About me',
                'card_secondary_cta_url_fi' => '/minusta',
                'card_secondary_cta_url_en' => '/minusta?lang=en',
            ],
            'frontpage-hero' => [
                'eyebrow_fi' => 'Ratkaisukeskeinen lyhytterapia',
                'eyebrow_en' => 'Solution-focused brief therapy',
                'title_fi' => 'Rauhallista muutosta – yksi keskustelu kerrallaan',
                'title_en' => 'Peaceful change – one conversation at a time',
                'content_fi' => '<p>Ratkaisukeskeinen lyhytterapia auttaa sinua löytämään toivoa, voimavaroja ja suuntaa elämään silloin, kun asiat tuntuvat jumittuneilta.</p>',
                'content_en' => '<p>Solution-focused brief therapy helps you find hope, resources, and direction in life when things feel stuck.</p>',
                'cta_primary_label_fi' => 'Varaa aika',
                'cta_primary_label_en' => 'Book a session',
                'cta_primary_url_fi' => '#',
                'cta_primary_url_en' => '#',
                'cta_secondary_label_fi' => 'Lue lisää terapiasta',
                'cta_secondary_label_en' => 'Learn about therapy',
                'cta_secondary_url_fi' => '/terapia',
                'cta_secondary_url_en' => '/terapia?lang=en',
            ],
            'frontpage-social' => [
                'eyebrow_fi' => 'Seuraa minua',
                'eyebrow_en' => 'Follow me',
                'title_fi' => 'Instagram',
                'title_en' => 'Instagram',
                'copy_fi' => 'Seuraa minua Instagramissa saadaksesi vinkkejä hyvinvoinnista ja terapiasta.',
                'copy_en' => 'Follow me on Instagram for tips on wellbeing and therapy.',
            ],
            'frontpage-video' => [
                'title_fi' => 'YouTube-kanavamme',
                'title_en' => 'Our YouTube channel',
                'copy_fi' => 'Seuraa minua Instagramissa saadaksesi vinkkejä hyvinvoinnista ja terapiasta.',
                'copy_en' => 'Follow me on Instagram for tips on wellbeing and therapy.',
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
                'title_fi' => 'Blogi ja ajatuksia',
                'title_en' => 'Blog and thoughts',
                'content_fi' => '<p>Lue ajatuksiani terapiasta, hyvinvoinnista ja elämän haasteista.</p>',
                'content_en' => '<p>Read my thoughts on therapy, wellbeing, and life challenges.</p>',
                'cta_label_fi' => 'Kaikki uutiset',
                'cta_label_en' => 'All articles',
                'cta_url_fi' => '/blogi',
                'cta_url_en' => '/blogi?lang=en',
                'empty_message_fi' => 'Artikkeleita on tulossa pian – seuraa minua somessa pysyäksesi ajan tasalla.',
                'empty_message_en' => 'Articles coming soon—follow me on social media to stay in the loop.',
            ],
            'frontpage-overview' => [
                'eyebrow_fi' => 'Palvelut',
                'eyebrow_en' => 'Services',
                'title_fi' => 'Miten voin auttaa',
                'title_en' => 'How I can help',
                'cards_fi' => implode("\n", [
                    'Yksilöterapia::Yksilöterapiassa työskentelemme yhdessä löytääksemme ratkaisuja elämäsi haasteisiin ja vahvistaaksemme hyvinvointiasi.::Lue lisää::/terapia',
                    'Pariterapia::Pariterapiassa keskitymme parisuhteen vahvuuksiin ja kehitämme yhteisiä tapoja edetä kohti parempaa yhteiselämää.::Lue lisää::/terapia',
                    'Perheterapia::Perheterapiassa tuemme perheen jäseniä löytämään uusia tapoja kommunikoida ja toimia yhdessä.::Lue lisää::/terapia',
                ]),
                'cards_en' => implode("\n", [
                    'Individual therapy::In individual therapy we work together to find solutions to your challenges and strengthen your wellbeing.::Learn more::/terapia?lang=en',
                    'Couples therapy::In couples therapy we focus on relationship strengths and develop ways to move toward better shared life.::Learn more::/terapia?lang=en',
                    'Family therapy::In family therapy we support family members in finding new ways to communicate and work together.::Learn more::/terapia?lang=en',
                ]),
            ],
            'frontpage-contact' => [
                'eyebrow_fi' => 'Yhteystiedot',
                'eyebrow_en' => 'Contact',
                'title_fi' => 'Ota yhteyttä',
                'title_en' => 'Get in touch',
                'copy_fi' => 'Ota rohkeasti yhteyttä, jos haluat keskustella terapiasta tai varata ajan.',
                'copy_en' => 'Feel free to reach out if you want to discuss therapy or book an appointment.',
                'general_contact_label_fi' => 'Sähköposti:',
                'general_contact_label_en' => 'Email:',
                'general_contact_email_fi' => 'terapia@terapiahuvi.fi',
                'general_contact_email_en' => 'terapia@terapiahuvi.fi',
                'contact_phone_fi' => '+358 44 999 2092',
                'contact_phone_en' => '+358 44 999 2092',
                'contact_address_fi' => 'Aleksanterinkatu 29 B 18, 33100 Tampere',
                'contact_address_en' => 'Aleksanterinkatu 29 B 18, 33100 Tampere, Finland',
            ],
            'site-navigation' => [
                'links_fi' => implode("\n", [
                    'Etusivu::/',
                    'Terapia::/terapia',
                    'Hinnasto::/hinnasto',
                    'Minusta::/minusta',
                    'Yhteystiedot::/yhteystiedot',
                    'Lahjakortit::/lahjakortit',
                ]),
                'links_en' => implode("\n", [
                    'Home::/',
                    'Therapy::/terapia?lang=en',
                    'Pricing::/hinnasto?lang=en',
                    'About me::/minusta?lang=en',
                    'Contact::/yhteystiedot?lang=en',
                    'Gift cards::/lahjakortit?lang=en',
                ]),
                'cta_label_fi' => 'Varaa aika',
                'cta_label_en' => 'Book appointment',
                'cta_url_fi' => '#',
                'cta_url_en' => '#',
                'toggle_label_fi' => 'Avaa valikko',
                'toggle_label_en' => 'Open menu',
            ],
            'site-footer' => [
                'about_text_fi' => 'Ratkaisukeskeinen lyhytterapeutti Tampereelta. Autan sinua löytämään toivoa, voimavaroja ja suuntaa elämään.',
                'about_text_en' => 'Solution-focused brief therapist from Tampere. I help you find hope, resources, and direction in life.',
                'contact_heading_fi' => 'Yhteystiedot',
                'contact_heading_en' => 'Contact',
                'contact_email_fi' => 'terapia@terapiahuvi.fi',
                'contact_email_en' => 'terapia@terapiahuvi.fi',
                'contact_address_fi' => 'Aleksanterinkatu 29 B 18, 33100 Tampere',
                'contact_address_en' => 'Aleksanterinkatu 29 B 18, 33100 Tampere, Finland',
                'contact_phone_fi' => '+358 44 999 2092',
                'contact_phone_en' => '+358 44 999 2092',
                'contact_phone_href_fi' => 'tel:+358449992092',
                'contact_phone_href_en' => 'tel:+358449992092',
                'links_heading_fi' => 'Sivut',
                'links_heading_en' => 'Pages',
                'link_1_label_fi' => 'Terapia',
                'link_1_label_en' => 'Therapy',
                'link_1_url_fi' => '/terapia',
                'link_1_url_en' => '/terapia?lang=en',
                'link_2_label_fi' => 'Hinnasto',
                'link_2_label_en' => 'Pricing',
                'link_2_url_fi' => '/hinnasto',
                'link_2_url_en' => '/hinnasto?lang=en',
                'link_3_label_fi' => 'Minusta',
                'link_3_label_en' => 'About me',
                'link_3_url_fi' => '/minusta',
                'link_3_url_en' => '/minusta?lang=en',
                'link_4_label_fi' => 'Yhteystiedot',
                'link_4_label_en' => 'Contact',
                'link_4_url_fi' => '/yhteystiedot',
                'link_4_url_en' => '/yhteystiedot?lang=en',
                'social_instagram_label_fi' => 'Instagram',
                'social_instagram_label_en' => 'Instagram',
                'copyright_fi' => '© 2025 TerapiaHuvi',
                'copyright_en' => '© 2025 TerapiaHuvi',
            ],
            'terapia' => [
                'eyebrow_fi' => 'Ratkaisukeskeinen lyhytterapia',
                'eyebrow_en' => 'Solution-focused brief therapy',
                'title_fi' => 'Mitä on ratkaisukeskeinen lyhytterapia?',
                'title_en' => 'What is solution-focused brief therapy?',
                'content_fi' => '<p>Ratkaisukeskeinen lyhytterapia (SFBT) on käytännönläheinen ja voimavarasuuntautunut menetelmä. Keskustelun kautta autamme sinua tunnistamaan, mikä on sinulle tärkeää ja miten voit edetä sitä kohti. Menneisyyttä käsitellään vain siltä osin kuin se auttaa ymmärtämään toiveitasi ja tavoitteitasi.</p><p>Tyypillisesti tapaamisia on 1–10, riippuen tarpeistasi.</p>',
                'content_en' => '<p>Solution-focused brief therapy (SFBT) is a practical and strength-based method. Through conversation, we help you identify what matters to you and how you can move toward it. The past is addressed only to the extent that it helps understand your hopes and goals.</p><p>Typically, there are 1–10 sessions, depending on your needs.</p>',
                'highlights_fi' => implode("\n", [
                    'Vahvuuksien tunnistaminen::Keskitymme siihen, mikä jo toimii elämässäsi ja miten voimme hyödyntää näitä vahvuuksia.',
                    'Tulevaisuuteen suuntautuminen::Tutkimme yhdessä, miltä toivottu tulevaisuus näyttää ja miten sinne päästään.',
                    'Lyhytkestoisuus::Ratkaisukeskeinen terapia on yleensä lyhytkestoista ja tehokasta.',
                ]),
                'highlights_en' => implode("\n", [
                    'Identifying strengths::We focus on what already works in your life and how we can leverage these strengths.',
                    'Future orientation::Together we explore what the desired future looks like and how to get there.',
                    'Brief duration::Solution-focused therapy is typically brief and effective.',
                ]),
            ],
            'minusta' => [
                'eyebrow_fi' => 'Terapeutti',
                'eyebrow_en' => 'Therapist',
                'title_fi' => 'Valtteri Huvi – Sinun terapeuttisi Tampereelta',
                'title_en' => 'Valtteri Huvi – Your therapist from Tampere',
                'content_fi' => '<p>Olen ratkaisukeskeinen lyhytterapeutti Tampereelta. Työskentelen ihmisten kanssa, jotka haluavat löytää uusia näkökulmia, vahvistaa hyvinvointiaan ja löytää omat vastauksensa elämän kysymyksiin.</p><p>Uskon, että pienetkin muutokset voivat käynnistää suuria prosesseja. Terapiassa keskitymme siihen, mikä toimii – ei siihen, mikä on rikki.</p>',
                'content_en' => '<p>I am a solution-focused brief therapist from Tampere. I work with people who want to find new perspectives, strengthen their wellbeing, and find their own answers to life\'s questions.</p><p>I believe that even small changes can trigger large processes. In therapy, we focus on what works – not on what is broken.</p>',
                'highlights_fi' => implode("\n", [
                    'Koulutus::Olen suorittanut terapeutin koulutuksen ja erikoistunut ratkaisukeskeiseen lyhytterapiaan.',
                    'Kokemus::Työskentelen yksilö-, pari- ja perheterapian parissa.',
                    'Arvot::Uskon ihmisten vahvuuksiin ja kykyyn muuttaa elämäänsä. Pienet muutokset voivat käynnistää suuria prosesseja.',
                ]),
                'highlights_en' => implode("\n", [
                    'Education::I have completed therapist training and specialized in solution-focused brief therapy.',
                    'Experience::I work with individual, couple, and family therapy.',
                    'Values::I believe in people\'s strengths and ability to change their lives. Small changes can trigger large processes.',
                ]),
            ],
            'hinnasto' => [
                'eyebrow_fi' => 'Hinnasto',
                'eyebrow_en' => 'Pricing',
                'title_fi' => 'Hinnasto',
                'title_en' => 'Pricing',
                'content_fi' => '<p>Selkeät hinnat eri palveluille. Varaa aika vastaanotolleni.</p>',
                'content_en' => '<p>Clear pricing for different services. Book an appointment at my office.</p>',
                'prices_fi' => implode("\n", [
                    'Ratkaisukeskeinen terapia 30 min::52 €',
                    'Ratkaisukeskeinen terapia 60 min::75 €',
                    'Ratkaisukeskeinen terapia 90 min::90 €',
                ]),
                'prices_en' => implode("\n", [
                    'Solution-focused therapy 30 min::52 €',
                    'Solution-focused therapy 60 min::75 €',
                    'Solution-focused therapy 90 min::90 €',
                ]),
            ],
            'lahjakortit' => [
                'eyebrow_fi' => 'Lahjakortit',
                'eyebrow_en' => 'Gift cards',
                'title_fi' => 'Lahjakortit',
                'title_en' => 'Gift cards',
                'content_fi' => '<p>Anna terapeuttinen lahjakortti läheisellesi. Lahjakortti on henkilökohtainen ja voimassa 12 kuukautta.</p>',
                'content_en' => '<p>Give a therapeutic gift card to someone close to you. The gift card is personal and valid for 12 months.</p>',
            ],
            'yhteystiedot' => [
                'eyebrow_fi' => 'Yhteystiedot',
                'eyebrow_en' => 'Contact',
                'title_fi' => 'Ota yhteyttä',
                'title_en' => 'Get in touch',
                'content_fi' => '<p>Ota rohkeasti yhteyttä, jos haluat keskustella terapiasta tai varata ajan. Voin auttaa monenlaisissa elämäntilanteissa ja haasteissa. Vastaanotoni on maanantai-perjantai klo 9-17.</p>',
                'content_en' => '<p>Feel free to reach out if you want to discuss therapy or book an appointment. I can help with various life situations and challenges. My office hours are Monday-Friday 9-17.</p>',
            ],
            'tietosuoja' => [
                'eyebrow_fi' => 'Tietosuoja',
                'eyebrow_en' => 'Privacy',
                'title_fi' => 'Rekisteriseloste',
                'title_en' => 'Data protection summary',
                'content_fi' => '<p>Ylläpidän asiakasrekisteriä, jota käytetään terapiatoiminnan hoitamiseen ja asiakassuhteen hallintaan. Tietoja säilytetään suojatuissa järjestelmissä ja poistetaan viimeistään kolmen vuoden kuluttua terapiasuhteen päättymisestä.</p>',
                'content_en' => '<p>I maintain a client register used for managing therapy services and client relationships. Data is stored securely and deleted no later than three years after the therapy relationship ends.</p>',
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
                'contact_copy_fi' => '<p>Lähetä allekirjoitettu pyyntö osoitteeseen <a href="mailto:terapia@esimerkki.fi" class="text-brand-orange hover:text-brand-orange/80">terapia@esimerkki.fi</a>. Vastaamme pyyntöihin kuukauden kuluessa.</p>',
                'contact_copy_en' => '<p>Send a signed request to <a href="mailto:therapy@example.com" class="text-brand-orange hover:text-brand-orange/80">therapy@example.com</a>. I respond within one month.</p>',
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

new TerapiaHuviTheme();

