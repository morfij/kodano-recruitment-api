# kodano-recruitment-api

REST API for managing products and categories, built with Symfony 8.1, API Platform 5, Doctrine ORM and MySQL 8.4, running in Docker. Every change to a product is written to an operation log in the database and announced by e-mail; the notification code is open for further channels such as Slack or SMS.

## Requirements

- Docker Engine with Docker Compose v2
- Free ports 8080 (API) and 8025 (Mailpit); both can be changed, see [Configuration](#configuration)

PHP, Composer and MySQL run in containers, nothing else is needed on the host.

## Quick start

```bash
git clone git@github.com:morfij/kodano-recruitment-api.git
cd kodano-recruitment-api

docker compose up -d --wait
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate -n
```

| What | URL |
|---|---|
| API entry point | http://localhost:8080/api |
| Swagger UI / OpenAPI | http://localhost:8080/api/docs |
| Mailpit (sent e-mails) | http://localhost:8025 |
| Adminer (database UI) | http://localhost:8081 |

Optionally load demo data (4 categories, 8 products):

```bash
docker compose exec php bin/console doctrine:fixtures:load -n
```

This empties the database first, including the operation log. Demo data is written through Doctrine, not the API, so it produces no log entries and no e-mails. The command is available only with dev dependencies installed.

All further commands are run from the repository root. `make help` lists short aliases for the common ones (`make up`, `make test`, `make qa`, …).

## Configuration

There are two levels of settings.

**Docker Compose** reads an optional `.env` in the repository root. Copy `.env.example` and adjust it before `docker compose up` if the defaults do not fit. All ports are published on `127.0.0.1` only, so the API, Mailpit and Adminer are not reachable from other machines:

| Variable | Default | Purpose |
|---|---|---|
| `HTTP_PORT` | `8080` | API port on the host |
| `MAILPIT_PORT` | `8025` | Mailpit UI port |
| `ADMINER_PORT` | `8081` | Adminer port |
| `MYSQL_PASSWORD` | `app` | Password of the local MySQL user `app` |
| `UID` / `GID` | `1000` | Host user; files written by the PHP container belong to it |

**The Symfony application** lives in `app/` and uses the usual `app/.env` files:

| Variable | Value in `app/.env` | Purpose |
|---|---|---|
| `DATABASE_URL` | `mysql://app:app@database:3306/app?...` | Database connection |
| `MAILER_DSN` | `smtp://mailer:1025` | Mailpit inside Docker |
| `MAILER_FROM` | `Kodano API <noreply@example.com>` | Sender of notification e-mails |
| `MAILER_TO` | `catalog@example.com` | Recipient of notification e-mails |
| `API_TOKEN` | empty | Bearer token required by the API; empty rejects every request |
| `API_WRITE_LIMIT` | `60` | `POST` / `PATCH` / `DELETE` requests per client IP and minute |
| `LOCK_DSN` | `flock` | Lock store for the rate limiter; `flock` covers all PHP workers on one host |

`APP_SECRET` and `API_TOKEN` are not stored in the repository. `composer install` writes random values to `app/.env.local` (ignored by Git) when they are missing there. In production all values come from real environment variables, which take precedence over `.env` files.

The defaults (`APP_ENV=dev`) are meant for local work: error responses include stack traces. A shared or public instance needs `APP_ENV=prod` and `APP_DEBUG=0`, a real `API_TOKEN`, HTTPS in front of nginx, and no published Mailpit or Adminer.

## API

Formats: JSON-LD (`application/ld+json`, default) and plain JSON (`application/json`). Collections are paginated (30 items per page) and ordered by `id`; the operation log lists the newest entries first.

Every request under `/api` needs the token from `API_TOKEN` in an `Authorization: Bearer <token>` header (with curl: `--oauth2-bearer "$TOKEN"`, see [Examples](#examples)); without it the API answers `401`. Swagger UI and the OpenAPI document under `/api/docs` stay public; use **Authorize** in Swagger UI to send requests from there.

Write requests are limited per client IP (`API_WRITE_LIMIT` per minute); over the limit the API answers `429` with a `Retry-After` header and changes nothing. Reads are not limited.

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/products` | List products |
| `GET` | `/api/products/{id}` | Get one product with its categories |
| `POST` | `/api/products` | Create a product |
| `PATCH` | `/api/products/{id}` | Update a product (`application/merge-patch+json`) |
| `DELETE` | `/api/products/{id}` | Delete a product |
| `GET` | `/api/categories` | List categories |
| `GET` | `/api/categories/{id}` | Get one category |
| `POST` | `/api/categories` | Create a category |
| `PATCH` | `/api/categories/{id}` | Change the category code (`application/merge-patch+json`) |
| `DELETE` | `/api/categories/{id}` | Delete a category that no product uses |
| `GET` | `/api/operation_logs` | List operation log entries, newest first |
| `GET` | `/api/operation_logs/{id}` | Get one log entry |

Fields:

- **Product**: `id`, `name` (required, up to 255 characters), `price`, `categories` (at least one, as IRIs such as `"/api/categories/1"`), `createdAt`, `updatedAt`.
- **Category**: `id`, `code` (required, unique, up to 10 characters), `createdAt`, `updatedAt`.
- `id`, `createdAt` and `updatedAt` are read-only and set automatically. A product's `updatedAt` changes on every successful `PATCH`, even when no value changed; a category's changes when its code does.
- **Price** is a string with a dot as decimal separator, up to 8 digits before it and 2 after it (`"19.99"`, `"5"`, `"0.5"`). It is stored as `DECIMAL(10,2)` and returned with two decimals (`"5"` becomes `"5.00"`). A number instead of a string, a comma, a minus sign or a third decimal is rejected.

`PATCH` follows JSON Merge Patch: omitted fields stay unchanged, `categories` replaces the whole list, and `null` for `name` / `price` or an empty `categories` list is a validation error.

The operation log is read-only (`POST` / `PATCH` / `DELETE` return `405`). Each entry has `operation`, `productId` (a number, since the product may already be gone), `payload` and `createdAt`. The list can be filtered, and filters can be combined:

```bash
curl -s --oauth2-bearer "$TOKEN" 'http://localhost:8080/api/operation_logs?productId=1'
curl -s --oauth2-bearer "$TOKEN" 'http://localhost:8080/api/operation_logs?operation=deleted'
curl -s --oauth2-bearer "$TOKEN" -g 'http://localhost:8080/api/operation_logs?createdAt[after]=2026-10-01&createdAt[before]=2026-10-31'
```

An unknown `operation` or a non-integer `productId` returns `422`; a date that cannot be parsed is ignored.

A category that is still linked to a product cannot be deleted: the API returns `409` and nothing changes, so a product is never left without a category. Move the products to other categories first. The database enforces the same rule with a `RESTRICT` foreign key. Changing or deleting a category is not a product change, so it produces no log entry or e-mail; earlier log entries keep the category codes valid at the time of the product change.

Two `PATCH` requests for the same product that overlap in time do not overwrite each other: the second one is rejected with `409` and changes nothing, so it can be retried on the current state.

Response codes: `201` created, `200` OK, `401` missing or wrong API token, `429` too many write requests, `204` deleted, `400` malformed request (e.g. unknown category IRI), `404` not found, `405` method not available, `409` category still in use or product changed by a concurrent request, `415` wrong `Content-Type` for `PATCH`, `422` validation error (details in `violations`), `500` the change could not be saved (nothing was changed).

## Examples

The examples start from an empty database, so skip the demo data or recreate the database first (`docker compose down -v`, then the Quick start commands). They read the token from `app/.env.local`:

```bash
TOKEN=$(sed -n 's/^API_TOKEN=//p' app/.env.local)
```

Create the first category:

```bash
curl -s --oauth2-bearer "$TOKEN" -X POST http://localhost:8080/api/categories \
  -H 'Content-Type: application/ld+json' \
  -d '{"code": "MUGS"}'
```

Response (`201`, formatted):

```json
{
    "@context": "/api/contexts/Category",
    "@id": "/api/categories/1",
    "@type": "Category",
    "id": 1,
    "code": "MUGS",
    "createdAt": "2026-10-11T10:00:00+00:00",
    "updatedAt": "2026-10-11T10:00:00+00:00"
}
```

Create a product in that category:

```bash
curl -s --oauth2-bearer "$TOKEN" -X POST http://localhost:8080/api/products \
  -H 'Content-Type: application/ld+json' \
  -d '{"name": "Kubek", "price": "19.99", "categories": ["/api/categories/1"]}'
```

Response (`201`, formatted):

```json
{
    "@context": "/api/contexts/Product",
    "@id": "/api/products/1",
    "@type": "Product",
    "id": 1,
    "name": "Kubek",
    "price": "19.99",
    "categories": ["/api/categories/1"],
    "createdAt": "2026-10-11T10:00:05+00:00",
    "updatedAt": "2026-10-11T10:00:05+00:00"
}
```

Read, update and delete it:

```bash
curl -s --oauth2-bearer "$TOKEN" http://localhost:8080/api/products
curl -s --oauth2-bearer "$TOKEN" -H 'Accept: application/json' http://localhost:8080/api/products/1

curl -s --oauth2-bearer "$TOKEN" -X PATCH http://localhost:8080/api/products/1 \
  -H 'Content-Type: application/merge-patch+json' \
  -d '{"name": "Kubek XL", "price": "24.99"}'

curl -s --oauth2-bearer "$TOKEN" -X POST http://localhost:8080/api/categories \
  -H 'Content-Type: application/ld+json' -d '{"code": "CUPS"}'
curl -s --oauth2-bearer "$TOKEN" -X PATCH http://localhost:8080/api/products/1 \
  -H 'Content-Type: application/merge-patch+json' \
  -d '{"categories": ["/api/categories/2"]}'

curl -s --oauth2-bearer "$TOKEN" -o /dev/null -w '%{http_code}\n' -X DELETE http://localhost:8080/api/products/1
```

A validation error:

```bash
curl -s --oauth2-bearer "$TOKEN" -X POST http://localhost:8080/api/products \
  -H 'Content-Type: application/ld+json' \
  -d '{"name": "Kubek", "price": "19,99", "categories": []}'
```

returns `422` with one entry in `violations` for `price` and one for `categories`.

After these calls Mailpit shows one e-mail per product change, and the operation log holds the same events:

```bash
curl -s --oauth2-bearer "$TOKEN" http://localhost:8080/api/operation_logs
```

## Operation log and notifications

Creating, updating and deleting a **product** each produce:

1. a row in `operation_log`: `operation` (`created` / `updated` / `deleted`), `product_id`, a JSON snapshot of the product (`name`, `price`, category codes) and `created_at`;
2. an e-mail to `MAILER_TO` with the same snapshot.

Deletion is treated as a product change as well, so it is logged and announced. Changes to categories do not produce log entries or e-mails. `product_id` has no foreign key, so log entries outlive deleted products.

How a write request is processed (`app/src/State/ProductStateProcessor.php`):

```
validation ── fails ──> 422, nothing stored, nothing sent
    │
    ▼
transaction: save or delete the product + insert the operation log entry
    │   fails ──> rollback, 500, no e-mail
    ▼
commit ──> dispatch ProductNotification (Symfony Messenger, sync transport)
    │
    ▼
SendProductNotificationHandler ──> every NotificationChannelInterface
                                   └─ EmailNotificationChannel ──> Mailer
```

- The log entry is part of the change: if it cannot be written, the product change is rolled back.
- Notifications are sent only after the commit. If a channel fails, the error is written to the application log (Monolog; `app/var/log/dev.log` in dev) and the remaining channels still run; the API response stays successful because the change is already saved.
- `ProductNotification` is an immutable value snapshot, so it does not depend on the entity still existing.

The e-mail uses `app/templates/email/product_notification.{html,txt}.twig`.

## Adding a notification channel

A channel is a class implementing `App\Notification\NotificationChannelInterface`. Every implementation is registered automatically and called by the handler; the product code and the e-mail channel do not change.

Slack, for example:

```bash
docker compose exec php composer require symfony/notifier symfony/slack-notifier
```

```dotenv
# app/.env.local
SLACK_DSN=slack://TOKEN@default?channel=CHANNEL
```

```yaml
# app/config/packages/notifier.yaml
framework:
    notifier:
        chatter_transports:
            slack: '%env(SLACK_DSN)%'
```

```php
// app/src/Notification/SlackNotificationChannel.php
namespace App\Notification;

use App\Message\ProductNotification;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

final class SlackNotificationChannel implements NotificationChannelInterface
{
    public function __construct(private readonly ChatterInterface $chatter)
    {
    }

    public function supports(ProductNotification $notification): bool
    {
        return true;
    }

    public function send(ProductNotification $notification): void
    {
        $this->chatter->send(new ChatMessage(sprintf(
            'Product %s: %s (#%d), price %s',
            $notification->operation->value,
            $notification->name,
            $notification->productId,
            $notification->price,
        )));
    }
}
```

SMS works the same way with `TexterInterface`, `SmsMessage` and a provider bridge (e.g. `symfony/twilio-notifier`). `supports()` lets a channel react only to some events, for example only to deletions.

## Running notifications asynchronously

Notifications are handled synchronously, within the request. The message is already a plain snapshot handled by a Messenger handler, so moving it to a queue needs no change in the product code, but it is more than one line:

- a transport (e.g. `doctrine://default` or RabbitMQ) and the route `App\Message\ProductNotification` pointed to it in `app/config/packages/messenger.yaml`;
- a long-running worker (`bin/console messenger:consume async`) as an extra Compose service;
- a retry policy and a failure transport, and a decision on duplicates, since a retried message can send an e-mail twice;
- a handler that lets channel failures reach the worker (today it logs and swallows them, so nothing would be retried), and a way to retry only the channel that failed.

The message is dispatched after the commit, so a process that dies in between keeps the change and its log entry but sends nothing. Guaranteed delivery needs the message stored in the same transaction as the change (an outbox) and a worker that sends it.

The operation log is not affected: it stays in the same transaction as the product change.

## Tests

```bash
docker compose exec php composer test
```

`composer test` applies the migrations to the separate test database `app_test` and runs PHPUnit. Each test runs in a transaction that is rolled back afterwards, and e-mails are collected instead of sent. The tests generate their own random API token.

- `app/tests/Unit` — the API token check, the write rate limit rules, the `409` when a category gets linked while it is being deleted, the notification handler (channel selection, a channel failing in `supports()` or `send()` does not stop the others, failures are logged) and the e-mail channel (sender, recipient, subject, templates). They need no database: `docker compose exec php bin/phpunit tests/Unit`.
- `app/tests/Functional` — the API against MySQL: `401` without a valid token and public docs, CRUD, validation and price format, `PATCH` semantics, timestamps after a reload from the database, category updates and the `409` for a category in use, operation log and e-mail for each product change, no side effects on validation errors, rollback of create, update and delete when the log cannot be written, a stale concurrent update that is rejected, a duplicate category code from a concurrent request reported as a normal violation, `X-HTTP-Method-Override` being ignored, error responses without debug (no trace or SQL), the rate limit holding for parallel PHP processes, a successful response when a channel fails, and the operation log endpoint with its filters.

## Code quality

```bash
docker compose exec php composer stan   # PHPStan, level 8 (make stan)
docker compose exec php composer cs     # PHP-CS-Fixer, @Symfony rules, dry run (make cs)
make qa                                 # PHPStan + style check + tests
```

PHPStan uses the Symfony, Doctrine and PHPUnit extensions and reads the test container, so service types in tests are known. `composer cs-fix` (`make cs-fix`) applies the style fixes.

GitHub Actions (`.github/workflows/ci.yml`) runs the same three checks in the same Docker setup on every push to `main` and on pull requests.

## Database access

```bash
docker compose exec database mysql -uapp -papp app
```

MySQL is not exposed on the host. Adminer runs with the other services at http://localhost:8081 (server `database`, user and password `app`).

## Project layout

```
.
├── Dockerfile                 PHP 8.4-FPM image
├── Makefile                   Short aliases for Docker Compose commands
├── .github/workflows/ci.yml   PHPStan, coding style and tests on GitHub Actions
├── compose.yaml               php, nginx, database (MySQL 8.4), mailer (Mailpit), adminer
├── docker/                    nginx and MySQL configuration
└── app/                       Symfony application
    ├── config/
    ├── migrations/
    ├── src/
    │   ├── DataFixtures/      AppFixtures (demo data)
    │   ├── Entity/            Product, Category, OperationLog, TimestampableTrait
    │   ├── EventSubscriber/   ApiWriteRateLimitSubscriber
    │   ├── Enum/              ProductOperation
    │   ├── Message/           ProductNotification
    │   ├── MessageHandler/    SendProductNotificationHandler
    │   ├── Notification/      NotificationChannelInterface, EmailNotificationChannel
    │   ├── Security/          ApiTokenHandler, ApiTokenEntryPoint
    │   └── State/             ProductStateProcessor, CategoryPersistProcessor, CategoryDeleteProcessor
    ├── templates/email/
    └── tests/
```

## Design decisions

- **Operation log in the database, in the same transaction as the change.** A log entry exists exactly when the change exists. It can be read and filtered through a read-only endpoint or queried with SQL. Technical errors go to the regular application log.
- **Own channel interface instead of using Symfony Notifier directly.** E-mail, Slack and SMS are all "channels" behind one small interface; Notifier is used inside a channel where it helps (Slack, SMS). The operation log is not a channel, because it must not fail independently of the change.
- **Messenger with the sync transport.** It keeps the notification code ready for a queue without running a worker for a task that allows a fake e-mail.
- **Deletion is a product change too.** It is logged and announced; category changes are not.
- **Price as `DECIMAL(10,2)` exposed as a string.** No floating-point rounding; a single field is enough because currencies and price history are not part of the task (the log already keeps every price that was saved).
- **A category in use cannot be deleted.** Deleting it would break the "at least one category" rule, so the API answers `409` and the schema has a `RESTRICT` foreign key as a second guard.
- **Optimistic locking on products.** Each request validates its own category list, so two overlapping updates could still remove different categories and leave none. A Doctrine version column makes the later write fail with `409`; clients do not send the version, so the API stays plain `PATCH`.
- **API Platform resources on the entities, serialization groups, no separate DTOs or layers.** For a CRUD with three entities this keeps the code small; validation rules stay next to the fields.
- **nginx + PHP-FPM on official images.** A plain, widely known setup with a small `Dockerfile`.
- **One shared bearer token.** The task does not define users or roles, so a single token from the environment protects the whole API with Symfony's built-in `access_token` authenticator and no extra packages. Per-client tokens or JWT can replace the token handler when the API gets real consumers.
- **Write rate limit in the application.** Product writes produce a log entry and an e-mail, so the limit sits next to the code that causes them. The counter is kept in the Symfony cache and updated under a lock (`LOCK_DSN`), so parallel PHP workers cannot pass it together. With several servers both need a shared store, e.g. Redis for `cache_pool` in `rate_limiter.yaml` and for `LOCK_DSN`.
- **Database constraints as the last guard for categories.** The UNIQUE index on `code` and the `RESTRICT` foreign key catch what validation misses in races; their errors are returned as the same `422` / `409` a sequential request gets, without SQL details. Other unexpected errors give `500`; with `APP_DEBUG=0` the response has no stack trace.

## Limitations

Deliberate simplifications for the size of this task, and what to change when they start to matter:

- **Notifications are best effort.** They run synchronously after the commit, with no retry; see [Running notifications asynchronously](#running-notifications-asynchronously).
- **Concurrency protection covers overlapping requests, not stale forms.** The product version is not exposed, so a client that edits an old copy overwrites newer data. An editing UI would need the version in the API or `ETag` / `If-Match`.
- **Entities are the API contract.** ORM mapping, API metadata, validation and serialization groups live on the same classes. Separate DTO resources become worth it with distinct create/update commands, several API versions or different views of the data.
- **Product rules live in `ProductStateProcessor`.** Code that saves a product directly through Doctrine (fixtures do it on purpose) gets no log entry, e-mail or `updatedAt` touch. An import or CLI command should share that flow through an application service.
- **One client, one instance.** A single token means no per-client roles or revocation; the rate limit is per IP, so clients behind one NAT share it. The Docker setup is for development: production needs an image with the code and dependencies built in, read-only code, and migrations run as a deployment step.
