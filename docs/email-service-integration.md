# Email Service Containerization & Integration Plan

This document extends the existing `architecture.md` — specifically the
**External** layer (`SMTP via Mailpit locally; real SMTP in production`) — into
a concrete, containerized implementation covering local development,
end-to-end testing with real inboxes, and production delivery for
registration verification, workflow notifications, and other system emails.

---

## 1. Goals

| Goal | Requirement |
|---|---|
| Local dev | Catch every outgoing email in a browser UI, zero real delivery |
| E2E testing | Send real emails to real inboxes (registration, verification, approval notices) without touching production infra |
| Production | Reliable, authenticated delivery with good deliverability (SPF/DKIM), queued and retried |
| Consistency | The app code never changes between environments — only config |
| No inbound exposure | Sending verification/notification emails is outbound-only; no ngrok or public exposure needed for the core flow |

---

## 2. Where email fits in the existing architecture

Per `architecture.md`, notifications are one of the **shared services**
alongside `WorkflowEngine`, `ModuleRegistry`, and `DocumentStore`. This matters
for containerization because two triggers exist today:

1. **Registration / verification** — Laravel's built-in `MustVerifyEmail` flow,
   fired on user registration.
2. **Workflow notifications** — `WorkflowEngine::decide()` notifies the student
   on every stage transition (endorsed, rejected, approved, etc.).

Both should go through **Laravel's queued Notifications**, not synchronous
`Mail::send()` calls. Queuing decouples "the workflow transaction committed"
from "the email actually left the building," so a slow or temporarily-down
SMTP provider never blocks an approver's `decide()` call or a student's
registration request.

This means the container topology needs **one more piece than just an SMTP
catcher**: a queue worker.

---

## 3. Container topology

```
                    ┌─────────────────────────────┐
                    │         app (Laravel)        │
                    │  - web requests               │
                    │  - dispatches Notification    │
                    │    jobs onto the queue         │
                    └───────────────┬───────────────┘
                                    │ pushes job
                                    ▼
                    ┌─────────────────────────────┐
                    │        queue-worker           │
                    │  php artisan queue:work        │
                    │  - picks up job                │
                    │  - sends via MAIL_MAILER        │
                    └───────────────┬───────────────┘
                                    │ SMTP
                    ┌───────────────┴───────────────┐
                    ▼                                 ▼
            [ dev/local ]                     [ staging / prod ]
            mailpit container                  real SMTP provider
            (traps mail, web UI)                (Resend / SES / etc.)

        ┌─────────────┐   ┌─────────────┐   ┌─────────────┐
        │   mysql      │   │   redis      │   │  mailpit    │
        │  (existing)  │   │ (queue driver)│   │ (dev only)  │
        └─────────────┘   └─────────────┘   └─────────────┘
```

Five services, three of which you already have or nearly have:

| Service | New? | Purpose |
|---|---|---|
| `app` | existing | Laravel app container |
| `mysql` | existing | Eloquent's backing store |
| `redis` | **new** | Queue driver + cache; small, well-understood addition |
| `queue-worker` | **new** | Same image as `app`, different command — actually sends mail |
| `mailpit` | new (dev only) | SMTP catcher, dev/testing only |

Using a **separate `queue-worker` service** (same Dockerfile/image as `app`,
just a different `command:`) rather than running `queue:work` inside the web
container keeps a crashed or backed-up mail queue from ever affecting request
handling — and matches how the `WorkflowEngine`'s "notify" step is meant to be
fire-and-forget from the transaction's point of view.

---

## 4. Environment strategy: base + override

Use Docker Compose's layered file approach instead of one monolithic file, so
the **only** thing that changes between environments is which override is
applied — never the base app/mysql/redis definitions.

```
docker-compose.yml          # base: app, mysql, redis, queue-worker
docker-compose.dev.yml      # adds mailpit, mounts source for hot-reload
docker-compose.e2e.yml      # points MAIL_* env at a real provider, keeps everything else local
docker-compose.prod.yml     # production overrides (no mailpit, prod secrets)
```

Run with:

```bash
# Local dev — Mailpit traps everything
docker compose -f docker-compose.yml -f docker-compose.dev.yml up

# E2E testing — real emails, still fully local containers otherwise
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up

# Production
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

This directly answers the "can I just run one command" question from earlier:
**yes, per environment** — the override file is the one-time setup, the `up`
command is what you run every time after that.

---

## 5. Compose files

### `docker-compose.yml` (base)

```yaml
services:
  app:
    build: .
    depends_on:
      - mysql
      - redis
    env_file: .env
    ports:
      - "8000:8000"

  queue-worker:
    build: .
    command: php artisan queue:work --tries=3 --backoff=10
    depends_on:
      - mysql
      - redis
    env_file: .env

  mysql:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - mysql-data:/var/lib/mysql

  redis:
    image: redis:7-alpine

volumes:
  mysql-data:
```

### `docker-compose.dev.yml`

```yaml
services:
  app:
    volumes:
      - .:/var/www/html
    environment:
      MAIL_MAILER: smtp
      MAIL_HOST: mailpit
      MAIL_PORT: 1025
      MAIL_FROM_ADDRESS: dev@localhost.test

  queue-worker:
    volumes:
      - .:/var/www/html
    environment:
      MAIL_MAILER: smtp
      MAIL_HOST: mailpit
      MAIL_PORT: 1025

  mailpit:
    image: axllent/mailpit
    ports:
      - "8025:8025"   # web UI
      - "1025:1025"   # smtp
```

### `docker-compose.e2e.yml`

```yaml
services:
  app:
    environment:
      MAIL_MAILER: smtp
      MAIL_HOST: ${REAL_SMTP_HOST}
      MAIL_PORT: ${REAL_SMTP_PORT}
      MAIL_USERNAME: ${REAL_SMTP_USER}
      MAIL_PASSWORD: ${REAL_SMTP_PASS}
      MAIL_ENCRYPTION: tls
      MAIL_FROM_ADDRESS: ${REAL_MAIL_FROM}

  queue-worker:
    environment:
      MAIL_MAILER: smtp
      MAIL_HOST: ${REAL_SMTP_HOST}
      MAIL_PORT: ${REAL_SMTP_PORT}
      MAIL_USERNAME: ${REAL_SMTP_USER}
      MAIL_PASSWORD: ${REAL_SMTP_PASS}
      MAIL_ENCRYPTION: tls
      MAIL_FROM_ADDRESS: ${REAL_MAIL_FROM}
```

Note there is **no `mailpit` service** in the e2e override — it's simply not
declared, so `compose` never starts it. `app`/`queue-worker` now point straight
at the real provider instead.

### `.env.example` additions

```dotenv
# --- Mail (overridden per environment via compose files) ---
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=dev@localhost.test
MAIL_FROM_NAME="${APP_NAME}"

# --- Real provider, used only by e2e/prod overrides ---
REAL_SMTP_HOST=smtp.resend.com
REAL_SMTP_PORT=465
REAL_SMTP_USER=resend
REAL_SMTP_PASS=
REAL_MAIL_FROM=noreply@yourdomain.com

QUEUE_CONNECTION=redis
```

---

## 6. Provider choice for E2E and production

| Provider | Fit for this system |
|---|---|
| **Resend** (recommended) | Simple SMTP + API, generous free tier, fast domain verification — good default for both e2e testing and early production |
| **Amazon SES** | Cheapest at real scale; more setup (domain verification, sandbox limits lifted manually) — consider once volume grows |
| **Gmail SMTP** | Fine for a quick personal e2e smoke test, but ~500/day limit and occasional automated-send flags make it unsuitable past initial testing |

For a university-facing system (student/supervisor verification and approval
emails), **domain-authenticated sending (SPF + DKIM via Resend/SES) matters**
— unauthenticated sends are more likely to land in spam, which is a real
failure mode for a verification-link flow.

---

## 7. Wiring it into the existing notification service

Two triggers, one pattern — both go through a queued `Notification`, not a
direct `Mail::send()`:

**Registration verification** — Laravel's built-in flow already queues by
default once `ShouldQueue` is added:

```php
class User extends Authenticatable implements MustVerifyEmail
{
    // Laravel's VerifyEmail notification already implements ShouldQueue
    // when the notification class is marked as such — confirm in
    // app/Notifications if it's been customized.
}
```

**Workflow notifications** — the shared notification service that
`WorkflowEngine::decide()` calls should dispatch a queued notification per
stage transition:

```php
class ApplicationStageChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Application $application,
        private readonly string $event // 'endorsed' | 'approved' | 'rejected'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Application {$this->event}")
            ->line("Your {$this->application->module_type} application has been {$this->event}.")
            ->action('View application', url("/applications/{$this->application->id}"));
    }
}
```

Because `MAIL_MAILER`/`MAIL_HOST`/etc. are the only things that change between
`dev.yml`, `e2e.yml`, and `prod.yml`, this class — and every other notification
in the system — needs **zero code changes** across environments.

---

## 8. The one case that *does* still need a tunnel

Everything above is outbound-only, so ngrok stays out of the picture — with
one exception worth flagging since it can come up later: if you ever want
**delivery/bounce/complaint webhooks** from the provider (Resend, SES, etc.)
to call back into your local app (e.g., to mark an email as bounced in your
`applications` audit trail), that *is* inbound, and *would* need a tunnel
(ngrok, Cloudflare Tunnel, etc.) pointed at your app's webhook route during
local development. It's not needed for sending verification/notification
emails themselves — only if you build provider-side delivery-status handling
later.

---

## 9. Local development workflow

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
php artisan migrate   # run once, or bake into an entrypoint script
```

- App sends mail → `queue-worker` picks up the job → delivers to `mailpit:1025`
- Inspect every email, headers, and rendered HTML at `http://localhost:8025`
- Nothing leaves the machine

## 10. End-to-end testing workflow (real inboxes)

```bash
cp .env.example .env.e2e   # fill in REAL_SMTP_* with Resend/SES credentials
docker compose -f docker-compose.yml -f docker-compose.e2e.yml --env-file .env.e2e up -d
```

- Register a real test account → verification email lands in the real inbox
- Click the verification link → hits your locally running app directly over
  HTTP (this part was never email-dependent, so it needs no tunnel either)
- Trigger a workflow transition → approval/rejection email arrives for real

## 11. Production

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

- Same provider as e2e (or a separate production sending domain/key)
- `queue-worker` should run under a process supervisor inside the container
  (or as its own scaled Compose/Swarm/K8s replica) so a worker crash restarts
  automatically rather than silently dropping the queue

---

## 12. Implementation checklist

- [ ] Add `redis` service and set `QUEUE_CONNECTION=redis`
- [ ] Add `queue-worker` service (same image, `queue:work` command)
- [ ] Split `docker-compose.yml` into base + `dev`/`e2e`/`prod` overrides
- [ ] Add `mailpit` service to `dev` override only
- [ ] Sign up for Resend (or SES), verify sending domain, add SPF/DKIM records
- [ ] Add `REAL_SMTP_*` vars to `.env.e2e` / `.env.prod` (never commit these)
- [ ] Confirm `VerifyEmail` and any custom notifications implement `ShouldQueue`
- [ ] Test dev flow: register → check Mailpit UI at `localhost:8025`
- [ ] Test e2e flow: register with a real address → confirm inbox delivery →
      click verification link against the locally running app
- [ ] (Later, optional) Add a webhook route + tunnel only if you build
      bounce/delivery-status handling
