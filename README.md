# Kirby Vite

> Kirby plugin that handles development and production mode of Vite.

## Options

### mode

Default: `'auto'`

- `auto`: Development when Kirby detects a local environment and the Vite development server responds; production otherwise.
- `development`: Always use development assets.
- `production`: Always use production assets from the manifest.
- `hotfile`: Development when `build.hotFile` exists; production otherwise.
- `manifest`: Production when `build.manifest` exists; development otherwise.

### entry

Default: `'index.js'`

### server.host

Default: `localhost`

### server.port

Default: `3000`

### server.https

Default: `false`

### server.base

Default: `/`

### build.rootDir

Default: `'src'`

### build.outDir

Default: `'dist'`

### build.hotFile

Default: `'src/.lock'`

### build.manifest

Default: `.vite/manifest.json`
