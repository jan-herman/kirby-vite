# Changelog

## [2.5.0] - 2025-10-31
### Added
- `vite()->url()` & `vite()->path()` methods
- path normalization

### Changed
- more logical order of methods in `Vite` class
- `sanitizeDir()` helper method renamed to `normalizePath()`
- refactoring using new methods

### Deprecated
- `vite()->file()` method (will be set to private in 3.0.0)


## [2.4.0] - 2025-10-14
### Added
- support for directly importing stylesheets
- `virtualJs()` method to create virtual JS modules
- `server.base` option
- `build.manifest` & `build.hotFile` options

### Changed
- `getCssFiles()` method now supports nested imports
- `getCssFiles()` & `isDev()` methods are now public
- `server.host` option default value changed from kirby()->environment()->host() to 'localhost' (⚡ potential BC)


## [2.3.0] - 2025-06-09
### Added
- `vite()->file()` method to access assets


## [2.2.0] - 2025-06-06
### Added
- legacy `router.php` to ensure compatibility with Kirby 4.7.1


## [2.1.0] - 2025-01-05
### Added
- `destroy()` method (useful for static site generation to reset the instance after each page build)


## [2.0.0] - 2024-02-15
### Added
- Support for Vite 5
    - Breaking change: manifest.json was moved to .vite/ directory


## [1.0.3] - 2023-02-21
### Changed
- Remove unnecessary PHP_EOL from the inlineCss method output


## [1.0.2] - 2023-02-19
### Changed
- Plugin name changed from `kirby-vite` to `vite`


## [1.0.0] - 2023-02-19
### Added
- Initial release
