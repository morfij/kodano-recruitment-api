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

Optionally load demo data (4 categories, 8 products):

```bash
docker compose exec php bin/console doctrine:fixtures:load -n
```

This empties the database first, including the operation log. Demo data is written through Doctrine, not the API, so it produces no log entries and no e-mails. The command is available only with dev dependencies installed.

All further commands are run from the repository root. `make help` lists short aliases for the common ones (`make up`, `make test`, `make qa`, …).

## Configuration

There are two levels of settings.

**Docker Compose** reads an optional `.env` in the repository root. Copy `.env.example` and adjust it before `docker compose up` if the defaults do not fit:

| Variable | Default | Purpose |
|---|---|---|
| `HTTP_PORT` | `8080` | API port on the host |
| `MAILPIT_PORT` | `8025` | Mailpit UI port |
| `ADMINER_PORT` | `8081` | Adminer port (optional tool) |
| `MYSQL_PASSWORD` | `app` | Password of the local MySQL user `app` |
| `UID` / `GID` | `1000` | Host user; files written by the PHP container belong to it |

**The Symfony application** lives in `app/` and uses the usual `app/.env` files:

| Variable | Value in `app/.env` | Purpose |
|---|---|---|
| `DATABASE_URL` | `mysql://app:app@database:3306/app?...` | Database connection |
| `MAILER_DSN` | `smtp://mailer:1025` | Mailpit inside Docker |
| `MAILER_FROM` | `Kodano API <noreply@example.com>` | Sender of notification e-mails |
| `MAILER_TO` | `catalog@example.com` | Recipient of notification e-mails |

`APP_SECRET` is not stored in the repository. The first `composer install` writes a random one to `app/.env.local` (ignored by Git). In production all values come from real environment variables, which take precedence over `.env` files.

## API

Formats: JSON-LD (`application/ld+json`, default) and plain JSON (`application/json`). Collections are paginated (30 items per page) and ordered by `id`.

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
| `GET` | `/api/operation_logs` | List operation log entries, newest first |
| `GET` | `/api/operation_logs/{id}` | Get one log entry |

Fields:

- **Product**: `id`, `name` (required, up to 255 characters), `price`, `categories` (at least one, as IRIs such as `"/api/categories/1"`), `createdAt`, `updatedAt`.
- **Category**: `id`, `code` (required, unique, up to 10 characters), `createdAt`, `updatedAt`.
- `id`, `createdAt` and `updatedAt` are read-only and set automatically. `updatedAt` changes on every successful `PATCH`.
- **Price** is a string with a dot as decimal separator, up to 8 digits before it and 2 after it (`"19.99"`, `"5"`, `"0.5"`). It is stored as `DECIMAL(10,2)` and returned with two decimals (`"5"` becomes `"5.00"`). A number instead of a string, a comma, a minus sign or a third decimal is rejected.

`PATCH` follows JSON Merge Patch: omitted fields stay unchanged, `categories` replaces the whole list, and `null` for `name` / `price` or an empty `categories` list is a validation error.

The operation log is read-only (`POST` / `PATCH` / `DELETE` return `405`). Each entry has `operation`, `productId` (a number, since the product may already be gone), `payload` and `createdAt`. The list can be filtered, and filters can be combined:

```bash
curl -s 'http://localhost:8080/api/operation_logs?productId=1'
curl -s 'http://localhost:8080/api/operation_logs?operation=deleted'
curl -s -g 'http://localhost:8080/api/operation_logs?createdAt[after]=2026-10-01&createdAt[before]=2026-10-31'
```

An unknown `operation` or a non-integer `productId` returns `422`; a date that cannot be parsed is ignored.

Categories cannot be changed or deleted through the API, so a product can never be left without a category. The database enforces the same rule: a category that is still linked to a product cannot be deleted.

Response codes: `201` created, `200` OK, `204` deleted, `400` malformed request (e.g. unknown category IRI), `404` not found, `405` method not available, `415` wrong `Content-Type` for `PATCH`, `422` validation error (details in `violations`), `500` the change could not be saved (nothing was changed).

## Examples

Create the first category:

```bash
curl -s -X POST http://localhost:8080/api/categories \
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
curl -s -X POST http://localhost:8080/api/products \
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
curl -s http://localhost:8080/api/products
curl -s -H 'Accept: application/json' http://localhost:8080/api/products/1

curl -s -X PATCH http://localhost:8080/api/products/1 \
  -H 'Content-Type: application/merge-patch+json' \
  -d '{"name": "Kubek XL", "price": "24.99"}'

curl -s -X POST http://localhost:8080/api/categories \
  -H 'Content-Type: application/ld+json' -d '{"code": "CUPS"}'
curl -s -X PATCH http://localhost:8080/api/products/1 \
  -H 'Content-Type: application/merge-patch+json' \
  -d '{"categories": ["/api/categories/2"]}'

curl -s -o /dev/null -w '%{http_code}\n' -X DELETE http://localhost:8080/api/products/1
```

A validation error:

```bash
curl -s -X POST http://localhost:8080/api/products \
  -H 'Content-Type: application/ld+json' \
  -d '{"name": "Kubek", "price": "19,99", "categories": []}'
```

returns `422` with one entry in `violations` for `price` and one for `categories`.

After these calls Mailpit shows one e-mail per product change, and the operation log holds the same events:

```bash
curl -s http://localhost:8080/api/operation_logs
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
- a retry policy and a failure transport, and a decision on duplicates, since a retried message can send an e-mail twice.

The operation log is not affected: it stays in the same transaction as the product change.

## Tests

```bash
docker compose exec php composer test
```

`composer test` applies the migrations to the separate test database `app_test` and runs PHPUnit. Each test runs in a transaction that is rolled back afterwards, and e-mails are collected instead of sent.

- `app/tests/Unit` — the notification handler (channel selection, one failing channel does not stop the others, failures are logged) and the e-mail channel (sender, recipient, subject, templates). They need no database: `docker compose exec php bin/phpunit tests/Unit`.
- `app/tests/Functional` — the API against MySQL: CRUD, validation and price format, `PATCH` semantics, timestamps after a reload from the database, operation log and e-mail for each product change, no side effects on validation errors, rollback when the log cannot be written, a successful response when a channel fails.

## Code quality

```bash
docker compose exec php composer stan   # PHPStan, level 8 (make stan)
docker compose exec php composer cs     # PHP-CS-Fixer, @Symfony rules, dry run (make cs)
make qa                                 # PHPStan + style check + tests
```

PHPStan uses the Symfony, Doctrine and PHPUnit extensions and reads the test container, so service types in tests are known. `composer cs-fix` (`make cs-fix`) applies the style fixes.

## Database access

```bash
docker compose exec database mysql -uapp -papp app
```

MySQL is not exposed on the host. For a web UI, start Adminer (server `database`, user and password `app`):

```bash
docker compose --profile tools up -d    # http://localhost:8081
docker compose --profile tools down
```

## Project layout

```
.
├── Dockerfile                 PHP 8.4-FPM image
├── Makefile                   Short aliases for Docker Compose commands
├── compose.yaml               php, nginx, database (MySQL 8.4), mailer (Mailpit), adminer (optional)
├── docker/                    nginx and MySQL configuration
└── app/                       Symfony application
    ├── config/
    ├── migrations/
    ├── src/
    │   ├── DataFixtures/      AppFixtures (demo data)
    │   ├── Entity/            Product, Category, OperationLog, TimestampableTrait
    │   ├── Enum/              ProductOperation
    │   ├── Message/           ProductNotification
    │   ├── MessageHandler/    SendProductNotificationHandler
    │   ├── Notification/      NotificationChannelInterface, EmailNotificationChannel
    │   └── State/             ProductStateProcessor
    ├── templates/email/
    └── tests/
```

## Design decisions

- **Operation log in the database, in the same transaction as the change.** A log entry exists exactly when the change exists. It can be read and filtered through a read-only endpoint or queried with SQL. Technical errors go to the regular application log.
- **Own channel interface instead of using Symfony Notifier directly.** E-mail, Slack and SMS are all "channels" behind one small interface; Notifier is used inside a channel where it helps (Slack, SMS). The operation log is not a channel, because it must not fail independently of the change.
- **Messenger with the sync transport.** It keeps the notification code ready for a queue without running a worker for a task that allows a fake e-mail.
- **Deletion is a product change too.** It is logged and announced; category changes are not.
- **Price as `DECIMAL(10,2)` exposed as a string.** No floating-point rounding; a single field is enough because currencies and price history are not part of the task (the log already keeps every price that was saved).
- **Categories are read-only after creation.** Deleting a category in use would break the "at least one category" rule; the schema enforces it as well.
- **API Platform resources on the entities, serialization groups, no separate DTOs or layers.** For a CRUD with three entities this keeps the code small; validation rules stay next to the fields.
- **nginx + PHP-FPM on official images.** A plain, widely known setup with a small `Dockerfile`.
- **No authentication.** It is not required here; it would be the next step before exposing the API.
