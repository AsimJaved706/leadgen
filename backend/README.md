# Leadspace Laravel API

See [the application setup guide](../docs/SETUP.md) for database setup, secure administrator creation, routes, tests, Docker and deployment.

```sh
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan leadspace:admin admin@your-company.com
php artisan serve
```

Copy `.env.example` to `.env` before initial setup. The seeder creates plans only. No default credentials exist.

```sh
php artisan test
php vendor/bin/pint --test app routes database tests
```
