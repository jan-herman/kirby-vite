<?php

use Kirby\Cms\App as Kirby;
use Kirby\Http\Remote;
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
	'routes' => function ($kirby) {
		$routes = [
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
		];

		// Proxy
		$vite_server_base = trim($kirby->option('jan-herman.vite.server.base', '/'), '/');

		if (vite()->isDev() && $vite_server_base) {
			$routes[] = [
				'pattern' => $vite_server_base . '/(:all)',
				'action' => function($entry) {
					$url = vite()->url($entry);

					if ($url === null) {
						return false;
					}

					$response = Remote::get($url);

					return new Response(
						$response->content(),
						$response->headers()['Content-Type'] ?? 'text/html',
						$response->code(),
						$response->headers()
					);
				}
			];
		}

		return $routes;
	},
]);

function vite() {
    return Vite::getInstance();
}
