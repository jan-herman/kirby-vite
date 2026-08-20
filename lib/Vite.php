<?php

namespace JanHerman\Vite;

use InvalidArgumentException;
use JsonException;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use Kirby\Http\Uri;
use Kirby\Http\Url;
use Kirby\Toolkit\Html;
use RuntimeException;
use Throwable;

class Vite
{
    protected static $instance;

    protected bool $isDev;
    protected string $outDir;
    protected string $rootDir;
    protected string $devServer;
    protected array $manifest;
    protected array $manifestNames;
    protected array $cssFiles = [];

    public static function getInstance()
    {
        return self::$instance ??= new self();
    }

    public function destroy(): void
    {
        self::$instance = null;
    }

    /**
     * Make sure a path starts with a slash and doesn't end with a slash.
     */
    protected function normalizePath(string $path): string
    {
        return Url::path($path, true, false);
    }

    /**
     * Check if we're in development mode using the configured detection mode.
     */
    public function isDev(): bool
    {
        if (isset($this->isDev)) {
            return $this->isDev;
        }

        $mode = option('jan-herman.vite.mode', 'auto');

        if (!is_string($mode)) {
            throw new InvalidArgumentException('Vite mode must be a string');
        }

        return $this->isDev = match ($mode) {
            'auto' => kirby()->environment()->isLocal() && $this->devServerIsRunning(),
            'development' => true,
            'production' => false,
            'hotfile' => $this->hotFileExists(),
            'manifest' => !$this->manifestExists(),
            default => throw new InvalidArgumentException(
                'Invalid Vite mode: ' . $mode
            ),
        };
    }

    /**
     * Check if the configured hot file exists.
     */
    protected function hotFileExists(): bool
    {
        $relativePath = option('jan-herman.vite.build.hotFile', 'src/.lock');
        $hotFile = kirby()->root('base') . $this->normalizePath($relativePath);

        return F::exists($hotFile);
    }

    /**
     * Check if the configured manifest exists.
     */
    protected function manifestExists(): bool
    {
        return F::exists($this->getManifestPath());
    }

    /**
     * Check if the Vite development server responds with a successful status.
     */
    protected function devServerIsRunning(): bool
    {
        try {
            $code = Remote::head(
                $this->devUrl('@vite/client'),
                ['timeout' => 1]
            )->code();

            return $code !== null && $code >= 200 && $code < 300;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get the output directory.
     */
    public function getOutDir(): string
    {
        return $this->outDir ??= $this->normalizePath(
            option('jan-herman.vite.build.outDir', 'dist')
        );
    }

    /**
     * Get the absolute path to the output directory.
     */
    public function getOutPath(): string
    {
        return kirby()->root('index') . $this->getOutDir();
    }

    /**
     * Get the absolute path to the manifest file.
     */
    protected function getManifestPath(): string
    {
        $relativePath = option('jan-herman.vite.build.manifest', '.vite/manifest.json');

        return $this->getOutPath() . $this->normalizePath($relativePath);
    }

    /**
     * Get Vite's root directory.
     */
    public function getRootDir(): string
    {
        return $this->rootDir ??= $this->normalizePath(
            option('jan-herman.vite.build.rootDir', 'src')
        );
    }

    /**
     * Get the absolute path to Vite's root directory.
     */
    public function getRootPath(): string
    {
        return kirby()->root('base') . $this->getRootDir();
    }

    /**
     * Get Vite's dev server URL without a trailing slash.
     */
    public function getDevServer(): string
    {
        if (isset($this->devServer)) {
            return $this->devServer;
        }

        $uri = new Uri([
            'scheme' => option('jan-herman.vite.server.https', false) ? 'https' : 'http',
            'host' => option('jan-herman.vite.server.host', 'localhost'),
            'port' => option('jan-herman.vite.server.port', 3000),
        ]);

        return $this->devServer = $uri->toString();
    }

    /**
     * Get the URL for the specified file for development mode.
     */
    public function devUrl(string $path, bool $proxy = false): string
    {
        $base = option('jan-herman.vite.server.base', '/');
        $server = $proxy && trim($base, '/')
            ? kirby()->url('index')
            : $this->getDevServer();
        $normalizedPath = $this->normalizePath($path);

        if ($server === '/') {
            $server = '';
        }

        // Vite serves files outside its root through the /@fs/ prefix.
        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $normalizedPath) === 1) {
            $absolutePath = realpath($this->devPath($path));

            if ($absolutePath !== false) {
                $normalizedPath = '/@fs' . $this->normalizePath($absolutePath);
            }
        }

        return $server
            . $this->normalizePath($base)
            . $normalizedPath;
    }

    /**
     * Get the source path for the specified development entry.
     */
    public function devPath(string $path): string
    {
        return $this->getRootPath() . $this->normalizePath($path);
    }

    /**
     * Get the URL for the specified file for production mode.
     */
    public function prodUrl(string $path): string
    {
        $root = kirby()->url('index');

        return ($root === '/' ? '' : $root)
            . $this->getOutDir()
            . $this->normalizePath($path);
    }

    /**
     * Get the path for the specified file for production mode.
     */
    public function prodPath(string $path): string
    {
        return $this->getOutPath() . $this->normalizePath($path);
    }

    /**
     * Read and parse the manifest file.
     */
    public function getManifest(): array
    {
        if (isset($this->manifest)) {
            return $this->manifest;
        }

        $manifestPath = $this->getManifestPath();

        if (!F::exists($manifestPath)) {
            throw new RuntimeException('Vite manifest not found: ' . $manifestPath);
        }

        $contents = F::read($manifestPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read Vite manifest: ' . $manifestPath);
        }

        try {
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Invalid Vite manifest JSON at ' . $manifestPath . ': ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!is_array($manifest)) {
            throw new RuntimeException('Vite manifest must contain a JSON object: ' . $manifestPath);
        }

        return $this->manifest = $manifest;
    }

    /**
     * Index manifest records by their stable Vite input names.
     */
    protected function getManifestNames(): array
    {
        if (isset($this->manifestNames)) {
            return $this->manifestNames;
        }

        $names = [];

        foreach ($this->getManifest() as $manifestEntry) {
            $name = $manifestEntry['name'] ?? null;

            if (is_string($name) && isset($names[$name]) === false) {
                $names[$name] = $manifestEntry;
            }
        }

        return $this->manifestNames = $names;
    }

    /**
     * Get the value of a manifest property for a specific entry.
     */
    public function getManifestProperty(?string $entry = null, string $key = 'file')
    {
        $entry ??= option('jan-herman.vite.entry', 'index.js');
        $manifestEntry = $this->getManifest()[$entry]
            ?? $this->getManifestNames()[$entry]
            ?? null;

        if ($manifestEntry === null) {
            return;
        }

        $value = $manifestEntry[$key] ?? null;

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

        return preg_match(
            '/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/',
            $entry
        ) === 1;
    }

    /**
     * Return whether an entry points at a Latte source.
     */
    private function entryIsLatte(?string $entry): bool
    {
        if (!$entry) {
            return false;
        }

        return str_ends_with(strtolower($entry), '.latte');
    }

    /**
     * Return array of files for given entry point.
     */
    public function getCssFiles(?string $entry = null): array
    {
        $files = (array) $this->getManifestProperty($entry, 'css');

        if ($files === [] && $this->entryIsCss($entry)) {
            $files = (array) $this->getManifestProperty($entry, 'file');
        }

        if ($files === [] && $this->entryIsLatte($entry)) {
            $file = $this->getManifestProperty($entry, 'file');

            if ($this->entryIsCss($file)) {
                $files = [$file];
            }
        }

        $imports = (array) $this->getManifestProperty($entry, 'imports');

        foreach ($imports as $import) {
            $files = array_merge($files, $this->getCssFiles($import));
        }

        // Reverse the order to prevent specificity issues.
        return array_reverse($files);
    }

    /**
     * Return the URL or path for the specified entry point.
     */
    public function file(?string $entry = null, string $format = 'url', bool $proxy = false): ?string
    {
        $entry ??= option('jan-herman.vite.entry', 'index.js');

        if ($this->isDev()) {
            $filePath = $this->devPath($entry);
            $fileUrl = $this->devUrl($entry, $proxy);
        } else {
            $manifestProperty = $this->getManifestProperty($entry, 'file');

            if (!$manifestProperty) {
                return null;
            }

            $filePath = $this->prodPath($manifestProperty);
            $fileUrl = $this->prodUrl($manifestProperty);
        }

        if (!F::exists($filePath)) {
            return null;
        }

        if ($format === 'path') {
            return $filePath;
        }

        return $fileUrl;
    }

    /**
     * Return the path for the specified entry point.
     */
    public function path(?string $entry = null): ?string
    {
        return $this->file($entry, 'path');
    }

    /**
     * Return the URL for the specified entry point.
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
        $fileUrl = $this->url($entry);

        if (!$fileUrl) {
            return null;
        }

        $options = array_merge(['rel' => 'preload', 'href' => $fileUrl], $options);

        return '<link ' . Html::attr($options) . '>' . PHP_EOL;
    }

    /**
     * Return a `<script>` tag for Vite's client in development mode.
     */
    public function client(): ?string
    {
        if (!$this->isDev()) {
            return null;
        }

        return js($this->devUrl('@vite/client'), ['type' => 'module']);
    }

    /**
     * Return a `<link>` tag for a CSS file in development mode.
     */
    private function devCss(?string $entry = null, array $options = []): ?string
    {
        $isLatte = $this->entryIsLatte($entry);

        if ($isLatte === false && $this->entryIsCss($entry) === false) {
            return null;
        }

        if (!F::exists($this->devPath($entry))) {
            return null;
        }

        $fileUrl = $this->devUrl($entry);

        if ($isLatte) {
            $fileUrl .= '?sfc=style';
        }

        return css($fileUrl, $options) . PHP_EOL;
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
            if (in_array($file, $this->cssFiles)) {
                continue;
            }

            $css .= css($this->prodUrl($file), $options) . PHP_EOL;
            $this->cssFiles[] = $file;
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
            if (in_array($file, $this->cssFiles)) {
                continue;
            }

            $css .= '<style ' . Html::attr($options) . '>';
            $css .= F::read($this->prodPath($file));
            $css .= '</style>';

            $this->cssFiles[] = $file;
        }

        return $css;
    }

    /**
     * Return a `<script>` tag for an entry point.
     */
    public function js(?string $entry = null, array $options = []): ?string
    {
        $isDev = $this->isDev();
        $fileUrl = $this->url($entry);

        if ($fileUrl === null) {
            return null;
        }

        if ($isDev && $this->entryIsLatte($entry)) {
            $fileUrl .= '?sfc=script';
        }

        if ($isDev === false) {
            $filePath = $this->path($entry);

            if ($filePath === null || F::size($filePath) <= 29) {
                return null;
            }
        }

        $options = array_merge(['type' => 'module'], $options);

        return js($fileUrl, $options) . PHP_EOL;
    }
}
