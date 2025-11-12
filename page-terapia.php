<?php

use Timber\Timber;

$context = Timber::context();
$context['post'] = Timber::get_post();
$context['slug'] = 'terapia';

Timber::render('page-terapia.twig', $context);

