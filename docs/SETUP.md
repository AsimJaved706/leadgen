# Leadspace database and platform administration

## What is now connected

`/` is the public landing page. Its pricing cards read plan records from the API.
`/register` creates a user, a Free workspace and an owner membership in a transaction.
`/login` authenticates with Laravel Sanctum's session guard. Sessions live in the database; the browser receives an HttpOnly cookie. Mutations require a CSRF token.
`/app` is the authenticated database workspace: add/search/paginate/delete leads, switch among existing memberships, and create/list collections.
`/admin` is the super-admin console: live counts, paginated user/workspace searches, suspend/restore access, grant/revoke super-admin roles, change workspace plans, edit plan pricing/limits and inspect audit logs. All admin endpoints enforce the role on the server. Users cannot edit their own platform access, and the last active administrator is protected.
`/demo` retains the earlier localStorage demonstration. Its samples are independent of real accounts.

## Local setup (Windows or macOS/Linux)

Requirements: Node.js 22+, PHP 8.2+ with PDO SQLite (or PDO MySQL), Composer.

From this frontend directory:

```sh
npm ci
cd backend
composer install
# On a fresh checkout, copy .env.example to .env first.
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve --host=127.0.0.1 --port=8000
```

Run `npm run dev` from the frontend directory in another terminal. Open http://localhost:5173. Vite proxies `/api` to Laravel on port 8000, keeping browser authentication same-origin. Use the same host consistently, rather than switching between localhost and 127.0.0.1.

The current local database is `backend/database/database.sqlite`; it is excluded from version control. Migrations and the four plan records have already been applied. No administrator password or sample user is seeded.

### Create the first super administrator

```sh
cd backend
php artisan leadspace:admin admin@your-company.com
```

The command prompts privately for a password and confirmation; use at least 12 characters with letters and numbers. It refuses to overwrite existing accounts. Sign in at `/login`, which redirects super admins to `/admin`. Subsequent admins can be granted access through Users. Never expose the CLI command through an unauthenticated HTTP endpoint.

An administrator is not automatically a workspace member. Register a normal account for lead management. Admins can manage workspace entitlement and suspension without being given implicit access to private lead records.

## MySQL Docker development stack

The Compose stack includes frontend Nginx, Laravel, MySQL 8.4, a database queue worker, Redis and Mailpit. Redis is available but the current cache/queue drivers intentionally use the database. Start Docker Desktop first.

1. Copy `.env.docker.example` to the root `.env` and set separate strong database passwords.
2. Generate a fresh application key with `php backend/artisan key:generate --show` and place it in the root `.env` as APP_KEY. Do not publish it.
3. Run `docker compose up --build -d`.
4. Run `docker compose exec api php artisan leadspace:admin admin@your-company.com`.
5. Open http://localhost:8080 and mail testing at http://localhost:8025.

The API container runs migrations and the idempotent PlanSeeder. MySQL data is persisted in `mysql-data`. The default database seeder creates plans only, never users with a known password. Compose uses Laravel's development HTTP server; for production replace it with a supervised PHP-FPM or application-server deployment behind TLS. The Docker stack was not started in this session because the Docker daemon was unavailable.

## Environment variables

Backend settings are in `backend/.env`. `APP_KEY` encrypts cookies and must remain stable and secret. `APP_DEBUG=false` is required in deployed environments. `APP_URL` is the public origin. `DB_CONNECTION=sqlite` uses the local database; `mysql` requires DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD. `SESSION_DRIVER=database`, `CACHE_STORE=database`, and `QUEUE_CONNECTION=database` are supported by the migrations. Set `SESSION_SECURE_COOKIE=true` behind HTTPS; the default SameSite policy is lax and HttpOnly is enabled. Configure MAIL_* for future email workflows.

The frontend uses same-origin `/api`; no secrets or database credentials belong in Vite variables. `LEADSPACE_API_TARGET` overrides the development proxy target for isolated tests. The old VITE_DEMO_MODE and VITE_API_URL placeholders are not used by the live application.

## Database design

Migrations create users, workspaces, workspace_user, plans, subscriptions, api_keys, lead_lists, leads, lead_list_items, imports, import_failures, exports, usage_records and audit_logs, plus Laravel session/cache/job/password reset tables. Foreign keys cascade child records where appropriate and restrict deleting workspace owners or referenced plans. Memberships and list entries have composite unique constraints. Imports have unique workspace/request UUIDs. Lead indexes start with workspace_id followed by duplicate, contact, location or timestamp fields. Multiple/dynamic fields are JSON. API key storage is hash-only; key issuance endpoints remain future work.

Lead creation locks its workspace, applies the current database plan limit, and checks duplicates in this order: Place ID, CID, normalized domain, normalized phone, normalized name/address. List creation similarly locks the workspace and enforces the list limit. Cross-workspace IDs return 404. Suspended accounts/workspaces return 403. Viewers can read only.

## API contract

All responses use JSON. Send `Accept: application/json`. Before a mutation GET `/api/csrf`, retain cookies, and send its `token` value as `X-CSRF-TOKEN`. Refresh the token after login/logout/registration. Login and registration are limited to five requests per minute per IP.

| Method | Route | Access |
| --- | --- | --- |
| GET | /api/plans | Public active plan catalog |
| POST | /api/register | name, email, password, password_confirmation, workspace |
| POST | /api/login | email, password |
| GET | /api/me | Active session |
| POST | /api/logout | Active session + CSRF |
| GET | /api/workspaces/{id}/summary | Workspace member |
| GET, POST | /api/workspaces/{id}/leads | Read membership / non-viewer writes |
| DELETE | /api/workspaces/{id}/leads/{lead} | Non-viewer, matching workspace |
| GET, POST | /api/workspaces/{id}/lists | Read membership / non-viewer writes |
| GET | /api/admin/overview | Super admin |
| GET | /api/admin/users, /workspaces | Super admin, q and page parameters |
| PATCH | /api/admin/users/{id} | suspended, is_super_admin |
| PATCH | /api/admin/workspaces/{id} | suspended, plan_id |
| GET, PATCH | /api/admin/plans, /api/admin/plans/{id} | Plan catalog / validated edits |
| GET | /api/admin/audit-logs | Super admin, paginated |
| GET, PUT | /api/workspaces/{id}/email-settings | Owner/admin SMTP configuration |
| POST | /api/workspaces/{id}/email-settings/test | Send SMTP test to current owner/admin |
| GET, POST, PUT, DELETE | /api/workspaces/{id}/email-templates | Owner/admin reusable templates |
| GET, POST | /api/workspaces/{id}/email-campaigns | Owner/admin campaign history/create |
| POST | /api/workspaces/{id}/email-campaigns/{campaign}/send | Start a draft campaign |
| POST | /api/workspaces/{id}/email-campaigns/{campaign}/cancel | Cancel draft/scheduled campaign |

Lead search accepts q, category, sort (name/created_at/average_rating), direction and page. API pagination is server-side. Plan edits change catalog records and connect them to Stripe Price IDs; Stripe Checkout and signed webhooks create and update subscriptions.

## Stripe Billing setup

Create one recurring monthly Price and one recurring yearly Price for each paid plan in Stripe. In **Admin console → Plans**, save the corresponding `price_...` IDs. Then add these server-only values to `backend/.env`:

```dotenv
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_AUTOMATIC_TAX=false
FRONTEND_URL=http://localhost:5173
```

Run `php artisan config:clear` after changing the environment. Register the public webhook URL `https://your-domain.example/api/stripe/webhook` for `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, and `invoice.payment_failed`. For local testing with Stripe CLI:

```sh
stripe listen --forward-to http://127.0.0.1:8000/api/stripe/webhook
```

Copy the CLI's `whsec_...` value to `STRIPE_WEBHOOK_SECRET`. Workspace owners and admins can start hosted Checkout from **Billing** and open Stripe's customer portal for card updates, invoices, plan changes, and cancellation. The API never accepts an arbitrary Price ID from the browser; it selects the configured ID from the requested plan and billing interval. Signed webhook events are stored idempotently and update both the subscription record and workspace entitlement. Configure products, prices, portal behavior, taxes, branding, receipts, and live credentials in Stripe before production use.

## Verification

```sh
npm run build
npm test
node platform-check.mjs
cd backend
php artisan test
php vendor/bin/pint --test app routes database tests
```

The browser check requires installed Chrome. It creates a disposable SQLite database and a random admin password, starts dedicated test servers on 8001/5174, tests registration, database persistence, collections, admin authorization, suspension, plan edits, audit logs and CSRF, then removes the test database. It does not seed users into your development database. Unit/feature tests use SQLite in memory. MySQL runtime validation is still required before deploying on MySQL.

## Deployment, backup and remaining scope

Serve the Vite build with route fallback to index.html and proxy `/api` to Laravel on the same origin. Use HTTPS, production cookie settings, a private database, least-privilege database users, restricted storage permissions and supervised workers. Run `php artisan migrate --force` during deployment. Configure persistent logs and monitor failed jobs; use `php artisan queue:failed` and `queue:retry` after fixing failures.

For MySQL, schedule encrypted `mysqldump --single-transaction` backups using a protected credentials file and regularly verify a restore in a separate database. For local SQLite, stop the application before copying the database, or use SQLite's backup API; do not copy only the main file while WAL writes are active.

### Email marketing operations

Workspace owners and admins configure SMTP under **Email settings**. Standard SMTP works with Mailchimp Transactional, SendGrid, Amazon SES SMTP, Mailgun SMTP, Mailpit and other providers. Passwords use Laravel's encrypted database cast and are never returned by the API. After saving, use **Send test email** before launching a campaign. Configure SPF, DKIM and DMARC with the provider.

Users can create multiple HTML templates with lead variables such as `{{lead.name}}`, `{{lead.city}}`, `{{lead.email}}`, `{{lead.phone}}`, `{{lead.website}}`, `{{lead.country}}` and `{{lead.category}}`. Unsafe scripts, embedded objects, forms and event handlers are removed on save. Campaigns can target all leads with valid email addresses or one lead list, run immediately, remain as drafts, or be scheduled. Recipient email/name values are snapshotted when processing begins. Duplicate, invalid and unsubscribed addresses are suppressed. Each delivered message receives a signed unsubscribe URL.

Run both background processes in production:

```sh
php artisan queue:work --queue=default --tries=3 --timeout=90
php artisan schedule:work
```

The scheduler releases due campaigns each minute; queue workers build recipient snapshots and deliver messages. Compose includes `worker` and `scheduler` services. Plans currently allow 100/2,500/25,000/100,000 queued emails per month for Free/Starter/Professional/Agency. Quota is reserved when a campaign is prepared. SMTP itself cannot guarantee exactly-once delivery if a worker dies after the provider accepts a message but before the database records success; use a transactional provider's event/idempotency API for stricter guarantees.

Password-reset email delivery, team invitations, automatic extension sync, queued bulk imports/exports, list membership editing, lead editing, provider delivery/open/click webhooks and automated bounce suppression remain outside this iteration. Stripe Billing is implemented, but live payments require real Stripe keys, recurring Price IDs and a registered webhook endpoint. The demo's import/export UI is independent from the live database. The MySQL container configuration needs verification on a running Docker installation.
