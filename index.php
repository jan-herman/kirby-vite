<?php

use Kirby\Cms\App as Kirby;
use Kirby\Http\Response;
use JanHerman\Vite\Vite;

@include_once __DIR__ . '/vendor/autoload.php';

Kirby::plugin('jan-herman/vite', [
    'options' => [
        'entry' => 'index.js',
		'server' => [
			'host' => 'localhost',
			'port' => 3000,
			'https' => false,
			'base' => '/',
		],
		'build' => [
            'rootDir' => 'src',
			'outDir' => 'dist',
			'manifest' => '.vite/manifest.json',
		]
	],
	'routes' => [
		[
			'pattern' => '@kirby-vite/(:all).js',
			'action' => function($file) {
				return new Response(
                    'import \'' . vite()->file($file) . '\'',
                    'application/javascript',
                    200,
                    [
                        'Cache-Control' => 'public, max-age=3600, must-revalidate'
                    ]
                );
			}
		]
	],
]);

function vite() {
    return Vite::getInstance();
}
