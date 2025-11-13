<?php

$context = Timber::context();
$paged = get_query_var('paged') ? (int) get_query_var('paged') : 1;
$context['posts'] = Timber::get_posts([
    'post_type' => 'post',
    'posts_per_page' => 12,
    'paged' => $paged,
]);

Timber::render('archive.twig', $context);

