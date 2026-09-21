# talaria/silverstripe

Silverstripe 4.13+ / 5 / 6 adapter for [Talaria](https://www.newtalaria.com). Depends on [`talaria/talaria`](https://packagist.org/packages/talaria/talaria).

Docs: [Silverstripe SDK guide](https://www.newtalaria.com/docs/sdk/silverstripe)

## Install

```bash
composer require talaria/silverstripe
```

```bash
TALARIA_DSN="https://api.newtalaria.com"
TALARIA_API_KEY="tal_live_…"
TALARIA_ENVIRONMENT="production"
# TALARIA_ENABLE_TRACING="true"
```

```bash
vendor/bin/sake dev/build flush=1
```

The module keeps `installer-name: talaria-logging` so existing `# talaria-logging` YAML includes still resolve.

Optional alias: `composer require talaria/logging` (metapackage → this package).

Full walkthrough: [docs/silverstripe.md](docs/silverstripe.md).

## License

MIT
