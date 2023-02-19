<?php

use Kirby\Cms\App as Kirby;
use JanHerman\Vite\Vite;

@include_once __DIR__ . '/vendor/autoload.php';

$kirby = kirby();

Kirby::plugin('jan-herman/vite', [
    'options' => [
        'entry' => 'index.js',
		'server' => [
			'host' => $kirby->environment()->host(),
			'port' => 3000,
			'https' => false,
		],
		'build' => [
            'rootDir' => 'src',
			'outDir' => 'dist'
		]
	]
]);

function vite() {
    return Vite::getInstance();
}
