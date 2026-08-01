<?php

use Kirby\Cms\App as Kirby;
use Kirby\Http\Remote;
use Kirby\Http\Response;
use JanHerman\Vite\Vite;

@include_once __DIR__ . '/vendor/autoload.php';

Kirby::plugin('jan-herman/vite', [
	'options' => [
		'entry' => 'index.js',
		'mode' => 'auto',
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
		],
	],
	'routes' => function ($kirby) {
		// Proxy
		$routes = [];
		$viteServerBase = trim($kirby->option('jan-herman.vite.server.base', '/'), '/');

		if (vite()->isDev() && $viteServerBase) {
			$routes[] = [
				'pattern' => $viteServerBase . '/(:all)',
				'action' => function ($entry) {
					$url = vite()->devUrl($entry);

					$query = $_SERVER['QUERY_STRING'] ?? '';

					if ($query !== '') {
						$url .= '?' . $query;
					}

					$response = Remote::get($url);

					return new Response(
						$response->content(),
						$response->headers()['Content-Type'] ?? 'text/html',
						$response->code(),
						$response->headers()
					);
				},
			];
		}

		return $routes;
	},
]);

function vite()
{
	return Vite::getInstance();
}
