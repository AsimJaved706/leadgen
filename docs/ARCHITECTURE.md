# Leadspace architecture and delivery plan

**Implementation update:** Laravel migrations, session authentication, live lead/list storage, super-admin APIs and a public landing page now exist in `backend/` and `src/Platform.tsx`. See [SETUP.md](SETUP.md) for the current implemented scope and remaining work. The original plan below is retained as broader roadmap context.

The existing Manifest V3 extension lives in `../google-maps-extractor-main`. It stores leads in chrome.storage.local and exports CSV/XLSX using Tabulator. Its camelCase `placeID`, `cID`, `reviewCount`, and `averageRating` fields need mapping at API ingestion. Preserve unknown fields as JSON and preserve the local export path.

## Application boundary
This workspace contains the React/TypeScript frontend. Its initial local demonstration persists lead lists, imports, leads, and preferences in localStorage. It is not a secure multi-tenant service. Laravel authentication, queues, billing, and extension synchronization require the backend described below.

## Planned relational schema
Use PostgreSQL. All tenant resources have a non-null workspace_id and indexed created_at. users owns workspaces through workspace_user (unique workspace_id/user_id, role owner/admin/member/viewer). plans defines JSON limits; subscriptions links workspaces to Stripe. api_keys stores a SHA-256 token hash, prefix, abilities, revoked_at and last_used_at. lead_lists and leads join through lead_list_items with a unique list/lead pair. leads includes all requested contact/location/source fields; secondary phones, additional emails, categories, hours, social profiles, images, action links, dynamic fields and raw source data are JSONB. Index workspace_id paired with place_id, cid, website_domain, normalized_phone, name_address_hash, email, category, city, country and collected_at. imports has a unique workspace_id/request_uuid pair and processing state; import_failures stores row errors. exports tracks queued artifacts; usage_records tracks monthly counters; audit_logs records actor/action/resource. Invitations use expiring hashed tokens.

## Planned API
Sanctum cookie authentication: POST /api/register, /login, /logout, /forgot-password, /reset-password; GET /api/user. Workspace membership is checked before resolving any tenant resource. Workspace routes: /api/workspaces, /api/workspaces/{workspace}/members, /invitations, /leads, /lists, /imports, /exports, /api-keys, /usage, /audit-logs. Lead index accepts q, filters, sort, page and per_page. Owners alone access /billing/checkout and /billing/portal. Stripe webhook verifies the signature with the raw request body.

Extension Bearer-key routes: POST /api/extension/leads/batch, GET /api/extension/workspace, GET /api/extension/lists, POST /api/extension/imports, GET /api/extension/imports/{import}. Limit batches to 100. Resolve workspace exclusively from the key. Validate each record, preserve dynamic data, detect duplicates by Place ID → CID → domain → phone → name/address within workspace. Lock usage counters and deduplication in transactions. Queue larger imports. Unique request UUIDs return the original result on retry; retain extension local records until acknowledgement.

## Implementation sequence and release gates
1. Deliver frontend and validate import/export/filter flows.
2. Scaffold sibling Laravel 12 service with Sanctum, Cashier, PostgreSQL, Redis, Mailpit and queue worker.
3. Implement migration/model/policy/factory coverage and cross-tenant authorization tests for every resource.
4. Add queued idempotent ingestion, quota enforcement, secure keys and extension retry outbox.
5. Integrate authentication, invitations and verified Stripe webhooks.
6. Release only after backend tests, frontend browser tests, static analysis and production builds pass. Deployment needs TLS, restricted CORS, secure cookies, encrypted secrets, daily tested database backups, queue supervision and failed-job alerts.
