# This is how i build it

## Step 1 — Configure database and Sanctum

- Update your .env:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=moma_db
DB_USERNAME=root
DB_PASSWORD=
```
- Create the database in MySQL:
```sql
CREATE DATABASE moma_db;
```
- Now install API authentication support:
```bash
composer require laravel/sanctum
php artisan install:api
```
- Then run:
```bash
php artisan migrate
```
and confirm it uses Sanctum:
```php
<?php

namespace App\Models;

........
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, ........;
```

## Step 2 — Models and migrations

- Run:
```bash
php artisan make:model Product -mf
php artisan make:model Order -mf
php artisan make:model OrderItem -mf
```

The shape i went with is `users -> orders -> order_items -> products`.

- `products` — belongs to the user who added it (`created_by`), price is `decimal(10,2)` so nothing goes through a float, `stock` is unsigned so it can never go negative at the database level. Indexed `name` and `price` because those are the columns the listing filters on.
- `orders` — belongs to a user, keeps `total_amount` and a `status` string. Cascade delete from users.
- `order_items` — the pivot between orders and products, but with data on it (`quantity`, `unit_price`, `subtotal`), so it gets its own model rather than a plain pivot table. `restrictOnDelete` on `product_id` so you cannot delete a product out from under an old order.

`unit_price` is copied onto the line at the time of the order. If i only stored `product_id` and joined back to `products`, then changing a price later would silently rewrite every old order's total. So the line keeps its own copy.

Then:
```bash
php artisan migrate
```

## Step 3 — Register and login

- Run:
```bash
php artisan make:controller Api/AuthController
```

`register` validates, creates the user and hands back a Sanctum token. `login` looks up the user and checks the hash by hand instead of using `Auth::attempt`, because there is no session here — i only want the token. On a bad password i throw a `ValidationException` so the error comes back in the same `422` shape as every other validation error, instead of being a one-off `401` body the client has to special case.

`logout` deletes only the token that made the request:
```php
$request->user()->currentAccessToken()->delete();
```
so signing out on your phone does not sign you out on your laptop.

Routes go in `routes/api.php`, everything except register and login sits behind `auth:sanctum`.

Because this is an API only app, i also told the exception handler to always answer in JSON, otherwise a validation error on a request without an `Accept` header comes back as an HTML error page. In `bootstrap/app.php`:
```php
$exceptions->shouldRenderJsonWhen(
    fn (Request $request) => $request->is('api/*'),
);
```

## Step 4 — Products

- Run:
```bash
php artisan make:controller Api/ProductController --api
php artisan make:request StoreProductRequest
php artisan make:request UpdateProductRequest
php artisan make:resource ProductResource
```

Validation lives in form requests so the controller stays readable. `StoreProductRequest` requires everything, `UpdateProductRequest` uses `sometimes` so a PATCH with one field does not wipe the rest.

The output goes through `ProductResource` rather than returning the model, so i control exactly which columns leave the app — `created_by` is not something the client needs to see.

Only the person who added a product can change or delete it:
```php
abort_unless($product->created_by === $request->user()->id, 403);
```

### Search filters

The listing takes `search`, `min_price`, `max_price` and `in_stock`, all optional and all chainable, using `when()` so an absent filter adds nothing to the query:
```
GET /api/products?search=keyboard&min_price=50&max_price=200&in_stock=1
```
`search` matches on name **or** description, and the two `orWhere`s are wrapped in their own closure — without that nesting the `or` would leak out and cancel the price filters.

## Step 5 — Placing an order

- Run:
```bash
php artisan make:controller Api/OrderController
php artisan make:request StoreOrderRequest
php artisan make:resource OrderResource
php artisan make:resource OrderItemResource
php artisan make:class Services/OrderService
```

Placing an order is the only part of this project with real logic in it, so it goes in a service instead of the controller. The controller validates and returns; `OrderService::place()` does the work.

Everything happens inside one transaction:

1. Merge duplicate lines first — if someone posts the same `product_id` twice, i add the quantities together, otherwise each line checks stock on its own and two lines of 3 can both pass against a stock of 5.
2. `lockForUpdate()` on the products in the order. Two people buying the last unit at the same time would otherwise both read `stock = 1` and both pass. With the lock the second request waits, re-reads the reduced stock and gets rejected.
3. Collect **all** the short lines before throwing, so the customer sees every problem at once instead of fixing them one request at a time. Thrown as a `ValidationException` on `items`, so it comes back as a normal `422`.
4. Create the order, then one row per line with the price snapshot, and decrement stock.
5. Total it up and save it on the order.

If anything throws, the transaction rolls back — no order, no items, no stock movement. That is the case i cared most about testing.

`GET /orders` only ever queries through the relation:
```php
$request->user()->orders()
```
so there is no way to leak someone else's orders by forgetting a `where`. `GET /orders/{order}` uses route model binding, so that one does need the ownership check.

## Step 6 — Queue the slow part

- Run:
```bash
php artisan make:job ProcessOrder
php artisan make:mail OrderPlaced --markdown=mail.orders.placed
```

The request itself only does what has to be transactional — stock, totals, saving. The confirmation email is dispatched to the queue and the customer gets their `201` straight away, without waiting on a mail server.

The order is created as `pending` and the job flips it to `completed` once the email is out. The job checks that status before doing anything:
```php
if ($this->order->status !== 'pending') {
    return;
}
```
so if it retries after a mail server timeout the customer does not get a second confirmation. `$tries = 3`, and `failed()` marks the order `failed` and logs why.

Queue runs on the database connection, so there is nothing extra to install:
```bash
php artisan queue:work
```

With `MAIL_MAILER=log` the email is written to `storage/logs/laravel.log`.

## Step 7 — Cache the product listing

Products get read constantly and written rarely, so the listing is cached on Redis, keyed by the full URL — that way `?search=keyboard&page=2` gets its own entry and different filters never collide:
```php
Cache::tags('products')->remember('products.'.md5($request->fullUrl()), now()->addMinutes(5), fn () => ...);
```

Tagging is what makes this manageable. On create, update, delete — and after an order reduces stock — i just drop the whole tag:
```php
Cache::tags('products')->flush();
```
Without tags i would have to track every filter combination that has ever been cached in order to clear them. Flushing after an order matters as much as the others, otherwise the listing keeps showing stock that is already sold.

Set `CACHE_STORE=redis` in `.env` (the database cache driver does not support tags) and make sure Redis is up:
```bash
brew services start redis
```

## Step 8 — Rate limiting

Three limiters in `AppServiceProvider::boot()`:

| Limiter | Applies to | Limit |
| --- | --- | --- |
| `api` | everything under `/api` | 60/min |
| `auth` | register and login | 10/min per IP |
| `orders` | `POST /orders` | 20/min per user |

The `api` limiter buckets signed in callers by user id and only falls back to IP for guests:
```php
Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
```
If it keyed on IP alone, everyone in one office behind the same connection would share a single allowance and one busy client would lock out the rest.

`auth` is per IP on purpose — nobody is authenticated yet at that point, so IP is all there is to throttle on.

## Step 9 — Swagger docs

- Run:
```bash
composer require darkaonline/l5-swagger
php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
```

The docs are written as PHP 8 attributes right above the method they describe, so they sit next to the code and are hard to forget about when a route changes:
```php
#[OA\Post(
    path: '/api/orders',
    summary: 'Place an order',
    security: [['bearerAuth' => []]],
    ...
)]
public function store(StoreOrderRequest $request)
```

The shared pieces live in the base `app/Http/Controllers/Controller.php` — the `Info` block, the server URL, the bearer security scheme, and the error responses (401, 403, 404, 422, 429) that nearly every endpoint repeats. Each endpoint then just points at them:
```php
new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
```
Otherwise the same fifteen lines of "Unauthenticated" get copy pasted eleven times.

The response bodies are `#[OA\Schema]` attributes on the API resources — `ProductResource`, `OrderResource`, `OrderItemResource` — because that is the class that decides the shape, so if i add a field there the schema is right there to update too.

Two env values:
```env
L5_SWAGGER_CONST_HOST="${APP_URL}"
L5_SWAGGER_GENERATE_ALWAYS=true
```
The first puts the right host in the "Servers" dropdown so *Try it out* actually hits your machine. The second regenerates the spec on every page load, which you want in development and would turn off in production.

Regenerate by hand with:
```bash
php artisan l5-swagger:generate
```

| | |
| --- | --- |
| Swagger UI | `/api/documentation` |
| OpenAPI JSON | `/docs` |

To try a protected route: run **Auth → login**, copy the `token` out of the response, hit **Authorize** at the top right and paste it in. Every request after that carries the bearer header.

## Step 10 — Seeders

```bash
php artisan make:seeder ProductSeeder
php artisan migrate:fresh --seed
```

Gives you a `test@example.com` user (password `password`) and 30 products to poke at.

## Step 11 — Tests

```bash
php artisan test
```

43 tests, running on SQLite in memory so the suite needs no database of its own.

| File | Covers |
| --- | --- |
| `tests/Feature/AuthTest.php` | register, login, logout, token revocation, validation |
| `tests/Feature/ProductTest.php` | CRUD, ownership, validation, the search filters |
| `tests/Feature/OrderTest.php` | placement, stock, totals, rollback, ownership, the job and the email |
| `tests/Feature/ProductCacheTest.php` | cache hits and clearing on every write path |
| `tests/Feature/RateLimitTest.php` | throttled auth and orders, headers, per user buckets |

The ones i actually care about are the rollback test (nothing saved when one line of a multi line order is short), the duplicate line test, and the cache test that asserts a repeated listing runs **zero** product queries.

---

## Running it

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Point `.env` at your MySQL database, then:

```bash
php artisan migrate --seed
php artisan serve
```

In a second terminal, so orders get processed and emails go out:

```bash
php artisan queue:work
```

Then open **http://localhost:8000/api/documentation** for the Swagger UI — every endpoint below is documented there and you can call them straight from the page.

## Endpoints

| Method | Endpoint | Auth | What it does |
| --- | --- | :---: | --- |
| `POST` | `/api/register` | — | create a user, returns a token |
| `POST` | `/api/login` | — | returns a token |
| `POST` | `/api/logout` | ✅ | revokes the current token |
| `GET` | `/api/products` | ✅ | list products, filterable and paginated |
| `POST` | `/api/products` | ✅ | add a product |
| `GET` | `/api/products/{id}` | ✅ | one product |
| `PUT\|PATCH` | `/api/products/{id}` | ✅ | update, owner only |
| `DELETE` | `/api/products/{id}` | ✅ | delete, owner only |
| `POST` | `/api/orders` | ✅ | place an order |
| `GET` | `/api/orders` | ✅ | your orders |
| `GET` | `/api/orders/{id}` | ✅ | one order with its items |

Send the token as `Authorization: Bearer <token>`.

### Example

```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Authorization: Bearer 1|your-token" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"items":[{"product_id":1,"quantity":2}]}'
```

```json
{
  "data": {
    "id": 1,
    "status": "pending",
    "total_amount": "410.86",
    "items": [
      {
        "id": 1,
        "product_id": 1,
        "product_name": "Mechanical Keyboard",
        "quantity": 2,
        "unit_price": "205.43",
        "subtotal": "410.86"
      }
    ]
  }
}
```

Not enough stock comes back as a `422`:

```json
{
  "message": "Mechanical Keyboard: you asked for 5 but only 2 left in stock.",
  "errors": {
    "items": ["Mechanical Keyboard: you asked for 5 but only 2 left in stock."]
  }
}
```

## What i would do next

- Soft delete products instead of hard delete, so `order_items.product_id` stays valid forever. Right now the foreign key is `restrictOnDelete`, which means you simply cannot delete a product that has ever been ordered.
- Move `status` to a backed enum. It is a plain string today and there are only three values.
- Make `per_page` and sorting configurable on the product listing.
