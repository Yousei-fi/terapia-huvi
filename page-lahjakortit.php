<?php

use Timber\Timber;

$context = Timber::context();
$context['post'] = Timber::get_post();
$context['slug'] = 'lahjakortit';

Timber::render('page-lahjakortit.twig', $context);

