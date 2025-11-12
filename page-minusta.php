<?php

use Timber\Timber;

$context = Timber::context();
$context['post'] = Timber::get_post();
$context['slug'] = 'minusta';

Timber::render('page-minusta.twig', $context);

