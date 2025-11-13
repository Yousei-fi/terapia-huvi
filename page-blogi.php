<?php

use Timber\Timber;

$context = Timber::context();

// Get posts for the blog archive
$paged = get_query_var('page') ? (int) get_query_var('page') : 1;
$context['posts'] = Timber::get_posts([
    'post_type' => 'post',
    'posts_per_page' => 12,
    'paged' => $paged,
]);

// If there's a page with slug 'blogi', include it in context
$page = Timber::get_post(['post_type' => 'page', 'name' => 'blogi']);
if ($page) {
    $context['post'] = $page;
}

Timber::render('page-blogi.twig', $context);

