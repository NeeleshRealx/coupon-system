# High-Concurrency Coupon System (Laravel 12 · MySQL 8)

This is a coupon-claiming API. The database itself makes sure a campaign never hands out more coupons than it has and a user never holds two coupons for the same campaign. It also makes sure a coupon is never redeemed twice and every state change gets written to an audit log. All of this holds under heavy concurrent traffic, and it keeps holding if the expiry cron job goes down.

---

## 1. Quick start

You'll need PHP 8.2 or newer with `pdo_mysql`, Composer 2, and MySQL 8.0.16 or newer (older versions don't enforce `CHECK` constraints). The concurrency tests also need the `pcntl` and `posix` extensions, so you'll have to run them on a Unix-like system. WSL works fine on Windows.

```bash
# 1. Create a fresh Laravel 12 app and install Sanctum for token auth
composer create-project laravel/laravel:^12.0 coupon-system
cd coupon-system
php artisan install:api --without-migration-prompt

# 2. Copy this repository's files over the fresh app (overwrite when asked)
cp -R /path/to/submission/. .

# 3. Point .env at MySQL
#    DB_CONNECTION=mysql
#    DB_HOST=127.0.0.1
#    DB_PORT=3306
#    DB_DATABASE=coupon_system
#    DB_USERNAME=<user>
#    DB_PASSWORD=<password>
mysql -u root -p -e "CREATE DATABASE coupon_system; CREATE DATABASE coupon_system_test;"

# 4. Migrate and seed (the seeder prints demo API tokens)
php artisan migrate --seed

# 5. Start the app and the scheduler
php artisan serve
php artisan schedule:work        # in production, cron runs `php artisan schedule:run` every minute
```

### Running the tests

```bash
php artisan test                         # every suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Concurrency # real multi-process races, needs MySQL and pcntl
```

`phpunit.xml` sets `DB_CONNECTION=mysql` and `DB_DATABASE=coupon_system_test`. The host, user and password come from your `.env`. I run the tests on MySQL on purpose. Row locks, generated columns, `CHECK` constraints and unique-violation codes either behave differently on SQLite or don't work there at all, so a passing SQLite run wouldn't tell you much.

---

## 2. API

Every endpoint needs an `Authorization: Bearer <sanctum token>` header and returns JSON. Business errors always come back in the same shape: `{"error": {"code": "...", "message": "...", "details": {...}}}`. Validation errors use Laravel's standard `422 {"message", "errors"}` response.

| Method & path | Body | Success | Business errors |
|---|---|---|---|
| `POST /api/coupons/claim` | `{"campaign_id": 1}` | `201 {"claim_id": 12, "expires_at": "2026-06-17T11:41:22Z"}` | `404 campaign_not_found`, `409 campaign_not_active`, `409 duplicate_claim` (with `details.claim_id`), `409 quota_exhausted` |
| `POST /api/coupons/redeem` | `{"claim_id": 12, "transaction_id": "TX123456", "order_id": "ORD-1"?}` | `201` the first time, `200` with `Idempotent-Replayed: true` on a replay | `404 claim_not_found`, `409 claim_expired`, `409 invalid_claim_state`, `409 transaction_id_conflict` |
| `POST /api/coupons/cancel` | `{"claim_id": 12}` | `200 {"claim_id", "campaign_id", "status": "cancelled"}` | `404`, `409 invalid_claim_state`, `409 claim_expired` |
| `GET /api/coupons/my-active?per_page=15` | – | `200 {"data": [{claim_id, status, campaign{…}, claimed_at, expires_at, remaining_seconds}], "links", "meta"}` | – |
| `POST /api/admin/coupon/revoke` | `{"claim_id": 12, "reason": "Fraud prevention"}` | `200 {"claim_id", "campaign_id", "status": "cancelled"}` | `403` for non-admins, `404`, `409 invalid_claim_state` |

You'll only see `503 service_busy` (with a `Retry-After` header) if lock contention is still there after three automatic transaction retries.

```bash
curl -s -X POST localhost:8000/api/coupons/claim  -H "Authorization: Bearer $ALICE" -H "Content-Type: application/json" -d '{"campaign_id":1}'
curl -s -X POST localhost:8000/api/coupons/redeem -H "Authorization: Bearer $ALICE" -H "Content-Type: application/json" -d '{"claim_id":1,"transaction_id":"TX123456"}'
curl -s        localhost:8000/api/coupons/my-active -H "Authorization: Bearer $ALICE"
curl -s -X POST localhost:8000/api/admin/coupon/revoke -H "Authorization: Bearer $ADMIN" -H "Content-Type: application/json" -d '{"claim_id":1,"reason":"Fraud prevention"}'
```

---

## 3. Design overview

```
Route → auth:sanctum → throttle → FormRequest (validation) → Controller (thin)
      → CouponService (all rules, transactions, locks, audit log) → API Resource
                ↑
coupons:expire (scheduler, every minute) ──┘   same service, same locking rules
```

- `CouponService` is the only code that writes coupon data. The scheduler and the tests call it too, so you'll find every concurrency rule in that one file.
- `CouponClaimStatus` is an explicit state machine. A claim can move from `claimed` to `redeemed`, `expired` or `cancelled`, and an admin revoke can move a `redeemed` claim to `cancelled`. Every transition goes through one method that checks it's allowed and writes the `coupon_logs` row in the same transaction.
- Domain exceptions render themselves as JSON `4xx` responses and don't show up in error reporting.

### Schema highlights

| Table | Integrity guarantees |
|---|---|
| `campaigns` | `UNIQUE(code)`, `CHECK (0 <= claimed_quantity <= total_quantity)` and `CHECK (expires_at > starts_at)` |
| `coupon_claims` | A stored generated column, `holding_flag`, is 1 when the status is `claimed` or `redeemed` and NULL otherwise. It's covered by `UNIQUE(campaign_id, user_id, holding_flag)`. Because NULLs never collide in a unique index, a user can have any number of expired or cancelled rows but only one holding claim per campaign. There are also indexes for the scheduler scan, the self-healing scan and `my-active`. |
| `coupon_redemptions` | `UNIQUE(transaction_id)` is the idempotency key. `UNIQUE(coupon_claim_id)` allows one redemption per claim whatever key is used. |
| `coupon_logs` | Append-only. There's no `updated_at` column, the model throws on update or delete, and the foreign keys use `RESTRICT`. I added an `actor_id` column so you can see who made each change (a user, an admin, or `NULL` for the system). |

`claimed_quantity` counts every claim that's holding a slot, which means claimed and redeemed ones. A redeemed coupon keeps its slot, since the campaign is for the "first 100 users". Expired, cancelled and revoked coupons give their slot back.

---

## 4. Architectural reasoning

### 4.1 Quota tracking: Redis `DECR` or the database?

I keep the quota in MySQL as a counter on the campaign row, and I only update it while holding that row's lock.

- The claim row and the counter change in one ACID transaction. With Redis `DECR` they'd live in two separate systems. If the database insert failed after the `DECR`, or the process died in between, you'd leak a slot and need compensation logic and a reconciliation job to fix it. Redis can also lose recent writes during a failover.
- The database already has to enforce correctness through the unique index and the CHECK constraint. Adding Redis would give you one more system to keep in sync.
- The throughput is plenty for this scale. A claim transaction holds the campaign lock for a few milliseconds, so one campaign can handle hundreds of claims per second. A "first 100 users" campaign sells out in well under a second either way.

I'd bring in Redis for very large flash sales, say 100k+ requests per second on one campaign. In that setup it would sit in front of the database as a gate. An atomic Lua script decrements the counter only while it's positive, so most losing requests get turned away before they reach MySQL. The winners then go through the normal transactional claim. The database constraints still have the final say, and a periodic job resets the Redis counter from `campaigns.claimed_quantity`.

### 4.2 Concurrency control: pessimistic or optimistic locking?

I use pessimistic locking (`SELECT … FOR UPDATE`) wherever contention is high, and database constraints act as a safety net behind it.

| Operation | Lock(s) | Why |
|---|---|---|
| claim | campaign row | Hundreds of requests hit the same row. With optimistic locking (a version column plus retries), almost every request would fail and retry over and over. Waiting in line for a row lock is fair and cheap. |
| redeem | claim row only | This makes the impatient user's repeat requests and the scheduler take turns on that one claim. Redeem doesn't touch the counter, so it doesn't need the campaign lock. |
| cancel / revoke / expire | campaign, then claim (then redemption) | These change the counter, so they need the campaign lock. |

These rules keep it safe:

1. Locks are always taken in the same order: campaign, then claim, then redemption. No transaction ever waits for a lock in the reverse order, so deadlock cycles can't form.
2. Every transaction starts with its locking read. Under MySQL's default `REPEATABLE READ`, plain `SELECT`s read from a snapshot taken at the first plain read. Taking the lock first means that snapshot already includes everything committed before the lock was granted. For example, the impatient user's second request has to see the redemption the first one just made.
3. State is checked again after the lock is held. Anything read before the lock is treated as stale.
4. Database constraints are the last line of defence, and any violation comes back as a `409`. You won't get a `500` from them.
5. Transactions retry automatically, up to three attempts, on a deadlock or lock-wait timeout.

Where contention is low, the design is effectively optimistic. Idempotency relies on the `UNIQUE(transaction_id)` insert, and if two different claims race with the same key, the loser gets caught and turned into a replay or a `409`.

### 4.3 Asynchronous processing (queues)

- Claims stay synchronous. The user needs an immediate yes or no, and the database transaction only takes a few milliseconds. A queue would add latency, a "pending" state and polling, and the worker would still need the same atomic database logic. It could work as a waiting room for extreme spikes (accept, return 202, process in order), but the client side gets more complicated.
- The redemption state change also stays synchronous. It's part of checkout, so it has to finish in the request and stay idempotent. Side effects should go on a queue after commit, such as notifications, analytics, fraud scoring and CRM sync. Use `ShouldQueueAfterCommit` or `afterCommit()` so a rolled-back transaction never fires an event.
- Expiry already runs in the background through the scheduler. At a larger scale it could fan out into one queued job per campaign.

### 4.4 Disaster recovery: what if the expiry cron is down for 3 hours?

Nothing breaks. The cron only tidies up.

- Redeem checks `expires_at` itself, so you can't redeem an overdue claim even if its status still says `claimed`.
- `my-active` filters on `expires_at > now`, so overdue claims never show up as active.
- Claims fix things up as they go. When a campaign looks full, the claim transaction (which already holds the campaign lock) expires up to 100 of that campaign's overdue claims, frees their slots and then serves the request. A user's own overdue claim gets expired the same way, so it won't block them from claiming again through the unique index.
- When the cron comes back, `coupons:expire` picks up everything it missed. It scans `status='claimed' AND expires_at <= now` (which is indexed) with `chunkById` and expires each claim in its own short transaction, checking state again under the lock. You can run it repeatedly while live traffic is coming in.
- `withoutOverlapping()` stops runs from overlapping, and `onOneServer()` makes sure only one server runs it. In production you'd want alerts on how many claims are overdue and on the job not succeeding for N minutes.

---

## 5. Critical edge cases

| Scenario | What happens |
|---|---|
| Last-coupon race: 10 users, 1 slot left | All 10 wait on the campaign row lock. The first one sees 99/100, inserts the claim, increments the counter and commits. The other nine then see 100/100 and get `409 quota_exhausted`. If the code ever got this wrong, `CHECK (claimed_quantity <= total_quantity)` would still block the 101st slot. |
| Impatient user: 5 identical redeem requests | All five wait on the claim row lock. The first one redeems and gets `201`. The other four find the existing redemption for this claim with the same `transaction_id` and return it with `200` and `Idempotent-Replayed: true`. You end up with one redemption row and one log entry. A different `transaction_id` gets `409 invalid_claim_state`, and reusing the same key on another claim gets `409 transaction_id_conflict`. |
| Expiry collision | Redeem and the scheduler both need the claim row lock. If the scheduler gets it first, the status becomes `expired` and the redeem then gets a `409`. If redeem gets it first, the status becomes `redeemed` and the scheduler skips the claim when it checks again. The outcome is decided when the lock is granted, and `expires_at == now` counts as expired. |

---

## 6. Testing

| Suite | What it covers |
|---|---|
| `Unit/CouponClaimStatusTest` | The full 4×4 state-transition matrix. |
| `Feature/*` | Every endpoint. Successful calls, validation and 401/403/404 responses. Inactive campaigns and window boundaries. Duplicate claims and quota limits. Lazy expiry and self-healing, idempotent replays and key reuse across claims. Terminal states and the exact expiry boundary. A rollback when the last write in a transaction fails. Admin revokes of claimed and redeemed coupons. Scheduler boundaries, idempotency and catch-up. Log immutability, plus the database unique and CHECK backstops. |
| `Concurrency/*` | Real parallel processes (`pcntl_fork`, each with its own MySQL connection). The last-coupon race and 40 users competing for 10 slots. A double-clicked claim and cancels racing claims on the counter. Five identical redeems and five redeems with different keys. The same key on two claims, and the scheduler racing redeem on 10 boundary claims. Two deterministic tests also hold a lock for 1.5 seconds. One shows the scheduler waits for an in-flight redemption and then skips it. The other shows a redemption waits for an in-flight expiry and gets rejected, even though its own clock says the claim is still valid. |

Keep the limits in mind. A passing race test only shows there was no bug in the interleavings that happened to run. The deterministic lock-holding tests and the database constraints cover the orderings that matter most. The concurrency tests skip themselves on SQLite, which ignores `FOR UPDATE` and runs writers one at a time, so a pass there wouldn't prove anything.

---

## 7. Assumptions

1. A campaign is active when `status = active` and `starts_at <= now < expires_at`.
2. A claim is valid for exactly 24 hours from `claimed_at`, and the campaign's end date doesn't shorten it. A claim counts as expired from the moment `now >= expires_at`.
3. Redeemed coupons keep their slot. When an admin revokes a redeemed coupon, the redemption is voided and the slot is released. Refunding the order is the order system's job.
4. Only the owner can cancel a claim, and only while it's `claimed` and before it expires.
5. `order_id` is optional in the redeem payload. The spec's payload doesn't include it, but the table has the column.
6. If you ask for another user's claim, you'll get a `404` so claim IDs can't be probed.
7. All timestamps are in UTC and the app servers are NTP-synced. The app clock is the only clock used for comparisons, and it's truncated to whole seconds to match the `DATETIME` columns.
8. Authentication uses Laravel Sanctum personal access tokens. Admins are users with `users.is_admin` set, and that column can't be mass-assigned.

## 8. Known limitations and next steps

- A user whose coupon was revoked can claim again if there are free slots, since the spec doesn't forbid it. A per-campaign block list would close that gap.
- Each campaign has one hot row, so its claim throughput tops out at roughly 1 / (lock hold time). Section 4.1 describes the Redis gate for that case.
- The app enforces `coupon_logs` immutability. In production you'd also revoke `UPDATE` and `DELETE` on that table from the app's database user.
- Rate limiting uses the default cache store. On multiple servers you'd switch it to Redis.
- There aren't any campaign-management endpoints since they're out of scope. Campaigns come from the seeder or the factory.
