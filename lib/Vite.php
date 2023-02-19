<?php

namespace JanHerman\Vite;

use Kirby\Http\Url;
use Kirby\Http\Uri;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Html;

class Vite
{
    protected static $instance;

    protected string $out_dir;
    protected string $root_dir;
    protected string $dev_server;
    protected array $manifest;
    protected array $css_files = [];

    public static function getInstance()
    {
        return self::$instance ??= new self();
    }

    /**
     * Make sure, a directory starts with a slash and doesn't end with a slash.
     */
    protected function sanitizeDir(string $dir): string
    {
        return Url::path($dir, true, false);
    }

    /**
     * Check if we're in development mode.
     * Look for `.lock` file in vite's root dir as indicator.
     */
    protected function isDev(): bool
    {
        $lock_file = kirby()->root('base') . $this->getRootDir() . '/.lock';
        return F::exists($lock_file);
    }

    /**
     * Get the output directory.
     */
    public function getOutDir(): string
    {
        return $this->out_dir ??= $this->sanitizeDir(option('jan-herman.kirby-vite.build.outDir', 'dist'));
    }

    /**
     * Get vite's root directory.
     */
    public function getRootDir(): string
    {
        return $this->root_dir ??= $this->sanitizeDir(
            option('jan-herman.kirby-vite.build.rootDir', 'src')
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
			'scheme' => option('jan-herman.kirby-vite.server.https', false) ? 'https' : 'http',
			'host'   => option('jan-herman.kirby-vite.server.host', kirby()->environment()->host()),
			'port'   => option('jan-herman.kirby-vite.server.port', 3000)
		]);

		return $this->dev_server = $uri->toString();
	}

    /**
     * Get the url for the specified file for development mode.
     */
    public function devUrl(string $path): string
	{
		return $this->getDevServer() . '/' . $path;
	}

    /**
     * Get the path for the specified file for development mode.
     */
    public function devPath(string $path): string
	{
		return kirby()->root('base') . $this->getRootDir() . '/' . $path;
	}

    /**
     * Get the URL for the specified file for production mode.
     */
    public function prodUrl(string $path): string
	{
        $root = kirby()->url('index');
        return ($root === '/' ? '' : $root) . $this->getOutDir() . '/' . $path;
	}

    /**
     * Get the path for the specified file for production mode.
     */
    public function prodPath(string $path): string
	{
        $root = kirby()->root('index');
        return ($root === '/' ? '' : $root) . $this->getOutDir() . '/' . $path;
	}

    /**
     * Read and parse the manifest file.
     */
    public function getManifest(): array
    {
        if (isset($this->manifest)) {
            return $this->manifest;
        }

        $manifest_path = kirby()->root() . $this->getOutDir() . '/manifest.json';

        if (!F::exists($manifest_path)) {
            return [];
        }

        return $this->manifest = json_decode(F::read($manifest_path), true);
    }

    /**
     * Get the value of a manifest property for a specific entry.
     */
    public function getManifestProperty(?string $entry = null, $key = 'file')
    {
        $entry ??= option('jan-herman.kirby-vite.entry', 'index.js');
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
	 * Return array of files for given entry point.
	 */
    private function getCssFiles(?string $entry = null): array
    {
        $files = (array) $this->getManifestProperty($entry, 'css');
        $imports = (array) $this->getManifestProperty($entry, 'imports');

        if ($imports) {
            foreach ($imports as $import) {
                $imported_files = (array) $this->getManifestProperty($import, 'css');
                $files = array_merge($files, $imported_files);
            }
        }

        // reverse the order to prevent specificity issues
        $files = array_reverse($files);

        // make sure every file is imported only once
        $files = array_diff($files, $this->css_files);
        $this->css_files = array_merge($this->css_files, $files);

        return $files;
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
	 * Return `<link>` tags for each CSS file of an entry point.
	 */
    public function css(?string $entry = null, array $options = []): ?string
    {
        if ($this->isDev()) {
            return null;
        }

        $files = $this->getCssFiles($entry);

        if (!$files) {
            return null;
        }

        $css = '';
        foreach ($files as $file) {
            $css .= css($this->prodUrl($file), $options) . PHP_EOL;
        }

        return $css;
    }

    /**
	 * Return inline `<style>` for each CSS file of an entry point.
	 */
    public function inlineCss(?string $entry = null, array $options = []): ?string
    {
        if ($this->isDev()) {
            return null;
        }

        $files = $this->getCssFiles($entry);

        if (!$files) {
            return null;
        }

        $options = array_merge(['type' => 'text/css'], $options);

        $css = '';
        foreach ($files as $file) {
            $css .= '<style ' . Html::attr($options) . '>' . PHP_EOL;
            $css .= F::read($this->prodPath($file));
            $css .= '</style>' . PHP_EOL;
        }

        return $css;
    }

    /**
	 * Return a `<script>` tag for an entry point.
	 */
    public function js(?string $entry = null, array $options = []): ?string
    {
        if ($this->isDev()) {
            $entry = $entry ?? option('jan-herman.kirby-vite.entry', 'index.js');

            if (!F::exists($this->devPath($entry))) {
                return null;
            }

            $file_url = $this->devUrl($entry);
        } else {
            $manifest_property = $this->getManifestProperty($entry, 'file');

            if (!$manifest_property || F::size($this->prodPath($manifest_property)) <= 29) {
                return null;
            }

            $file_url = $this->prodUrl($manifest_property);
        }

        $options = array_merge(['type' => 'module'], $options);

        return js($file_url, $options) . PHP_EOL;
    }

    /**
	 * Return a `<link rel="preload">` tag for an entry point.
	 */
    public function preload(string $entry = null, array $options = []): ?string
    {
        if ($this->isDev()) {
            $file_path = $this->devPath($entry);
            $file_url = $this->devUrl($entry);
        } else {
            $manifest_property = $this->getManifestProperty($entry, 'file');
            $file_path = $this->prodPath($manifest_property);
            $file_url = $this->prodUrl($manifest_property);
        }

        if (!F::exists($file_path)) {
            return null;
        }

        $options = array_merge(['rel' => 'preload', 'href' => $file_url], $options);

        return '<link ' . Html::attr($options) . '>' . PHP_EOL;
    }
}
