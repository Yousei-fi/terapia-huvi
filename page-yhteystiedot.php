<?php

use Timber\Timber;

$context = Timber::context();
$context['post'] = Timber::get_post();
$context['slug'] = 'yhteystiedot';

Timber::render('page-yhteystiedot.twig', $context);

