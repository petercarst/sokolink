# Database — setup and operation

Everything needed to get the SokoLink database running on a clean machine, plus the rules for
changing it afterwards.

| File | What it is | Safe to run on production? |
|---|---|---|
| `schema.sql` | The 44-table structure. Creates the database if absent. | Yes, on an empty database |
| `seed.sql` | **Development data only.** 14 fake accounts with a shared, published password. | **No. Never.** |
| `migrations/` | Ordered, forward-only changes made after Phase 2 | Only on a database that already has data |

Design rationale is in [`../docs/DATABASE_DESIGN.md`](../docs/DATABASE_DESIGN.md); the diagrams are
in [`../docs/ER_DIAGRAM.md`](../docs/ER_DIAGRAM.md).

---

## 1. Requirements

- **MariaDB 10.4+** (what XAMPP ships) **or MySQL 8.0+**. Both are supported; the schema avoids
  features exclusive to either.
- `utf8mb4` available (it is, on both).
- A user that may `CREATE DATABASE`, or a `sokolink` database created for you in advance.

Verified on: MariaDB 10.4.32, XAMPP for Windows, PHP 8.2.

---

## 2. Import — command line (recommended)

From the project root. On Windows, `mysql` lives at `C:\xampp\mysql\bin\mysql.exe`; add it to
`PATH` or use the full path.

```bash
# 1. Structure. Creates the `sokolink` database if it does not exist.
mysql -u root -p < database/schema.sql

# 2. Development data. Skip this on anything resembling a real deployment.
mysql -u root -p sokolink < database/seed.sql
```

Both scripts are silent on success and exit `0`. Any output is an error worth reading.

`schema.sql` does **not** drop an existing database or any table. Pointing it at a database that
already has SokoLink tables will fail on the first `CREATE TABLE` rather than destroy anything —
which is the intended behaviour. To start over deliberately, see §6.

## 3. Import — phpMyAdmin

1. Start Apache and MySQL in the XAMPP control panel.
2. Open <http://localhost/phpmyadmin>.
3. **Import** tab → choose `database/schema.sql` → **Go**. No database needs to be selected first;
   the file creates and selects `sokolink` itself.
4. Select the `sokolink` database → **Import** → choose `database/seed.sql` → **Go**.

If phpMyAdmin rejects the upload as too large, use the command line instead. `seed.sql` is around
55 KB, so this is unlikely.

---

## 4. Verify the import

```bash
mysql -u root sokolink -e "SELECT COUNT(*) AS tables_created FROM information_schema.tables WHERE table_schema='sokolink';"
```

Expect **44**.

Then run the two test scripts, which check far more than a row count:

```bash
php tests/Integration/schema_contracts_test.php   # 43 assertions
php tests/Concurrency/oversell_test.php           # 7 assertions, two live connections
```

Both print per-assertion `PASS`/`FAIL` and exit `0` when everything passes. They read their
connection settings from constants at the top of each file — edit those if your MySQL user is not
`root` with an empty password.

The concurrency test **modifies one inventory row** (it forces a single unit into stock to create
the race). Re-run `seed.sql` afterwards if you want the sample data exactly as shipped.

Expected row counts after seeding, for a quick eyeball:

| Table | Rows | Table | Rows |
|---|---|---|---|
| `users` | 14 | `orders` | 7 |
| `permissions` | 46 | `seller_orders` | 8 |
| `role_permissions` | 92 | `order_items` | 12 |
| `products` | 18 | `order_status_history` | 48 |
| `inventory` | 23 | `payment_transactions` | 8 |
| `delivery_tasks` | 4 | `reorder_reminders` | 7 |
| `support_tickets` | 5 | `support_messages` | 11 |

---

## 5. Test accounts (seed data only)

All fourteen share one password:

```
SokoLink!Dev2026
```

It is stored as a real bcrypt hash, not plain text — but it is written in this file, in a public
repository, so **these accounts must never exist anywhere reachable from the internet.**

| Email | Role | Deliberately interesting because |
|---|---|---|
| `admin@sokolink.test` | admin | full platform access |
| `support@sokolink.test` | support | sees internal ticket notes; customers do not |
| `seller.mama.lishe@sokolink.test` | seller | pickup-capable store, has a ready-for-collection order |
| `seller.duka.kuu@sokolink.test` | seller | delivery store, shares an order with Mama Lishe |
| `seller.bustani@sokolink.test` | seller | has an out-of-stock and a low-stock product |
| `seller.pending@sokolink.test` | seller | **`pending_approval`** — must not be able to trade yet |
| `agent.juma@sokolink.test` | delivery agent | has an active delivery task |
| `agent.neema@sokolink.test` | delivery agent | has a **failed** delivery awaiting retry |
| `customer.asha@sokolink.test` | customer | consented to reminders, has repeat purchases |
| `customer.baraka@sokolink.test` | customer | **never consented** — must receive nothing |
| `customer.grace@sokolink.test` | customer | consented then **withdrew** — must receive nothing |
| `customer.neema@sokolink.test` | customer | the two-seller order `SL-2026-9F3K2A` |
| `customer.joseph@sokolink.test` | customer | a seller rejection with a refund |
| `customer.rashid@sokolink.test` | customer | **`suspended`** — must not be able to log in |

The awkward accounts are the point. A seed where everything is healthy tests nothing.

> Passwords for these accounts are not usable until Phase 3 implements authentication. Phase 1's
> login screens are previews and say so on the page.

---

## 6. Resetting

`schema.sql` deliberately refuses to overwrite. To rebuild from scratch — **which destroys all
data in the `sokolink` database** — drop it explicitly first:

```bash
mysql -u root -p -e "DROP DATABASE IF EXISTS sokolink;"
mysql -u root -p < database/schema.sql
mysql -u root -p sokolink < database/seed.sql
```

`seed.sql` on its own is safe to re-run: it truncates the tables it populates and reinserts them,
so the sample data returns to its shipped state without touching the structure.

Never run either of those on a database holding real orders.

---

## 7. Backups

Before any migration, any experiment, and any `DROP`:

```bash
# Structure and data, into the git-ignored backups folder
mysqldump -u root -p --single-transaction --routines sokolink > storage/backups/sokolink_20261001.sql

# Restore
mysql -u root -p sokolink < storage/backups/sokolink_20261001.sql
```

`--single-transaction` gives a consistent snapshot without locking the tables, which matters
because InnoDB is used throughout.

Backup files contain customer data. `storage/backups/` is outside the web root and is
git-ignored, so dumps written there stay out of the repository. A dump written next to
`schema.sql` **would** be committed — don't.

---

## 8. Changing the schema after Phase 2

`schema.sql` is the state of the database at the end of Phase 2. From here on it is **not** edited
in place for structural changes to a running system; changes go into `migrations/` as numbered,
forward-only files:

```
migrations/
  2026_09_23_01_cash_on_fulfilment_controls.sql
```

`schema.sql` is always the **current** structure, so a fresh install needs it
alone — every migration's change is already folded into it. The files in
`migrations/` exist for a database that already holds data and cannot simply be
recreated. Running one against a fresh install is harmless but pointless: it
will fail on the duplicate column, which is the intended behaviour.

Rules:

1. One logical change per file. Name it after what it does.
2. Forward-only. If something must be undone, that is a new migration.
3. Every migration begins with the same two `SET SESSION` lines as `schema.sql` (§9).
4. Never `DROP` a table or column that holds data without explicit sign-off — and a backup taken
   first.
5. When a migration lands, update `schema.sql` **and** `../docs/DATABASE_DESIGN.md` §12 so a fresh
   install and a migrated install end up identical.

The folder is empty today. That is correct: nothing has changed since the schema was written.

---

## 9. Connection settings the application must use

Not optional, and not cosmetic. Phase 3's `Core\Database` must issue all of this on **every**
connection:

```php
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=sokolink;charset=utf8mb4',
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$pdo->exec("SET SESSION time_zone='+00:00'");
```

Why each line is there:

| Setting | Consequence of omitting it |
|---|---|
| `STRICT_ALL_TABLES` | This XAMPP build defaults to **non-strict**. Measured behaviour: `'TOOLONGVALUE'` into a `VARCHAR(4)` silently stored `'TOOL'`; an invalid `DECIMAL` silently became `0`. Silent truncation of money is not acceptable. |
| `time_zone='+00:00'` | Every stored `DATETIME` is UTC. A connection in local time makes "delivered at" wrong by three hours and nothing warns you. |
| `ERRMODE_EXCEPTION` | Failures become return values nobody checks. |
| `EMULATE_PREPARES => false` | With emulation on, PDO interpolates values client-side. Real server-side prepares are what make the injection guarantee a guarantee. |
| `charset=utf8mb4` in the DSN | Mojibake, and a second encoding boundary to get wrong. |

Credentials come from `.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
`.env` is git-ignored and sits above the web root. Copy `.env.example` to `.env` and edit it; never
put credentials in a file under `public/`.

**The default XAMPP `root` account with an empty password is fine for local development and
unacceptable anywhere else.** For a real deployment, create a dedicated user with only the rights
the application needs:

```sql
CREATE USER 'sokolink_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON sokolink.* TO 'sokolink_app'@'localhost';
FLUSH PRIVILEGES;
```

No `DROP`, no `ALTER`, no `CREATE`. The application never needs to change its own structure at
runtime; migrations are run deliberately, by a person, with a different account.

---

## 10. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Access denied for user 'root'@'localhost'` | MySQL has a root password. Add `-p`, and set `DB_PASSWORD` in `.env`. |
| `Can't connect to MySQL server on '127.0.0.1'` | MySQL is not running. Start it in the XAMPP control panel. |
| `Unknown database 'sokolink'` | `schema.sql` has not been imported, or it was imported into a different server. |
| `Table 'users' already exists` | The database already holds SokoLink tables. This is the safety net working — see §6 to reset deliberately. |
| `Specified key was too long` | The server is on `utf8mb4` with an old `innodb_large_prefix` default. Requires MariaDB 10.2+/MySQL 5.7+; both XAMPP and any supported server are past that. |
| Imports fine, but tests fail on `sql_mode` | The assertion is doing its job. The **session** must set strict mode; it is not inherited from the server default. |
| Times look three hours out | A connection somewhere is missing `SET SESSION time_zone='+00:00'`. Storage is UTC; `Africa/Dar_es_Salaam` is applied at render time only. |
| `qty_reserved` rejected with a constraint error | `chk_inventory_reserved` refused an oversell. The write was wrong, not the constraint. |

---

## 11. Rules that do not bend

1. **`seed.sql` never runs against real data.** It truncates.
2. **No table or column is dropped without explicit approval and a backup.**
3. **No payment credential is ever stored.** There is no column for a card number, CVV or PIN, and
   `tests/Integration/schema_contracts_test.php` asserts that the count of such columns is zero. If
   a future change adds one, that test fails, which is the point.
4. **`audit_log`, `order_status_history`, `stock_movements`, `consent_records` and
   `delivery_events` are append-only.** No update path, no delete path, not even for
   administrators.
5. **Credentials live in `.env`, never in SQL files, never in `public/`, never in git.**
