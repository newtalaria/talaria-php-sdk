# Talaria PHP SDKs

Composer monorepo for the official PHP SDKs.

| Package | Composer name | Path |
| --- | --- | --- |
| Core | [`talaria/talaria`](packages/talaria) | `packages/talaria` |
| Silverstripe | [`talaria/silverstripe`](packages/silverstripe) | `packages/silverstripe` |
| Laravel | [`talaria/laravel`](packages/laravel) | `packages/laravel` |
| Alias | [`talaria/logging`](packages/logging) | metapackage → Silverstripe |

```bash
composer require talaria/talaria
composer require talaria/silverstripe
composer require talaria/laravel
```

Source: [github.com/newtalaria/talaria-php-sdk](https://github.com/newtalaria/talaria-php-sdk)

Docs: [PHP](https://www.newtalaria.com/docs/sdk/php) · [Silverstripe](https://www.newtalaria.com/docs/sdk/silverstripe) · [Laravel](https://www.newtalaria.com/docs/sdk/laravel)

Packagist (after the first tag): submit each subdirectory from this repo — `packages/talaria`, `packages/silverstripe`, `packages/laravel`, `packages/logging`. That claims the `talaria` vendor. The first package you submit should be `talaria/talaria`.

## Develop

```bash
cd packages/talaria && composer update && composer test && composer phpstan
cd ../silverstripe && composer config repositories.talaria path ../talaria && composer update && composer test
cd ../laravel && composer config repositories.talaria path ../talaria && composer update && composer test
```
