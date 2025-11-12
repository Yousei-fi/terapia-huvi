<?php

use Timber\Timber;

$context = Timber::context();
$context['post'] = Timber::get_post();
$context['slug'] = 'hinnasto';

Timber::render('page-hinnasto.twig', $context);

