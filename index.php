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
			'hotFile' => 'src/.lock',
			'manifest' => '.vite/manifest.json',
		]
	],
	'routes' => [
		[
			'pattern' => '@kirby-vite/(:all).js',
			'action' => function($entry) {
				return new Response(
                    'import \'' . vite()->url($entry) . '\'',
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
