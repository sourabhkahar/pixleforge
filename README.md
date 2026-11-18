# Pixleforge

PromptEdit take-home: an image-generation app on **Laravel 12 + Inertia 2 + Vue 3** that resells
[Pixelforge](https://pixelforge.promptedit.dev/docs) generations at cost + 25%, with exact
money handling, signed and deduplicated webhooks, and a reconciled admin report.

## Quick start (Windows + XAMPP)

Requirements: PHP **8.2+**, Composer, Node 18+, MySQL (XAMPP).

```bash
composer install
npm install && npm run build
cp .env.example .env            # then fill in PIXELFORGE_API_KEY / PIXELFORGE_WEBHOOK_SECRET
php artisan key:generate
php artisan migrate             # say "yes" to create the `pixleforge` database
php artisan db:seed             # admin@example.com / test@example.com, password "password", $5 each
php artisan storage:link
```

Run (or `composer run dev` for server + queue + vite together):

```bash
php artisan serve               # http://localhost:8000
php artisan queue:work          # REQUIRED — submits, polls and stores images
php artisan schedule:work       # safety-net reconciler (every minute)
```

**Webhooks locally:** Pixelforge can't reach `localhost`. Either run a tunnel
(`cloudflared tunnel --url http://localhost:8000`, ngrok, Expose) and set the key's webhook URL to
`https://<tunnel>/webhooks/pixelforge`, or skip it — polling settles generations anyway, just a few
seconds later. Use a separate Pixelforge API key per environment.

## Deploying (Laravel Cloud)

1. Push to GitHub, create a Laravel Cloud app from the repo, attach a MySQL database.
2. Environment: `APP_ENV=production`, `APP_DEBUG=false`, `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`
   (from the **production** key), `FILESYSTEM_DISK=public`.
3. Enable a **queue worker** and the **scheduler**. Deploy commands: `php artisan migrate --force`,
   `php artisan storage:link` (or point the public disk at object storage).
4. In the Pixelforge dashboard set the key's webhook URL to `https://<app>/webhooks/pixelforge`
   and send a `ping` to check it.
5. `php artisan users:make-admin you@example.com` to unlock `/admin/report`.

## How money works

All amounts are **integer nanodollars** (1 USD = 10⁹) — the precision of Pixelforge's
`cost_usd` — so nothing is ever rounded or held in a float. `App\Support\Money` parses decimal
strings digit-by-digit.

| | |
|---|---|
| **Provider cost** | Σ tokens × rate / 1M, using the `usage` Pixelforge reports and the `rates` **locked onto that generation** at creation. |
| **User price** | provider cost × 1.25, rounded **up to the next whole nanodollar** (≤ $0.000000001). That is the only rounding in the system, and it never under-charges. Sub-cent prices are charged and displayed exactly (e.g. `$0.07435625`). |
| **Estimate** | Output tokens are exact for size × quality; image tokens are 765 each; text tokens are `ceil(chars/4)` ±15%; reasoning has a min/max per quality. We show the **expected** price (midpoint) and the min–max range. Rates come from `GET /v1/pricing` (cached; documented values as fallback). |
| **Balance** | On submit we **hold** the *maximum* possible price (so a user can never go negative), then on completion **release** the hold and **charge** the actual price. Failed generations are only released → free. |

The ledger (`credit_transactions`) is append-only and has a unique index on
`(generation_id, type)`, so a generation can be held, released and charged **at most once** —
enforced by the database, whatever webhooks and pollers race. `users.balance_nanos` is a cached
sum of the ledger, updated under a row lock in the same transaction.

## Robustness

* **Submission** (`SubmitGeneration` job) always sends the generation's UUID as `Idempotency-Key`.
  `429 rate/concurrency` and `503 overloaded` are retried after `Retry-After` (+ jitter), timeouts with
  exponential backoff — safe because of the idempotency key. Definite rejections (`content_policy_violation`,
  `validation_failed`, `daily_limit_exceeded`…) fail immediately and release the hold. After 15 minutes of
  retrying we give up and release.
* **One state machine** (`GenerationSync::apply`) handles the create response, webhooks and polls alike:
  it locks the row, only moves `queued → processing → succeeded|failed` forward, never leaves a terminal
  state, and settles money through the idempotent ledger.
* **Webhooks** (`POST /webhooks/pixelforge`): HMAC-SHA256 over `t.rawBody`, constant-time compare,
  300 s replay window, CSRF-exempt. The event id is the primary key of `webhook_events`; inserting it and
  applying the event share a transaction, so duplicates are no-ops and a failed apply is retried cleanly.
  Generations are matched by Pixelforge id **or** by our UUID in `metadata`, so a webhook that beats the
  create response still lands.
* **Polling fallback** (`PollGeneration`) every 10 s, plus a scheduled `pixelforge:reconcile` for anything
  that has gone quiet — so lost webhooks never strand a generation or its reserved credits.
* **Images** are copied to our storage when a generation succeeds (Pixelforge URLs expire after 24 h);
  the UI says so if a copy couldn't be made before expiry.

## Pages

* `/generate` — prompt, size, quality, reference URLs; live server-priced estimate (expected, range,
  amount reserved, balance after); history with live status, estimated vs actual cost, image.
* `/usage` — balance, reserved, spent, fake **Buy credits**, full ledger.
* `/admin/report` (admins) — per UTC day (by completion, Pixelforge's billing day) and per user: charged,
  provider cost, profit, with each day checked against `GET /v1/usage` (✓/✗).

## Tests

```bash
php artisan test
```

Cover: exact money maths (incl. the docs' worked maximum of `$0.373875000`), signature checks,
holds/charges/releases, duplicate + out-of-order + early webhooks, 429/503 retry, rejected prompts,
polling fallback, the admin report reconciling with `/v1/usage`, and a ledger-equals-balance invariant.

Prompt keywords for manual testing: `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`, `[succeed]`.
