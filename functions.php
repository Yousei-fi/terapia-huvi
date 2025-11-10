<?php

require_once __DIR__ . '/vendor/autoload.php';

\Timber\Timber::init();

class HPPTheme extends \Timber\Site {
    public function __construct() {
        \Timber\Timber::$dirname = ['views', 'src/components'];
        add_action('after_setup_theme', [$this, 'theme_supports']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('timber/context', [$this, 'add_to_context']);
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

        return $context;
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
}

new HPPTheme();

