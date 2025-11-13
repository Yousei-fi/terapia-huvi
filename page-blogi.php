<?php

$context = Timber::context();
$context['posts'] = Timber::get_posts([
    'post_type' => 'post',
    'posts_per_page' => 12,
    'paged' => get_query_var('paged') ? get_query_var('paged') : 1,
]);

Timber::render('archive.twig', $context);

