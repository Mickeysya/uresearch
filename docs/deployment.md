# Deployment — scoping a free-tier host

Status: **scoping only, nothing deployed yet.** Written 2026-10-11. Free tiers
change often (three of the options below changed theirs in 2026), so re-check
every limit marked *verify* before acting on it.

Goal: a public URL the supervisor, examiners and the six of us can log into
for the FYP demo, at **$0/month**, without rewriting any module.

---

## 1. What the app actually needs from a host

This is the bar every option is measured against. It comes from the code,
not from a generic Laravel checklist.

| Need | Why | Where in the repo |
|---|---|---|
| PHP 8.3 with `gd`, `pdo_mysql`, `zip`, `mbstring` | Dompdf, Laravel Excel, image fakes | `composer.json` |
| MySQL 8 | every table; tests use SQLite but the app does not | `.env`, `docker-compose.yml` |
| **A persistent, private disk** | `DocumentStore` writes uploads to `Storage::disk('local')`, which is outside `public/` on purpose (hard rule 4) | `app/Modules/Core/Services/DocumentStore.php` |
| **A scheduler running every minute** | five daily reminder/escalation commands | `routes/console.php` |
| **A long-running queue worker** | 36 notifications implement `ShouldQueue`; without a worker, no email goes out | `docker-compose.yml` → `queue` service |
| Outbound SMTP | Mailpit locally; a real provider in production | `docs/email-service-integration.md` |
| HTTPS | logins and student records | — |

The two items in bold are what rule out most "free hosting". A
serverless or sleep-on-idle platform has no persistent disk, no cron, and
no worker that stays up.

---

## 2. Options

### Vercel — not recommended

Vercel runs PHP only through the community `vercel-php` runtime (built and
tested against Laravel 10) or a container-backed function. Either way:

- The filesystem is read-only/ephemeral, so every uploaded document vanishes.
  Fixing that means moving `DocumentStore` to S3, which is a **Core** change.
- There is no persistent queue worker. Every `ShouldQueue` notification needs
  rethinking.
- There is no MySQL, so you need a separate database host anyway.
- Cron jobs exist, but they hit a URL rather than run `schedule:run`.

That is a lot of rework to fit a stateful Laravel monolith onto a platform
built for Next.js. **Skip.**

### Render free + Aiven MySQL + Cloudflare R2 — workable, but fragile

| Piece | Free allowance (*verify*) |
|---|---|
| Render web service | 512 MB RAM, 0.1 CPU, 750 h/month, **sleeps after 15 min** (~1 min cold start), **no persistent disk** |
| Aiven MySQL | 1 CPU, 1 GB RAM, **1 GB storage**, may be shut down if idle |
| Cloudflare R2 | 10 GB storage, free egress |

Problems: no disk means uploads must go to R2 (Core change to `DocumentStore`
plus `league/flysystem-aws-s3-v3`). Render's free tier has no cron jobs and no
background workers, so reminders and queued mail don't run. The demo also
stalls for a minute whenever nobody has visited in 15 minutes.

### Railway / Koyeb / Fly.io — not free any more

Railway is a $5 trial credit. Fly.io dropped its free allowance for new
accounts. Koyeb's free tier is either two nano instances or a database only,
depending on which source you read. None of them covers web + worker + cron +
MySQL at $0.

### Laravel Cloud — the paid fallback

Laravel's own host. The $0 Sandbox plan is gone; Starter is **$5/month**
(first month free, $5 usage credit, managed queue, scale-to-zero, spending
cap). It handles queues, the scheduler and object storage natively, so it is
the least-work option *if* the team is willing to pay $5. Uploads still need
an object-storage disk, which is a Core change.

### Oracle Cloud Always Free VM — **recommended**

A real Linux VM, free indefinitely:

| Resource | Always Free (*verify*) |
|---|---|
| Ampere A1 (ARM) | **2 OCPU / 12 GB RAM** total, cut from 4/24 in mid-2026 |
| Block storage | 200 GB |
| AMD micro | 2 × (1/8 OCPU, 1 GB): too small for this app, ignore |

Why it wins: it's a normal server, so the app runs **exactly as it does
today**. You get a local disk for uploads, cron for the scheduler, systemd
for the queue worker, and MySQL plus Redis on the same box. No Core changes
and no module changes. 12 GB is far more than a demo needs.

The costs:

- Sign-up needs a credit card (identity check, not charged).
- ARM capacity in popular regions is sometimes "out of host capacity". Retry,
  or pick a quieter region (Singapore or Tokyo for latency from Malaysia).
- Oracle can reclaim Always Free instances that sit **idle** (very low CPU for
  7 days). The every-minute scheduler and the queue worker usually keep it
  above that line. Confirm with the dashboards in week one.
- We run the server ourselves: updates, backups, TLS. Section 4 keeps that
  small.

---

## 3. Recommendation

**Oracle Always Free VM, running the app natively (Caddy + PHP-FPM + MySQL +
Redis), with Brevo for SMTP.** Use Laravel Cloud Starter ($5/month) if Oracle
sign-up or capacity fails.

| Layer | Choice | Free limit (*verify*) |
|---|---|---|
| Server | Oracle A1, Ubuntu 24.04, 2 OCPU / 12 GB | Always Free |
| Web | Caddy (automatic HTTPS) → PHP 8.3-FPM | — |
| DB | MySQL 8.4 on the VM | 200 GB disk |
| Queue | Redis on the VM + `queue:work` under systemd | — |
| Scheduler | cron → `schedule:run` every minute | — |
| Uploads | `storage/app` on the VM disk, unchanged | — |
| Mail | Brevo SMTP (300/day) or Resend (100/day, 3,000/month) | plenty for a demo |
| Domain | free subdomain, e.g. DuckDNS or `<ip>.sslip.io` | Let's Encrypt via Caddy |

The six of us keep developing locally with Docker exactly as now. The VM is
only for the shared demo.

---

## 4. Rollout plan

### Phase 1 — server (≈1 hour)

1. Create the Oracle account, then an A1 instance (2 OCPU / 12 GB, Ubuntu 24.04).
2. Security list: open 22, 80, 443 only. **Do not open 3306 or 6379.**
3. Install the stack:
   ```bash
   sudo apt install -y caddy mysql-server redis-server git unzip \
     php8.3-fpm php8.3-{mysql,gd,zip,mbstring,xml,curl,bcmath,intl,redis}
   ```
4. Create the MySQL database and a dedicated user (not root).

### Phase 2 — app (≈1 hour)

```bash
git clone <repo> /var/www/uresearch && cd /var/www/uresearch
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# edit .env — see the table below
php artisan migrate --force
php artisan db:seed --force     # demo accounts; see the data note in §6
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
```

Production `.env` differences from `.env.example`:

| Key | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` (otherwise errors leak stack traces and env values) |
| `APP_URL` | `https://<your-domain>` |
| `DB_HOST` | `127.0.0.1` |
| `REDIS_HOST` / `QUEUE_CONNECTION` | `127.0.0.1` / `redis` |
| `MAIL_*` | Brevo SMTP credentials (stay in `.env`, never committed, hard rule 7) |
| `SESSION_SECURE_COOKIE` | `true` |

### Phase 3 — the three always-on pieces

Caddy (`/etc/caddy/Caddyfile`):

```
your-domain.duckdns.org {
    root * /var/www/uresearch/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
    encode gzip
}
```

Scheduler (`sudo crontab -u www-data -e`):

```
* * * * * cd /var/www/uresearch && php artisan schedule:run >> /dev/null 2>&1
```

Queue worker (`/etc/systemd/system/uresearch-queue.service`):

```ini
[Unit]
Description=UResearch queue worker
After=network.target mysql.service redis-server.service

[Service]
User=www-data
WorkingDirectory=/var/www/uresearch
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

`sudo systemctl enable --now uresearch-queue`

### Phase 4 — deploying updates

A `deploy.sh` on the server (not in CI, since Sharvin owns every push):

```bash
cd /var/www/uresearch
php artisan down
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
sudo systemctl restart uresearch-queue   # workers cache old code
php artisan up
```

Skipped: GitHub Actions auto-deploy. Add it once deploys happen more than
weekly. It needs an SSH deploy key in repo secrets.

### Phase 5 — backups

Nightly `mysqldump` plus a tar of `storage/app`, kept 7 days on the VM's
200 GB disk. Copy one off-box weekly (an R2 bucket or someone's laptop). The
VM is the only copy of any document uploaded during the demo.

---

## 5. Changes to the codebase

**None required** for the recommended option. That's most of why it's
recommended. The only new files are `docs/deployment.md` (this) and,
optionally, a `deploy.sh`, both outside `app/Modules/`.

If the team later moves to Laravel Cloud or Render, uploads must leave the
local disk. That is a **Core** change (`DocumentStore` → an `s3` disk), so
agree on it as a team first.

---

## 6. Risks and open questions

| # | Item | Owner / decision |
|---|---|---|
| 1 | **Real student data.** Seed with the fake test accounts only. Hosting real UTP student records on a personal free-tier VM raises PDPA and university-policy questions the FYP doesn't need. | team + supervisor |
| 2 | Default `password` on every seeded account is fine for a demo URL shared privately, not for anything indexed. Consider changing the seeded password via `.env` before going public. | Sharvin |
| 3 | Oracle idle reclamation. Check CPU graphs after week one. | Sharvin |
| 4 | Brevo/Resend need a verified sender domain for good deliverability. A DuckDNS subdomain can't set SPF/DKIM, so mail may land in spam. Buy a cheap domain (~RM40/year) if that matters for the demo. | team |
| 5 | Who else gets SSH? Keep it to one or two keys. | Sharvin |

---

## 7. Sources

Checked 2026-10-11. Limits are copied from these and may already be stale.

- Laravel Cloud pricing — <https://laravel.com/cloud/pricing>; Starter tier announcement — <https://laravel-news.com/laravel-cloud-adds-scale-to-zero-and-spending-limits>
- Oracle Always Free A1 reduction — <https://infoq.com/news/2026/07/oracle-cloud-free-tier-limits/>, <https://fullmetalbrackets.com/blog/oci-free-tier-breakdown>
- Render free tier — <https://render.com/docs/free>
- Vercel + Laravel — <https://vercel.com/kb/guide/laravel-php-with-docker>, <https://npmjs.com/package/vercel-php-laravel>
- Aiven MySQL free — <https://aiven.io/docs/products/mysql/concepts/mysql-free-tier>
- Railway / Koyeb — <https://agentdeals.dev/hosting-free-tier-comparison-2026>
- Cloudflare R2 — <https://agentdeals.dev/vendor/cloudflare-r2>
- Free SMTP tiers — <https://freetier.co/articles/free-email-sending-tiers-2026>
