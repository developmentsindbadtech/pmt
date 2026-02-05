# PMT Deployment - PMT.sindbad.tech

## Domain

- **Production URL**: https://pmt.sindbad.tech
- **Webhook URL** (for GitHub): https://pmt.sindbad.tech/webhooks/github

## Environment

1. Copy `.env.production.example` to `.env` and set:
   - `APP_URL=https://pmt.sindbad.tech`
   - Database (PostgreSQL) credentials
   - `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT_ID` for SSO
   - `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` for GitHub OAuth
   - `GITHUB_WEBHOOK_SECRET` for GitHub webhook signature verification

2. **GitHub (choose one)**  
   - **SSO-only (recommended):** Create a [GitHub App](https://github.com/settings/apps/new) and install it on your org. Set `GITHUB_APP_ID`, `GITHUB_APP_INSTALLATION_ID`, and `GITHUB_APP_PRIVATE_KEY` (PEM content or path). Users never leave the portal—Connect GitHub lists repos from the app installation; no OAuth redirect.  
   - **OAuth fallback:** Set `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, and callback `https://pmt.sindbad.tech/auth/github/callback` if you do not use the GitHub App.

3. Microsoft Azure app:
   - Redirect URI: `https://pmt.sindbad.tech/auth/microsoft/callback`

4. GitHub webhook (per repository):
   - Payload URL: `https://pmt.sindbad.tech/webhooks/github`
   - Content type: `application/json`
   - Events: `pull_request`, `push` (optional)
   - Secret: same as `GITHUB_WEBHOOK_SECRET`

## Build

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

## Subdomain & GCP (load balancer, scaling)

- **APP_URL**: Set to your subdomain, e.g. `https://pmt.sindbad.tech`. Required for correct links and redirects.
- **TRUSTED_PROXIES**: Set to `*` when the app is behind GCP HTTP(S) Load Balancer (or any reverse proxy). This makes Laravel trust `X-Forwarded-For`, `X-Forwarded-Proto`, etc., so client IP and HTTPS are correct (sessions and CSRF work properly).
- **Session cookie**:
  - For subdomain-only (e.g. only `pmt.sindbad.tech`): leave `SESSION_DOMAIN` unset.
  - To share the session cookie with other subdomains (e.g. `app.sindbad.tech`): set `SESSION_DOMAIN=.sindbad.tech`.
  - Set `SESSION_SECURE_COOKIE=true` when using HTTPS.
- **Stateless scaling**: `SESSION_DRIVER=database` (or Redis) is already required so multiple instances share sessions; do not use `file` in production.
- **Cache & queues**: Use `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` (or `database`) in production. For GCP, use Memorystore for Redis or the database for cache/queues.
- **Production caches**: Before going live run:
  `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`.
- **PHP**: Enable OPcache for better performance.

## GCP Cloud Run (optional)

Use a similar setup to HRIS: Dockerfile, Cloud Build, and Cloud Run service. Set the service URL to `pmt.sindbad.tech` via domain mapping. Ensure `TRUSTED_PROXIES=*` and `APP_URL` are set so the load balancer is trusted and URLs are correct.
