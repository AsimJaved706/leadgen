# Leadspace

A React/TypeScript frontend and Laravel 12 database API for business leads, with a public landing page and server-protected super-admin console.

## Start locally

The database and plans are already initialized in this workspace. Start the two applications in separate terminals:

```sh
# From this directory
npm run dev
```

```sh
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://localhost:5173. For fresh installation, MySQL, Docker, environment variables and deployment, see [the setup guide](docs/SETUP.md).

## Create your super administrator

```sh
cd backend
php artisan leadspace:admin admin@your-company.com
```

Enter a password privately when prompted. No default administrator credentials are installed. Sign in at `/login` to enter the admin console.

## Application routes

- `/`: responsive landing page with plan pricing read from the database.
- `/register`, `/login`: registration and secure session authentication.
- `/app`: persistent workspace leads, search, collections, SMTP settings, reusable email templates, and scheduled bulk campaigns.
- `/admin`: platform counts, user/workspace management, suspension, admin roles, plan pricing and limits, audit history.
- `/demo`: separate sample workspace with local CSV/JSON imports, Excel/CSV exports, filtering and selection.

The live workspace uses the database. The demo continues to use localStorage and does not affect real accounts. The neighboring Chrome extension remains unchanged.

## Verification

```sh
npm run build
npm test
npm run test:platform
cd backend
php artisan test
php vendor/bin/pint --test app routes database tests
```

The platform browser test requires Chrome, uses a disposable database and random credentials, and cleans up after itself. It verifies registration, persistent leads, lists, admin access restrictions, suspension, plan edits, audit history, CSRF and mobile layouts.

## Scope

SQLite runs locally; MySQL Docker configuration is included but was not run because the Docker daemon was unavailable. Stripe Checkout, signed webhook subscription synchronization and the Stripe customer portal are implemented. Live Stripe credentials and plan Price IDs must be configured before accepting payments. Invitation delivery, password-reset delivery, automatic extension sync and queued bulk import/export remain unimplemented. See [current architecture, API and deployment notes](docs/SETUP.md).
