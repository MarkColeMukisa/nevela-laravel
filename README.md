# nevela/laravel

The Laravel side of [Nevela](https://nevela-docs.vercel.app): describe a resource once and get its model, migration, validation, policy and REST API, plus the Next.js dashboard screens for it.

```sh
php artisan nevela:resource Product --fields="name:string, sku:string!, price:money, kind:enum(stock|digital), notes:text?"
php artisan migrate
```

## Start a new app

The quickest way is the create command, which sets up Laravel, this package and the dashboard together:

```sh
pnpm create nevela my-app
```

## Add it to an existing Laravel app

```sh
php artisan install:api          # Sanctum, if you don't have it yet
composer require nevela/laravel
```

Add Sanctum's trait to your user model:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

Needs PHP 8.2 or newer and Laravel 11, 12 or 13.

## Commands

| Command | What it does |
|---|---|
| `nevela:resource` | Describe a resource and generate everything for it. |
| `nevela:generate` | Regenerate after changing a descriptor. Your own code is kept. |
| `nevela:seed` | Fill a resource with plausible records, and print how long it took. |
| `nevela:user` | Create someone who can sign in. |
| `nevela:update` | Update the package, the generated code and the dashboard. |

Full documentation: https://nevela-docs.vercel.app

## Where this code lives

This repository is a read-only copy of `packages/laravel` from [MarkColeMukisa/nevela](https://github.com/MarkColeMukisa/nevela), kept so Composer can install the package. Open issues and pull requests there.

## License

MIT. Copyright (c) 2026 Mark Cole MUKISA.
