<?php

namespace JanHerman\Vite;

use Kirby\Http\Url;
use Kirby\Http\Uri;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Html;

class Vite
{
    protected static $instance;

    protected bool $is_dev;
    protected string $out_dir;
    protected string $root_dir;
    protected string $dev_server;
    protected array $manifest;
    protected array $css_files = [];

    public static function getInstance()
    {
        return self::$instance ??= new self();
    }

    public function destroy(): void
    {
        self::$instance = null;
    }

    /**
     * Make sure, a path starts with a slash and doesn't end with a slash.
     */
    protected function normalizePath(string $path): string
    {
        return Url::path($path, true, false);
    }

    /**
     * Check if we're in development mode.
     * Look for hotfile in vite's root dir as indicator.
     */
    public function isDev(): bool
    {
        if (isset($this->is_dev)) {
            return $this->is_dev;
        }

        $relative_path = option('jan-herman.vite.build.hotFile', 'src/.lock');
        $hot_file = kirby()->root('base') . $this->normalizePath($relative_path);

        return $this->is_dev = F::exists($hot_file);
    }

    /**
     * Get the output directory.
     */
    public function getOutDir(): string
    {
        return $this->out_dir ??= $this->normalizePath(
            option('jan-herman.vite.build.outDir', 'dist')
        );
    }

    /**
     * Get vite's root directory.
     */
    public function getRootDir(): string
    {
        return $this->root_dir ??= $this->normalizePath(
            option('jan-herman.vite.build.rootDir', 'src')
        );
    }

    /**
     * Get vite's dev server url.
     */
    public function getDevServer(): string
	{
		if (isset($this->dev_server)) {
            return $this->dev_server;
        }

		$uri = new Uri([
			'scheme' => option('jan-herman.vite.server.https', false) ? 'https' : 'http',
			'host'   => option('jan-herman.vite.server.host', 'localhost'),
			'port'   => option('jan-herman.vite.server.port', 3000)
		]);

		return $this->dev_server = $uri->toString();
	}

    /**
     * Get the url for the specified file for development mode.
     */
    public function devUrl(string $path, bool $proxy = false): string
	{
        $base = option('jan-herman.vite.server.base', '/');
        $server = $proxy && trim($base, '/') ? kirby()->url('index') : $this->getDevServer();

		return $server . $this->normalizePath($base) . $this->normalizePath($path);
	}

    /**
     * Get the path for the specified file for development mode.
     */
    public function devPath(string $path): string
	{
		return kirby()->root('base') . $this->getRootDir() . $this->normalizePath($path);
	}

    /**
     * Get the URL for the specified file for production mode.
     */
    public function prodUrl(string $path): string
	{
        $root = kirby()->url('index');
        return ($root === '/' ? '' : $root) . $this->getOutDir() . $this->normalizePath($path);
	}

    /**
     * Get the path for the specified file for production mode.
     */
    public function prodPath(string $path): string
	{
        $root = kirby()->root('index');
        return $this->normalizePath($root) . $this->getOutDir() . $this->normalizePath($path);
	}

    /**
     * Read and parse the manifest file.
     */
    public function getManifest(): array
    {
        if (isset($this->manifest)) {
            return $this->manifest;
        }

        $relative_path = option('jan-herman.vite.build.manifest', '.vite/manifest.json');
        $manifest_path = kirby()->root('index') . $this->getOutDir() . $this->normalizePath($relative_path);

        if (!F::exists($manifest_path)) {
            return [];
        }

        return $this->manifest = json_decode(F::read($manifest_path), true);
    }

    /**
     * Get the value of a manifest property for a specific entry.
     */
    public function getManifestProperty(?string $entry = null, string $key = 'file')
    {
        $entry ??= option('jan-herman.vite.entry', 'index.js');
        $entry = ltrim($entry, '/');
        $manifest_entry = $this->getManifest()[$entry] ?? null;

        if (!$manifest_entry) {
            return;
        }

        $value = $manifest_entry[$key] ?? null;

        if (!$value) {
            return;
        }

        return $value;
    }

    /**
     * Check if an entry file is a stylesheet.
     */
    private function entryIsCss(?string $entry): bool
    {
        if (!$entry) {
            return false;
        }

        return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/', $entry) === 1;
    }

    /**
	 * Return array of files for given entry point.
	 */
    public function getCssFiles(?string $entry = null): array
    {
        $files = (array) $this->getManifestProperty($entry, 'css');

        if ($this->entryIsCss($entry) && $files === []) {
            $files = (array) $this->getManifestProperty($entry, 'file');
        }

        $imports = (array) $this->getManifestProperty($entry, 'imports');

        foreach ($imports as $import) {
            $files = array_merge($files, $this->getCssFiles($import));
        }

        // reverse the order to prevent specificity issues
        return array_reverse($files);
    }

    /**
	 * Return the url or path for the specified entry point.
	 */
    public function file(?string $entry = null, string $format = 'url', bool $proxy = false): ?string
    {
        $entry ??= option('jan-herman.vite.entry', 'index.js');
        $entry = ltrim($entry, '/');

        if ($this->isDev()) {
            $file_path = $this->devPath($entry);
            $file_url = $this->devUrl($entry, $proxy);
        } else {
            $manifest_property = $this->getManifestProperty($entry, 'file');

            if (!$manifest_property) {
                return null;
            }

            $file_path = $this->prodPath($manifest_property);
            $file_url = $this->prodUrl($manifest_property);
        }

        if (!F::exists($file_path)) {
            return null;
        }

        if ($format === 'path') {
            return $file_path;
        }

        return $file_url;
    }

    /**
	 * Return the path for the specified entry point.
	 */
    public function path(?string $entry = null): ?string
    {
        return $this->file($entry, 'path');
    }

    /**
	 * Return the url for the specified entry point.
	 */
    public function url(?string $entry = null, bool $proxy = false): ?string
    {
        return $this->file($entry, 'url', $proxy);
    }

    /**
	 * Return a `<link rel="preload">` tag for an entry point.
	 */
    public function preload(string $entry, array $options = []): ?string
    {
        $file_url = $this->url($entry);

        if (!$file_url) {
            return null;
        }

        $options = array_merge(['rel' => 'preload', 'href' => $file_url], $options);

        return '<link ' . Html::attr($options) . '>' . PHP_EOL;
    }

    /**
	 * Return a `<script>` tag for vite's client in development mode.
	 */
    public function client(): ?string
    {
        if (!$this->isDev()) {
            return null;
        }

        return js($this->devUrl('@vite/client'), ['type' => 'module']);
    }

    /**
	 * Return `<link>` tag for css file in development mode.
	 */
    private function devCss(?string $entry = null, array $options = []): ?string
    {
        if ($this->entryIsCss($entry) === false) {
            return null;
        }

        if (!F::exists($this->devPath($entry))) {
            return null;
        }

        return css($this->devUrl($entry), $options) . PHP_EOL;
    }

    /**
	 * Return `<link>` tags for each CSS file of an entry point.
	 */
    public function css(?string $entry = null, array $options = []): ?string
    {
        if ($this->isDev()) {
            return $this->devCss($entry, $options);
        }

        $files = $this->getCssFiles($entry);

        if (!$files) {
            return null;
        }

        $css = '';
        foreach ($files as $file) {
            if (in_array($file, $this->css_files)) {
                continue;
            }

            $css .= css($this->prodUrl($file), $options) . PHP_EOL;
            $this->css_files[] = $file;
        }

        return $css;
    }

    /**
	 * Return inline `<style>` for each CSS file of an entry point.
	 */
    public function inlineCss(?string $entry = null, array $options = []): ?string
    {
        if ($this->isDev()) {
            return $this->devCss($entry, $options);
        }

        $files = $this->getCssFiles($entry);

        if (!$files) {
            return null;
        }

        $options = array_merge(['type' => 'text/css'], $options);

        $css = '';
        foreach ($files as $file) {
            if (in_array($file, $this->css_files)) {
                continue;
            }

            $css .= '<style ' . Html::attr($options) . '>';
            $css .= F::read($this->prodPath($file));
            $css .= '</style>';

            $this->css_files[] = $file;
        }

        return $css;
    }

    /**
	 * Return a `<script>` tag for an entry point.
	 */
    public function js(?string $entry = null, array $options = []): ?string
    {
        $file_url = $this->url($entry);

        if ($file_url === null) {
            return null;
        }

        if ($this->isDev() === false && F::size($this->path($entry)) <= 29) {
            return null;
        }

        $options = array_merge(['type' => 'module'], $options);

        return js($file_url, $options) . PHP_EOL;
    }

    /**
     * Return a `<script>` tag for a virtual JS module.
     */
    public function virtualJs(?string $entry = null, array $options = []): ?string
    {
        if ($this->isDev() === false) {
            return null;
        }

        if ($this->path($entry) === null) {
            return null;
        }

        $options = array_merge(['type' => 'module'], $options);

        return js($this->getDevServer() . '/@kirby-vite' . $this->normalizePath($entry) . '.js', $options);
    }
}
