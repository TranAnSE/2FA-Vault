# Sentry Setup Guide

2FA-Vault integrates with [Sentry](https://sentry.io) for production error
and performance monitoring. Sentry is **optional and disabled by default** —
leaving `SENTRY_DSN` empty keeps the SDK fully inert (no boot, no network
calls, no overhead).

Both **Sentry SaaS** (sentry.io) and **self-hosted Sentry** are supported.
The integration is identical; only the DSN origin differs.

> **Telescope is not used.** Laravel Telescope is a development profiler and
> is not recommended for production (it logs every query/mail/job and can leak
> sensitive data). Sentry is the production-grade choice for an OTP vault.

---

## 1. Why use Sentry here

- Real-time exception capture with full stack traces and release tagging.
- Performance monitoring (optional) for slow transactions.
- Alerting to Slack / email / PagerDuty when error rates spike.
- Release health: know which deploy introduced a regression.
- Self-hostable — your error data never leaves your infrastructure.

For a zero-knowledge OTP vault this matters because failures in the E2EE
path, Passport token handling, or backup jobs are exactly the kind of silent
bugs you want surfaced immediately.

---

## 2. Prerequisites

- The `sentry/sentry-laravel` Composer package (declared in `composer.json`).
  If you deployed from the production Docker image, it is already installed.
  For non-Docker installs run `composer install` once.
- A Sentry project (SaaS or self-hosted) and its DSN.

---

## 3. Get a DSN

### Option A — Sentry SaaS (sentry.io)

1. Create an account at https://sentry.io.
2. Create a project: platform **Laravel**, name it e.g. `2fa-vault-prod`.
3. Go to **Settings → Projects → &lt;project&gt; → Client Keys (DSN)**.
4. Copy the DSN, which looks like
   `https://<key>@o<org>.ingest.sentry.io/<project>`.

### Option B — Self-hosted Sentry

1. Deploy self-hosted Sentry following the official guide:
   https://develop.sentry.dev/self-hosted/
2. Create a Laravel project inside your instance.
3. Copy the DSN from the project's **Client Keys** settings. It will be shaped
   like `https://<key>@<your-sentry-host>/<project>`.

---

## 4. Enable Sentry in 2FA-Vault

Set these in your `.env` (or via the `environment:` block of the `app`
service in `docker-compose.prod.yml`, which already wires them through):

```dotenv
SENTRY_DSN=https://<key>@o<org>.ingest.sentry.io/<project>
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=<git commit sha or image tag>
SENTRY_SAMPLE_RATE=1.0
SENTRY_TRACES_SAMPLE_RATE=0.0
```

| Variable                  | Recommended | Meaning                                                    |
| ------------------------- | ----------- | ---------------------------------------------------------- |
| `SENTRY_DSN`              | (your DSN)  | **Empty = disabled.** This is the only required setting.  |
| `SENTRY_ENVIRONMENT`      | `production`| Tag so you can filter prod vs staging events.             |
| `SENTRY_RELEASE`          | commit sha  | Lets Sentry correlate errors to a deploy.                 |
| `SENTRY_SAMPLE_RATE`      | `1.0`       | Send 100% of unhandled exceptions. Lower to shed volume.  |
| `SENTRY_TRACES_SAMPLE_RATE` | `0.0`     | Performance tracing. `0.0` = off. Raise to `0.1`–`0.25` to sample. |

Then restart the app container so the SDK boots with the new DSN:

```bash
docker compose -f docker-compose.prod.yml up -d app
# or, if already running:
docker compose -f docker-compose.prod.yml restart app
```

---

## 5. Verify the integration

1. Temporarily enable the test route:

   ```dotenv
   SENTRY_TEST_ENABLED=true
   ```

   Restart the app so the route is registered:

   ```bash
   docker compose -f docker-compose.prod.yml restart app
   ```

2. Trigger a test exception:

   ```bash
   curl -i https://vault.example.com/sentry-test
   # expect HTTP 500
   ```

3. Open your Sentry dashboard. The exception
   `"Sentry integration test from 2FA-Vault"` should appear within a few
   seconds (tagged with your `SENTRY_ENVIRONMENT` and `SENTRY_RELEASE`).

4. **Disable the test route again** so it is not exposed in production:

   ```dotenv
   SENTRY_TEST_ENABLED=false
   ```

   ```bash
   docker compose -f docker-compose.prod.yml restart app
   ```

If the event does not arrive, check:

- `SENTRY_DSN` is non-empty and matches the project's Client Key.
- The container can reach the Sentry ingest host (egress firewall, DNS).
- `storage/logs/laravel.log` for any Sentry SDK warnings.

---

## 6. Disable Sentry

Empty the DSN and restart:

```dotenv
SENTRY_DSN=
```

```bash
docker compose -f docker-compose.prod.yml restart app
```

With an empty DSN, `sentry/sentry-laravel` does not register its container
binding, so the `reportable` hook in `app/Exceptions/Handler.php` is a
guarded no-op (`app()->bound('sentry')` returns false). There is no residual
overhead.

---

## 7. Privacy and security notes

This is an OTP vault, so privacy configuration is non-negotiable:

- **`send_default_pii` is hardcoded to `false`** in `config/sentry.php`. Do
  not change it. It prevents Sentry from attaching request IPs, cookies,
  authenticated user IDs, or session data to events.
- Master passwords and derived encryption keys **never** leave the browser
  (zero-knowledge E2EE) and therefore can never appear in a Sentry event.
- OTP secrets are encrypted at rest on the server; even a full stack trace on
  the encryption path will reference the encrypted payload, not the plaintext.
- If you ever extend the codebase to attach custom Sentry scope data
  (`Sentry\configureScope`), do not include secrets, tokens, or `request()->all()`
  payloads. Review the data-flow rules in
  `docs/development/security-guidelines.md` first.

---

## 8. How it is wired (for maintainers)

- `composer.json` declares `sentry/sentry-laravel: ^4.0`.
- `config/sentry.php` reads all behaviour from env vars and forces
  `send_default_pii => false`.
- `app/Exceptions/Handler.php` registers a `reportable` callback that calls
  `\Sentry\Laravel\Integration::captureUnhandledException($e)` only when the
  SDK is booted (`app()->bound('sentry')` and `config('sentry.dsn')` truthy).
- `routes/web.php` registers `GET /sentry-test` only when
  `SENTRY_TEST_ENABLED=true`.
- `docker-compose.prod.yml` passes all `SENTRY_*` env vars through to the
  `app` container, defaulting `SENTRY_DSN` to empty.

---

## 9. Cost / volume notes

- At `SENTRY_SAMPLE_RATE=1.0` every unhandled exception is sent. For a
  low-traffic self-hosted vault this is fine. For a busy shared instance,
  consider `0.5` or lower.
- Performance tracing (`SENTRY_TRACES_SAMPLE_RATE > 0`) multiplies event
  volume significantly. Keep it at `0.0` unless you are actively diagnosing
  latency.
- Sentry SaaS has a free tier (5K errors/month); self-hosted has no quota but
  needs its own Postgres / Redis / ClickHouse resources.
