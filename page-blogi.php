<?php

use Timber\Timber;

$context = Timber::context();

// Get posts for the blog archive
$context['posts'] = Timber::get_posts([
    'post_type' => 'post',
    'posts_per_page' => 12,
    'paged' => get_query_var('paged') ? get_query_var('paged') : 1,
]);

// If there's a page with slug 'blogi', include it in context
$page = Timber::get_post(['post_type' => 'page', 'name' => 'blogi']);
if ($page) {
    $context['post'] = $page;
}

Timber::render('page-blogi.twig', $context);

