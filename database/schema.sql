-- =============================================================================
--  SokoLink - Online Marketplace & Customer Retention Platform
--  SCHEMA  (Phase 2)
--
--  Target:   MariaDB 10.4+ / MySQL 8.0+   (verified on MariaDB 10.4.32, XAMPP)
--  Engine:   InnoDB everywhere
--  Charset:  utf8mb4 / utf8mb4_unicode_ci
--
--  Full rationale, column-by-column, in docs/DATABASE_DESIGN.md.
--  Setup instructions in database/README.md.
--
--  ---------------------------------------------------------------------------
--  FOUR RULES THIS FILE ENFORCES AT THE STORAGE LAYER, NOT IN APPLICATION CODE
--  ---------------------------------------------------------------------------
--   1. Stock cannot be oversold.      inventory CHECK + guarded UPDATE
--   2. A payment cannot be applied     payment_transactions UNIQUE
--      twice from a replayed webhook.  (gateway, gateway_reference)
--   3. A reorder reminder cannot be    reorder_reminders UNIQUE
--      sent twice for the same cycle.  (customer_id, product_id, cycle_key)
--   4. A double-submitted form cannot  idempotency_keys UNIQUE (key_hash)
--      create two orders.
--
--  Application code can be refactored. A constraint cannot be refactored away
--  by accident, which is why these four live here.
--
--  ---------------------------------------------------------------------------
--  TIMEZONE CONVENTION
--  ---------------------------------------------------------------------------
--  Every DATETIME in this schema is UTC. CURRENT_TIMESTAMP resolves against the
--  SESSION time zone, so the session is pinned to +00:00 below and the
--  application connection does the same (docs/DATABASE_DESIGN.md section 4).
--  Display conversion to Africa/Dar_es_Salaam happens at render time only.
--
--  ---------------------------------------------------------------------------
--  STRICT MODE IS NOT OPTIONAL
--  ---------------------------------------------------------------------------
--  This XAMPP build ships WITHOUT strict mode, which silently truncates an
--  over-long string and turns an invalid DECIMAL into 0. For a system handling
--  money that is unacceptable, so the session is set to STRICT_ALL_TABLES here
--  and the application connection must do the same on every connect.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;

CREATE DATABASE IF NOT EXISTS `sokolink`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `sokolink`;


-- =============================================================================
--  1. IDENTITY AND ACCESS CONTROL
-- =============================================================================

-- Every person on the platform, whatever their role. One table, because a
-- seller who also buys is one human being with one password (FR-AUTH-11).
CREATE TABLE users (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email               VARCHAR(190)    NOT NULL,
    -- password_hash() output. 255 leaves room for a future algorithm change;
    -- nothing anywhere in this system stores a readable password.
    password_hash       VARCHAR(255)    NOT NULL,
    first_name          VARCHAR(80)     NOT NULL,
    last_name           VARCHAR(80)     NOT NULL,
    phone               VARCHAR(32)             DEFAULT NULL,
    status              ENUM('pending_verification','pending_approval','active','suspended','closed')
                                        NOT NULL DEFAULT 'pending_verification',
    status_reason       VARCHAR(500)            DEFAULT NULL,
    email_verified_at   DATETIME                DEFAULT NULL,
    locale              VARCHAR(10)     NOT NULL DEFAULT 'en',
    last_login_at       DATETIME                DEFAULT NULL,
    last_login_ip       VARBINARY(16)           DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status),
    KEY idx_users_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='All people on the platform. Roles are attached via user_roles.';

CREATE TABLE roles (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key    VARCHAR(40)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    description VARCHAR(255)          DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='customer, seller, delivery_agent, support, admin.';

CREATE TABLE permissions (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key  VARCHAR(80)  NOT NULL,
    description     VARCHAR(255) NOT NULL,
    area            VARCHAR(40)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_key (permission_key),
    KEY idx_permissions_area (area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Granular capabilities, e.g. order.transition.accept.';

-- Permissions attach to ROLES, never directly to users, so there is exactly one
-- place to audit what a role can do (USER_ROLES_AND_PERMISSIONS.md section 1.1).
CREATE TABLE role_permissions (
    role_id       SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_rp_permission (permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles (id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id    BIGINT UNSIGNED   NOT NULL,
    role_id    SMALLINT UNSIGNED NOT NULL,
    granted_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    granted_by BIGINT UNSIGNED            DEFAULT NULL,
    PRIMARY KEY (user_id, role_id),
    KEY idx_ur_role (role_id),
    CONSTRAINT fk_ur_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role    FOREIGN KEY (role_id)    REFERENCES roles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ur_granter FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email verification, password reset, remember-me and unsubscribe tokens.
-- The token is stored as a SHA-256 HASH, never in plain text: a leaked database
-- must not hand an attacker a working password-reset link (FR-AUTH-05).
CREATE TABLE user_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    purpose     ENUM('email_verification','password_reset','remember_me','unsubscribe') NOT NULL,
    selector    CHAR(24)        NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    expires_at  DATETIME                DEFAULT NULL,
    consumed_at DATETIME                DEFAULT NULL,
    created_ip  VARBINARY(16)           DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokens_selector (selector),
    KEY idx_tokens_user_purpose (user_id, purpose),
    KEY idx_tokens_expiry (expires_at),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Single-use, time-limited tokens. Stored hashed.';

-- Login throttling, per account AND per IP (FR-AUTH-09).
CREATE TABLE auth_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(190)            DEFAULT NULL,
    ip_address   VARBINARY(16)   NOT NULL,
    successful   TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_email_time (email, attempted_at),
    KEY idx_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  2. CUSTOMER PROFILE AND ADDRESSES
-- =============================================================================

CREATE TABLE customer_profiles (
    user_id            BIGINT UNSIGNED NOT NULL,
    default_address_id BIGINT UNSIGNED          DEFAULT NULL,
    orders_count       INT UNSIGNED    NOT NULL DEFAULT 0,
    lifetime_spend     DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_cprofile_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_addresses (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    label         VARCHAR(60)     NOT NULL DEFAULT 'Home',
    recipient     VARCHAR(160)    NOT NULL,
    phone         VARCHAR(32)     NOT NULL,
    region        VARCHAR(80)     NOT NULL,
    district      VARCHAR(80)     NOT NULL,
    ward          VARCHAR(80)             DEFAULT NULL,
    street        VARCHAR(190)    NOT NULL,
    landmark      VARCHAR(190)            DEFAULT NULL,
    instructions  VARCHAR(500)            DEFAULT NULL,
    -- Resolved when the address is saved. NULL means no served zone, which the
    -- UI must present as collection-only BEFORE payment, not after (FR-CART-07).
    zone_id       BIGINT UNSIGNED         DEFAULT NULL,
    is_default    TINYINT(1)      NOT NULL DEFAULT 0,
    archived_at   DATETIME                DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_addr_user (user_id, archived_at),
    KEY idx_addr_zone (zone_id),
    CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Addresses are archived, never hard-deleted: an old order must keep its delivery address.';


-- =============================================================================
--  3. SELLERS AND STORES
-- =============================================================================

CREATE TABLE seller_applications (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    business_name       VARCHAR(160)    NOT NULL,
    business_type       ENUM('sole_trader','partnership','company','cooperative') NOT NULL,
    registration_number VARCHAR(80)             DEFAULT NULL,
    contact_name        VARCHAR(160)    NOT NULL,
    contact_phone       VARCHAR(32)     NOT NULL,
    region              VARCHAR(80)     NOT NULL,
    district            VARCHAR(80)     NOT NULL,
    store_name          VARCHAR(160)    NOT NULL,
    street              VARCHAR(190)    NOT NULL,
    offers_pickup       TINYINT(1)      NOT NULL DEFAULT 1,
    offers_delivery     TINYINT(1)      NOT NULL DEFAULT 0,
    categories_text     VARCHAR(1000)   NOT NULL,
    status              ENUM('pending_approval','approved','rejected') NOT NULL DEFAULT 'pending_approval',
    -- Mandatory on rejection at the application layer: "unsuccessful" with no
    -- reason produces a support ticket and an identical reapplication.
    decision_reason     VARCHAR(1000)           DEFAULT NULL,
    decided_by          BIGINT UNSIGNED         DEFAULT NULL,
    decided_at          DATETIME                DEFAULT NULL,
    submitted_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_appl_status (status, submitted_at),
    KEY idx_appl_user (user_id),
    KEY idx_appl_decider (decided_by),
    CONSTRAINT fk_appl_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_appl_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sellers (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    slug                VARCHAR(160)    NOT NULL,
    business_name       VARCHAR(160)    NOT NULL,
    registration_number VARCHAR(80)             DEFAULT NULL,
    contact_email       VARCHAR(190)    NOT NULL,
    contact_phone       VARCHAR(32)     NOT NULL,
    status              ENUM('pending_approval','active','paused','suspended') NOT NULL DEFAULT 'pending_approval',
    -- Recorded per sale from day one. Payouts are NOT built in v1 (OQ-04);
    -- adding the column now is cheap, backfilling it later is not.
    commission_percent  DECIMAL(5,2)    NOT NULL DEFAULT 5.00,
    prep_hours          SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    auto_accept         TINYINT(1)      NOT NULL DEFAULT 0,
    -- Cash is the one method where the SELLER carries the risk: they pick and
    -- hold goods for somebody who has committed nothing. They may refuse it.
    accepts_cod         TINYINT(1)      NOT NULL DEFAULT 1,
    low_stock_threshold INT UNSIGNED    NOT NULL DEFAULT 10,
    rating_avg          DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
    rating_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    approved_at         DATETIME                DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sellers_slug (slug),
    UNIQUE KEY uq_sellers_user (user_id),
    KEY idx_sellers_status (status),
    CONSTRAINT fk_sellers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_sellers_commission CHECK (commission_percent >= 0 AND commission_percent <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stores (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id            BIGINT UNSIGNED NOT NULL,
    slug                 VARCHAR(160)    NOT NULL,
    name                 VARCHAR(160)    NOT NULL,
    region               VARCHAR(80)     NOT NULL,
    district             VARCHAR(80)     NOT NULL,
    street               VARCHAR(190)    NOT NULL,
    landmark             VARCHAR(190)            DEFAULT NULL,
    phone                VARCHAR(32)     NOT NULL,
    latitude             DECIMAL(10,7)           DEFAULT NULL,
    longitude            DECIMAL(10,7)           DEFAULT NULL,
    pickup_instructions  VARCHAR(1000)   NOT NULL,
    collection_window_hours SMALLINT UNSIGNED NOT NULL DEFAULT 72,
    offers_pickup        TINYINT(1)      NOT NULL DEFAULT 1,
    offers_delivery      TINYINT(1)      NOT NULL DEFAULT 0,
    status               ENUM('draft','published','paused','suspended') NOT NULL DEFAULT 'draft',
    rating_avg           DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
    rating_count         INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stores_slug (slug),
    KEY idx_stores_seller (seller_id, status),
    KEY idx_stores_location (region, district),
    CONSTRAINT fk_stores_seller FOREIGN KEY (seller_id) REFERENCES sellers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_hours (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id   BIGINT UNSIGNED NOT NULL,
    -- 0 = Monday .. 6 = Sunday, in the platform DISPLAY timezone. Opening hours
    -- are a local-wall-clock concept; storing them UTC would shift them.
    day_of_week TINYINT UNSIGNED NOT NULL,
    opens_at   TIME                    DEFAULT NULL,
    closes_at  TIME                    DEFAULT NULL,
    is_closed  TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hours_store_day (store_id, day_of_week),
    CONSTRAINT fk_hours_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE,
    CONSTRAINT chk_hours_day CHECK (day_of_week BETWEEN 0 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Local wall-clock hours, NOT UTC. See DATABASE_DESIGN.md section 4.';


-- =============================================================================
--  4. CATALOGUE
-- =============================================================================

CREATE TABLE categories (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id     BIGINT UNSIGNED         DEFAULT NULL,
    slug          VARCHAR(160)    NOT NULL,
    name          VARCHAR(120)    NOT NULL,
    icon          VARCHAR(40)     NOT NULL DEFAULT 'basket',
    depth         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Fallback for the reorder estimator when a seller gives no hint and the
    -- customer has no history. NULL means: schedule nothing (FR-CRM-09).
    default_consumption_days SMALLINT UNSIGNED DEFAULT NULL,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_parent (parent_id, sort_order),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE RESTRICT,
    CONSTRAINT chk_categories_depth CHECK (depth <= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Maximum depth 3 levels (depth 0,1,2) per FR-CAT-01.';

CREATE TABLE products (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id         BIGINT UNSIGNED NOT NULL,
    category_id       BIGINT UNSIGNED NOT NULL,
    slug              VARCHAR(190)    NOT NULL,
    name              VARCHAR(190)    NOT NULL,
    brand             VARCHAR(120)            DEFAULT NULL,
    sku               VARCHAR(80)     NOT NULL,
    description       TEXT            NOT NULL,
    price             DECIMAL(12,2)   NOT NULL,
    compare_at_price  DECIMAL(12,2)           DEFAULT NULL,
    unit              VARCHAR(40)     NOT NULL,
    pack_size         VARCHAR(60)     NOT NULL,
    weight_grams      INT UNSIGNED            DEFAULT NULL,
    allows_pickup     TINYINT(1)      NOT NULL DEFAULT 1,
    allows_delivery   TINYINT(1)      NOT NULL DEFAULT 0,
    -- Feeds the reorder engine (FR-CAT-11). NULL consumption days means the
    -- seller does not know, and we schedule nothing rather than guess.
    is_consumable     TINYINT(1)      NOT NULL DEFAULT 0,
    typical_consumption_days SMALLINT UNSIGNED DEFAULT NULL,
    status            ENUM('draft','published','archived','suspended') NOT NULL DEFAULT 'draft',
    moderation_reason VARCHAR(1000)           DEFAULT NULL,
    rating_avg        DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
    rating_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    UNIQUE KEY uq_products_seller_sku (seller_id, sku),
    KEY idx_products_category_status (category_id, status),
    KEY idx_products_seller_status (seller_id, status),
    KEY idx_products_price (price),
    KEY idx_products_rating (rating_avg),
    KEY idx_products_consumable (is_consumable, typical_consumption_days),
    FULLTEXT KEY ft_products_search (name, brand, description),
    CONSTRAINT fk_products_seller   FOREIGN KEY (seller_id)   REFERENCES sellers (id)    ON DELETE RESTRICT,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
    CONSTRAINT chk_products_price CHECK (price > 0),
    CONSTRAINT chk_products_compare CHECK (compare_at_price IS NULL OR compare_at_price >= price),
    CONSTRAINT chk_products_fulfilment CHECK (allows_pickup = 1 OR allows_delivery = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id    BIGINT UNSIGNED NOT NULL,
    -- Randomly generated filename under public/uploads/products/. The original
    -- filename is never used as a path (NFR-SEC-06).
    stored_path   VARCHAR(255)    NOT NULL,
    original_name VARCHAR(255)            DEFAULT NULL,
    mime_type     VARCHAR(80)     NOT NULL,
    byte_size     INT UNSIGNED    NOT NULL,
    width_px      SMALLINT UNSIGNED       DEFAULT NULL,
    height_px     SMALLINT UNSIGNED       DEFAULT NULL,
    alt_text      VARCHAR(255)            DEFAULT NULL,
    is_primary    TINYINT(1)      NOT NULL DEFAULT 0,
    sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_images_path (stored_path),
    KEY idx_images_product (product_id, sort_order),
    CONSTRAINT fk_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  5. INVENTORY
--
--  THE OVERSELL GUARD LIVES HERE.
--
--  Stock is held per product PER STORE (FR-INV-01). Two quantities are tracked
--  and a third is DERIVED - never stored twice, because two numbers that must
--  agree eventually will not:
--
--      qty_on_hand   physically in the store
--      qty_reserved  promised to orders already placed
--      qty_available generated: on_hand - reserved
--
--  Reservation runs inside one transaction:
--      SELECT ... FOR UPDATE   (ordered by product_id, so two concurrent
--                               checkouts queue rather than deadlock)
--      UPDATE inventory SET qty_reserved = qty_reserved + :n
--       WHERE id = :id AND (qty_on_hand - qty_reserved) >= :n
--      -- affected rows MUST be 1, else roll back
--
--  Three independent defences: the row lock, the guard repeated in the UPDATE's
--  WHERE clause, and the CHECK constraint below. Even if application code is
--  rewritten badly, the CHECK still refuses to let reserved exceed on-hand.
-- =============================================================================

CREATE TABLE inventory (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id          BIGINT UNSIGNED NOT NULL,
    store_id            BIGINT UNSIGNED NOT NULL,
    qty_on_hand         INT UNSIGNED    NOT NULL DEFAULT 0,
    qty_reserved        INT UNSIGNED    NOT NULL DEFAULT 0,
    qty_available       INT AS (CAST(qty_on_hand AS SIGNED) - CAST(qty_reserved AS SIGNED)) VIRTUAL,
    low_stock_threshold INT UNSIGNED    NOT NULL DEFAULT 0,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_product_store (product_id, store_id),
    KEY idx_inventory_store (store_id),
    KEY idx_inventory_available (qty_available),
    CONSTRAINT fk_inventory_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_store   FOREIGN KEY (store_id)   REFERENCES stores (id)   ON DELETE CASCADE,
    -- The last line of defence against overselling.
    CONSTRAINT chk_inventory_reserved CHECK (qty_reserved <= qty_on_hand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable. Every stock change writes a row; corrections are new rows, never
-- edits (FR-INV-03, NFR-DAT-07). This is what makes "where did 12 units go?"
-- an answerable question.
CREATE TABLE stock_movements (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id    BIGINT UNSIGNED NOT NULL,
    store_id      BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('receipt','adjustment','reservation','release','fulfilment','return','transfer_in','transfer_out','loss') NOT NULL,
    -- Signed: negative removes stock. Applies to on-hand or reserved depending
    -- on movement_type; documented in DATABASE_DESIGN.md section 6.
    qty_delta     INT             NOT NULL,
    qty_after     INT             NOT NULL,
    reason_code   VARCHAR(40)             DEFAULT NULL,
    note          VARCHAR(500)            DEFAULT NULL,
    reference_type VARCHAR(40)            DEFAULT NULL,
    reference_id  BIGINT UNSIGNED         DEFAULT NULL,
    actor_user_id BIGINT UNSIGNED         DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_moves_product_store_time (product_id, store_id, created_at),
    KEY idx_moves_reference (reference_type, reference_id),
    KEY idx_moves_actor (actor_user_id),
    CONSTRAINT fk_moves_product FOREIGN KEY (product_id)    REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_moves_store   FOREIGN KEY (store_id)      REFERENCES stores (id)   ON DELETE CASCADE,
    CONSTRAINT fk_moves_actor   FOREIGN KEY (actor_user_id) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  6. SHOPPING CART
-- =============================================================================

CREATE TABLE carts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED         DEFAULT NULL,
    -- Guests get a cookie-bound cart which merges into the user cart on login
    -- (FR-CART-01). Hashed so the cookie value is not usable from a dump.
    cookie_hash CHAR(64)                DEFAULT NULL,
    status      ENUM('active','merged','converted','abandoned') NOT NULL DEFAULT 'active',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carts_cookie (cookie_hash),
    KEY idx_carts_user_status (user_id, status),
    KEY idx_carts_updated (updated_at),
    CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_items (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id            BIGINT UNSIGNED NOT NULL,
    product_id         BIGINT UNSIGNED NOT NULL,
    store_id           BIGINT UNSIGNED         DEFAULT NULL,
    qty                INT UNSIGNED    NOT NULL DEFAULT 1,
    -- The price when the line was added. NOT authoritative: checkout re-reads
    -- products.price and surfaces any difference rather than silently applying
    -- it (FR-CART-10). Kept only so the change can be shown.
    price_when_added   DECIMAL(12,2)   NOT NULL,
    added_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_line (cart_id, product_id, store_id),
    KEY idx_cartitems_product (product_id),
    KEY idx_cartitems_store (store_id),
    CONSTRAINT fk_cartitems_cart    FOREIGN KEY (cart_id)    REFERENCES carts (id)    ON DELETE CASCADE,
    CONSTRAINT fk_cartitems_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_cartitems_store   FOREIGN KEY (store_id)   REFERENCES stores (id)   ON DELETE SET NULL,
    CONSTRAINT chk_cartitems_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  7. DELIVERY ZONES AND AGENTS
--  (defined before orders because seller_orders and addresses reference zones)
-- =============================================================================

CREATE TABLE delivery_zones (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(120)    NOT NULL,
    region           VARCHAR(80)     NOT NULL,
    base_fee         DECIMAL(12,2)   NOT NULL,
    heavy_surcharge  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    heavy_threshold_grams INT UNSIGNED NOT NULL DEFAULT 10000,
    free_threshold   DECIMAL(12,2)           DEFAULT NULL,
    max_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 3,
    assignment_mode  ENUM('admin','pool') NOT NULL DEFAULT 'admin',
    is_active        TINYINT(1)      NOT NULL DEFAULT 1,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zones_name (name),
    KEY idx_zones_active (is_active, region),
    CONSTRAINT chk_zones_fee CHECK (base_fee >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fee rules applied SERVER-SIDE. A fee posted by a browser is discarded.';

-- Which districts fall into which zone. An address with no match is
-- collection-only, and the UI says so before payment.
CREATE TABLE zone_districts (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    zone_id  BIGINT UNSIGNED NOT NULL,
    region   VARCHAR(80)     NOT NULL,
    district VARCHAR(80)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zone_district (region, district),
    KEY idx_zd_zone (zone_id),
    CONSTRAINT fk_zd_zone FOREIGN KEY (zone_id) REFERENCES delivery_zones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_agent_profiles (
    user_id        BIGINT UNSIGNED NOT NULL,
    vehicle_type   ENUM('foot','bicycle','motorcycle','car','van') NOT NULL DEFAULT 'motorcycle',
    max_weight_grams INT UNSIGNED  NOT NULL DEFAULT 25000,
    is_available   TINYINT(1)      NOT NULL DEFAULT 1,
    deliveries_completed INT UNSIGNED NOT NULL DEFAULT 0,
    deliveries_failed    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_agents_available (is_available),
    CONSTRAINT fk_agent_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agent_zones (
    user_id BIGINT UNSIGNED NOT NULL,
    zone_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, zone_id),
    KEY idx_az_zone (zone_id),
    CONSTRAINT fk_az_agent FOREIGN KEY (user_id) REFERENCES delivery_agent_profiles (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_az_zone  FOREIGN KEY (zone_id) REFERENCES delivery_zones (id)               ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customer_addresses
    ADD CONSTRAINT fk_addr_zone FOREIGN KEY (zone_id) REFERENCES delivery_zones (id) ON DELETE SET NULL;


-- =============================================================================
--  8. ORDERS
--
--  TWO LEVELS, AND THE SPLIT IS THE WHOLE POINT (A-03).
--
--    orders        the customer's order. One payment, one grand total.
--    seller_orders one per seller. Accepted, prepared and completed
--                  independently, with its own status and its own fulfilment.
--
--  A basket spanning two sellers becomes ONE orders row and TWO seller_orders
--  rows. If seller B rejects, seller A's collection is unaffected and only B's
--  portion is refunded. A single flat order table cannot express that without
--  lying about status.
-- =============================================================================

CREATE TABLE orders (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Human-readable and unguessable, e.g. SL-2026-9F3K2A (FR-ORD-09).
    order_number    VARCHAR(32)     NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    contact_email   VARCHAR(190)    NOT NULL,
    contact_phone   VARCHAR(32)     NOT NULL,
    contact_name    VARCHAR(160)    NOT NULL,
    payment_status  ENUM('pending','pending_cod','processing','paid','failed','expired','partially_refunded','refunded')
                                    NOT NULL DEFAULT 'pending',
    payment_method  ENUM('sandbox','cash','mpesa','airtel_money','mixx','halopesa') NOT NULL DEFAULT 'sandbox',
    currency        CHAR(3)         NOT NULL DEFAULT 'TZS',
    -- Every one of these is computed SERVER-SIDE from database prices at
    -- checkout. Nothing the browser sends about an amount is trusted
    -- (FR-CART-03).
    items_subtotal  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    delivery_total  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    discount_total  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    grand_total     DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    placed_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at         DATETIME                DEFAULT NULL,
    expires_at      DATETIME                DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_number (order_number),
    KEY idx_orders_user_time (user_id, placed_at),
    KEY idx_orders_payment_status (payment_status),
    KEY idx_orders_expiry (expires_at),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_orders_totals CHECK (
        items_subtotal >= 0 AND delivery_total >= 0 AND discount_total >= 0 AND grand_total >= 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Parent order: the customer, the payment, the grand total.';

CREATE TABLE seller_orders (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id          BIGINT UNSIGNED NOT NULL,
    -- Parent number plus a part index, e.g. SL-2026-9F3K2A-1.
    sub_number        VARCHAR(40)     NOT NULL,
    seller_id         BIGINT UNSIGNED NOT NULL,
    store_id          BIGINT UNSIGNED NOT NULL,
    fulfilment_method ENUM('pickup','delivery') NOT NULL,
    status            ENUM(
        'pending_payment','awaiting_seller','confirmed','preparing',
        'ready_for_pickup','collected','collection_overdue','returned_to_stock',
        'ready_for_dispatch','assigned','picked_up','out_for_delivery','delivered',
        'delivery_failed','returned_to_seller',
        'completed','cancelled_customer','rejected_seller','expired_unpaid',
        'refund_pending','refunded'
    ) NOT NULL DEFAULT 'pending_payment',
    status_reason     VARCHAR(1000)           DEFAULT NULL,
    subtotal          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    delivery_fee      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    total             DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    -- Recorded per sale. Payouts are not built in v1 (OQ-04).
    commission_amount DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    accepted_at       DATETIME                DEFAULT NULL,
    ready_at          DATETIME                DEFAULT NULL,
    completed_at      DATETIME                DEFAULT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sub_number (sub_number),
    KEY idx_sub_order (order_id),
    KEY idx_sub_seller_status (seller_id, status),
    KEY idx_sub_store_status (store_id, status),
    KEY idx_sub_status_time (status, created_at),
    CONSTRAINT fk_sub_order  FOREIGN KEY (order_id)  REFERENCES orders (id)  ON DELETE CASCADE,
    CONSTRAINT fk_sub_seller FOREIGN KEY (seller_id) REFERENCES sellers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sub_store  FOREIGN KEY (store_id)  REFERENCES stores (id)  ON DELETE RESTRICT,
    CONSTRAINT chk_sub_totals CHECK (subtotal >= 0 AND delivery_fee >= 0 AND total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One per seller. This is the row that has a fulfilment status.';

-- Line items snapshot the product as it was SOLD (FR-ORD-02). A later rename or
-- price change must not rewrite history on an existing receipt.
CREATE TABLE order_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_order_id BIGINT UNSIGNED NOT NULL,
    product_id      BIGINT UNSIGNED         DEFAULT NULL,
    name_snapshot   VARCHAR(190)    NOT NULL,
    sku_snapshot    VARCHAR(80)     NOT NULL,
    pack_size_snapshot VARCHAR(60)  NOT NULL,
    unit_price      DECIMAL(12,2)   NOT NULL,
    qty             INT UNSIGNED    NOT NULL,
    tax_amount      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(12,2)   NOT NULL,
    PRIMARY KEY (id),
    KEY idx_items_sub (seller_order_id),
    KEY idx_items_product (product_id),
    CONSTRAINT fk_items_sub     FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id) ON DELETE CASCADE,
    -- SET NULL, not CASCADE: deleting a product must never delete the evidence
    -- that it was sold.
    CONSTRAINT fk_items_product FOREIGN KEY (product_id)      REFERENCES products (id)      ON DELETE SET NULL,
    CONSTRAINT chk_items_qty CHECK (qty > 0),
    CONSTRAINT chk_items_price CHECK (unit_price >= 0 AND line_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only. Every transition writes a row with who did it and why
-- (FR-ORD-04). actor_type distinguishes a normal transition from an
-- administrator override, so the history cannot mislead a later reader.
CREATE TABLE order_status_history (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_order_id BIGINT UNSIGNED NOT NULL,
    from_status     VARCHAR(40)             DEFAULT NULL,
    to_status       VARCHAR(40)     NOT NULL,
    actor_user_id   BIGINT UNSIGNED         DEFAULT NULL,
    actor_type      ENUM('customer','seller','agent','support','admin','admin_override','system') NOT NULL,
    reason          VARCHAR(1000)           DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hist_sub_time (seller_order_id, created_at),
    KEY idx_hist_actor (actor_user_id),
    CONSTRAINT fk_hist_sub   FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_hist_actor FOREIGN KEY (actor_user_id)   REFERENCES users (id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Click and collect detail. The collection code is stored HASHED (FR-PICK-02):
-- staff verify a code the customer presents. Nobody - not the seller, not
-- support, not an administrator - can read it back. That is what makes the
-- collection record trustworthy rather than a claim.
CREATE TABLE order_pickups (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_order_id   BIGINT UNSIGNED NOT NULL,
    store_id          BIGINT UNSIGNED NOT NULL,
    code_hash         CHAR(64)                DEFAULT NULL,
    code_issued_at    DATETIME                DEFAULT NULL,
    window_from       DATETIME                DEFAULT NULL,
    window_to         DATETIME                DEFAULT NULL,
    instructions_snapshot VARCHAR(1000)       DEFAULT NULL,
    verify_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at   DATETIME                DEFAULT NULL,
    collected_at      DATETIME                DEFAULT NULL,
    collected_by_user_id BIGINT UNSIGNED      DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pickup_sub (seller_order_id),
    KEY idx_pickup_store_window (store_id, window_to),
    CONSTRAINT fk_pickup_sub   FOREIGN KEY (seller_order_id)      REFERENCES seller_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_pickup_store FOREIGN KEY (store_id)             REFERENCES stores (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_pickup_by    FOREIGN KEY (collected_by_user_id) REFERENCES users (id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  9. DELIVERY
-- =============================================================================

CREATE TABLE delivery_tasks (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_ref        VARCHAR(32)     NOT NULL,
    seller_order_id BIGINT UNSIGNED NOT NULL,
    zone_id         BIGINT UNSIGNED         DEFAULT NULL,
    agent_user_id   BIGINT UNSIGNED         DEFAULT NULL,
    status          ENUM('unassigned','offered','assigned','picked_up','out_for_delivery','delivered','failed','returned_to_seller','cancelled')
                                    NOT NULL DEFAULT 'unassigned',
    -- Address is SNAPSHOT, not a live FK: the delivery record must not change
    -- if the customer later edits or deletes the address.
    recipient_name  VARCHAR(160)    NOT NULL,
    recipient_phone VARCHAR(32)     NOT NULL,
    address_line    VARCHAR(400)    NOT NULL,
    landmark        VARCHAR(190)            DEFAULT NULL,
    instructions    VARCHAR(500)            DEFAULT NULL,
    fee             DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    cod_amount      DECIMAL(12,2)           DEFAULT NULL,
    -- Like the collection code: hashed, verified server-side. An agent cannot
    -- mark a delivery complete without the recipient's code (FR-DEL-06).
    code_hash       CHAR(64)                DEFAULT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    assigned_at     DATETIME                DEFAULT NULL,
    delivered_at    DATETIME                DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_task_ref (task_ref),
    UNIQUE KEY uq_task_sub (seller_order_id),
    -- The index that makes "only my tasks" cheap. In Phase 3 the agent id comes
    -- from the session actor, never from the request (FR-DEL-04).
    KEY idx_task_agent_status (agent_user_id, status),
    KEY idx_task_zone_status (zone_id, status),
    CONSTRAINT fk_task_sub   FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id)  ON DELETE CASCADE,
    CONSTRAINT fk_task_zone  FOREIGN KEY (zone_id)         REFERENCES delivery_zones (id) ON DELETE SET NULL,
    CONSTRAINT fk_task_agent FOREIGN KEY (agent_user_id)   REFERENCES users (id)          ON DELETE SET NULL,
    CONSTRAINT chk_task_attempts CHECK (attempts <= max_attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status changes AND failed attempts, append-only (FR-DEL-08). A failure always
-- carries a reason code: "failed" alone is the first thing every customer,
-- seller and support agent asks about.
CREATE TABLE delivery_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id       BIGINT UNSIGNED NOT NULL,
    event_type    ENUM('status_change','attempt_failed','assigned','declined','note') NOT NULL,
    from_status   VARCHAR(40)             DEFAULT NULL,
    to_status     VARCHAR(40)             DEFAULT NULL,
    reason_code   ENUM('recipient_absent','wrong_address','refused','unreachable_phone','access_denied','unsafe_conditions','damaged_in_transit','too_far','too_heavy','timing','busy','other')
                                          DEFAULT NULL,
    note          VARCHAR(1000)           DEFAULT NULL,
    contacted_recipient TINYINT(1)        DEFAULT NULL,
    actor_user_id BIGINT UNSIGNED         DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_devents_task_time (task_id, created_at),
    KEY idx_devents_reason (reason_code),
    KEY idx_devents_actor (actor_user_id),
    CONSTRAINT fk_devents_task  FOREIGN KEY (task_id)       REFERENCES delivery_tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_devents_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  10. PAYMENTS
--
--  NO PAYMENT CREDENTIAL IS STORED HERE OR ANYWHERE ELSE (FR-PAY-07). There is
--  no card column, no PIN column, no mobile-money token column, and none will
--  be added. What is stored is the provider's own reference.
--
--  IDEMPOTENCY IS A CONSTRAINT, NOT A CODE PATH: payment_transactions carries
--  UNIQUE (gateway, gateway_reference). A replayed webhook hits the constraint
--  and is ignored. Application logic can be refactored badly; a unique index
--  cannot (FR-PAY-05).
-- =============================================================================

CREATE TABLE payment_intents (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id       BIGINT UNSIGNED NOT NULL,
    gateway        VARCHAR(40)     NOT NULL,
    gateway_intent_ref VARCHAR(190)        DEFAULT NULL,
    amount         DECIMAL(12,2)   NOT NULL,
    currency       CHAR(3)         NOT NULL DEFAULT 'TZS',
    status         ENUM('created','processing','succeeded','failed','cancelled','expired') NOT NULL DEFAULT 'created',
    expires_at     DATETIME                DEFAULT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_intents_order (order_id),
    KEY idx_intents_gateway_ref (gateway, gateway_intent_ref),
    CONSTRAINT fk_intents_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT chk_intents_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_transactions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_ref   VARCHAR(32)     NOT NULL,
    order_id          BIGINT UNSIGNED NOT NULL,
    intent_id         BIGINT UNSIGNED         DEFAULT NULL,
    gateway           VARCHAR(40)     NOT NULL,
    -- The provider's own id for this event. The unique key below is what makes
    -- a replayed webhook safe.
    gateway_reference VARCHAR(190)    NOT NULL,
    direction         ENUM('charge','refund') NOT NULL DEFAULT 'charge',
    amount            DECIMAL(12,2)   NOT NULL,
    currency          CHAR(3)         NOT NULL DEFAULT 'TZS',
    status            ENUM('pending','paid','failed','refunded','flagged_for_review') NOT NULL,
    -- Raw provider payload, for reconciliation. Never contains a credential:
    -- providers return references, not instruments.
    raw_payload       TEXT                    DEFAULT NULL,
    signature_verified TINYINT(1)     NOT NULL DEFAULT 0,
    processed_at      DATETIME                DEFAULT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_txn_ref (transaction_ref),
    -- THE IDEMPOTENCY GUARANTEE.
    UNIQUE KEY uq_txn_gateway_reference (gateway, gateway_reference),
    KEY idx_txn_order (order_id),
    KEY idx_txn_status_time (status, created_at),
    CONSTRAINT fk_txn_order  FOREIGN KEY (order_id)  REFERENCES orders (id)           ON DELETE RESTRICT,
    CONSTRAINT fk_txn_intent FOREIGN KEY (intent_id) REFERENCES payment_intents (id)  ON DELETE SET NULL,
    CONSTRAINT chk_txn_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    refund_ref        VARCHAR(32)     NOT NULL,
    order_id          BIGINT UNSIGNED NOT NULL,
    seller_order_id   BIGINT UNSIGNED         DEFAULT NULL,
    amount            DECIMAL(12,2)   NOT NULL,
    reason            VARCHAR(500)    NOT NULL,
    status            ENUM('requested','approved','processing','refunded','failed','rejected') NOT NULL DEFAULT 'requested',
    -- Support can REQUEST a refund; only an admin can approve one. The two
    -- columns being separate is what keeps that split honest.
    requested_by      BIGINT UNSIGNED         DEFAULT NULL,
    approved_by       BIGINT UNSIGNED         DEFAULT NULL,
    transaction_id    BIGINT UNSIGNED         DEFAULT NULL,
    requested_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at       DATETIME                DEFAULT NULL,
    completed_at      DATETIME                DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refund_ref (refund_ref),
    KEY idx_refunds_order (order_id),
    KEY idx_refunds_sub (seller_order_id),
    KEY idx_refunds_status (status),
    KEY idx_refunds_requester (requested_by),
    KEY idx_refunds_approver (approved_by),
    KEY idx_refunds_txn (transaction_id),
    CONSTRAINT fk_refunds_order     FOREIGN KEY (order_id)        REFERENCES orders (id)               ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_sub       FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id)        ON DELETE SET NULL,
    CONSTRAINT fk_refunds_requester FOREIGN KEY (requested_by)    REFERENCES users (id)                ON DELETE SET NULL,
    CONSTRAINT fk_refunds_approver  FOREIGN KEY (approved_by)     REFERENCES users (id)                ON DELETE SET NULL,
    CONSTRAINT fk_refunds_txn       FOREIGN KEY (transaction_id)  REFERENCES payment_transactions (id) ON DELETE SET NULL,
    CONSTRAINT chk_refunds_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stops a double-submitted checkout form creating two orders (FR-CART-09).
-- The second request finds the key already present and returns the first
-- result instead of doing the work again.
CREATE TABLE idempotency_keys (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_hash      CHAR(64)        NOT NULL,
    user_id       BIGINT UNSIGNED         DEFAULT NULL,
    endpoint      VARCHAR(120)    NOT NULL,
    request_hash  CHAR(64)                DEFAULT NULL,
    response_type VARCHAR(40)             DEFAULT NULL,
    response_ref  VARCHAR(64)             DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME                DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idem_key (key_hash),
    KEY idx_idem_expiry (expires_at),
    KEY idx_idem_user (user_id),
    CONSTRAINT fk_idem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  11. NOTIFICATIONS, CONSENT AND THE REORDER ENGINE
-- =============================================================================

CREATE TABLE notification_preferences (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    category    ENUM('order_updates','pickup_delivery','support','reorder','offers') NOT NULL,
    channel     ENUM('email','sms','whatsapp') NOT NULL,
    is_enabled  TINYINT(1)      NOT NULL DEFAULT 1,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prefs_user_cat_chan (user_id, category, channel),
    CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only consent history (FR-CRM-06). Never updated in place: proving
-- what someone agreed to and when requires the whole trail, not the latest row.
CREATE TABLE consent_records (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    consent_type ENUM('marketing','terms','privacy') NOT NULL,
    granted      TINYINT(1)      NOT NULL,
    version      VARCHAR(20)     NOT NULL DEFAULT 'v1.0',
    source       VARCHAR(60)     NOT NULL,
    ip_address   VARBINARY(16)           DEFAULT NULL,
    actor_user_id BIGINT UNSIGNED        DEFAULT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_consent_user_type_time (user_id, consent_type, created_at),
    KEY idx_consent_actor (actor_user_id),
    CONSTRAINT fk_consent_user  FOREIGN KEY (user_id)       REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_consent_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The outbound queue AND the delivery log in one table. Queued by the web app
-- or a CLI task; sent by a separate CLI worker, never inline in a request
-- (FR-CRM-02, NFR-OPS-01).
--
-- skip_reason is the column that makes the retention engine explainable:
-- "why did this customer not get a reminder?" has an answer.
CREATE TABLE notifications (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    channel       ENUM('email','sms','whatsapp') NOT NULL DEFAULT 'email',
    category      ENUM('order_updates','pickup_delivery','support','reorder','offers') NOT NULL,
    is_marketing  TINYINT(1)      NOT NULL DEFAULT 0,
    template_key  VARCHAR(80)     NOT NULL,
    -- Rendering variables only. A ready-to-collect message carries the plain
    -- collection code at SEND time and is not persisted here.
    payload_json  TEXT                    DEFAULT NULL,
    status        ENUM('queued','sending','delivered','failed','skipped','cancelled') NOT NULL DEFAULT 'queued',
    skip_reason   ENUM('no_consent','consent_withdrawn','already_repurchased','cooldown','frequency_cap','unavailable','insufficient_data','skipped_no_provider','quiet_hours')
                                          DEFAULT NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    provider_response VARCHAR(500)        DEFAULT NULL,
    send_after    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at       DATETIME                DEFAULT NULL,
    read_at       DATETIME                DEFAULT NULL,
    reference_type VARCHAR(40)            DEFAULT NULL,
    reference_id  BIGINT UNSIGNED         DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- The worker's hot path: pick up what is due.
    KEY idx_notif_due (status, send_after),
    KEY idx_notif_user_time (user_id, created_at),
    KEY idx_notif_category (category, status),
    KEY idx_notif_reference (reference_type, reference_id),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONSUMPTION-AWARE REORDER REMINDERS (FR-CRM-08 to FR-CRM-10).
--
-- basis records HOW next_due_at was estimated, in priority order:
--   observed_interval  the customer's own median repeat gap (needs 2+ buys)
--   seller_hint        typical_consumption_days scaled by quantity bought
--   category_default   a category fallback, if an admin set one
--   none               nothing schedulable - we send nothing rather than guess
--
-- UNIQUE (user_id, product_id, cycle_key) makes a duplicate reminder physically
-- impossible. Running the scheduler twice inserts nothing the second time.
CREATE TABLE reorder_reminders (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            BIGINT UNSIGNED NOT NULL,
    product_id         BIGINT UNSIGNED NOT NULL,
    source_seller_order_id BIGINT UNSIGNED     DEFAULT NULL,
    -- Identifies one purchase cycle, e.g. 'so:1042'. Part of the unique key.
    cycle_key          VARCHAR(64)     NOT NULL,
    basis              ENUM('observed_interval','seller_hint','category_default','none') NOT NULL,
    basis_detail       VARCHAR(255)            DEFAULT NULL,
    last_purchased_at  DATETIME        NOT NULL,
    next_due_at        DATETIME                DEFAULT NULL,
    state              ENUM('scheduled','queued','sent','converted','snoozed','skipped','not_scheduled','closed') NOT NULL DEFAULT 'scheduled',
    skip_reason        ENUM('no_consent','consent_withdrawn','already_repurchased','cooldown','frequency_cap','unavailable','insufficient_data','quiet_hours')
                                               DEFAULT NULL,
    notification_id    BIGINT UNSIGNED         DEFAULT NULL,
    converted_order_id BIGINT UNSIGNED         DEFAULT NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- THE DUPLICATE GUARANTEE.
    UNIQUE KEY uq_reminder_cycle (user_id, product_id, cycle_key),
    KEY idx_reminder_due (state, next_due_at),
    KEY idx_reminder_product (product_id),
    KEY idx_reminder_source (source_seller_order_id),
    KEY idx_reminder_notification (notification_id),
    KEY idx_reminder_converted (converted_order_id),
    CONSTRAINT fk_reminder_user    FOREIGN KEY (user_id)                REFERENCES users (id)         ON DELETE CASCADE,
    CONSTRAINT fk_reminder_product FOREIGN KEY (product_id)             REFERENCES products (id)      ON DELETE CASCADE,
    CONSTRAINT fk_reminder_source  FOREIGN KEY (source_seller_order_id) REFERENCES seller_orders (id) ON DELETE SET NULL,
    CONSTRAINT fk_reminder_notif   FOREIGN KEY (notification_id)        REFERENCES notifications (id) ON DELETE SET NULL,
    CONSTRAINT fk_reminder_order   FOREIGN KEY (converted_order_id)     REFERENCES orders (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  12. REVIEWS
-- =============================================================================

-- A review requires a COMPLETED seller_order containing the product
-- (FR-REV-01). The FK to seller_order plus the unique key below is what makes
-- "verified purchase" a fact rather than a label.
CREATE TABLE reviews (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id       BIGINT UNSIGNED NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    seller_order_id  BIGINT UNSIGNED NOT NULL,
    rating           TINYINT UNSIGNED NOT NULL,
    title            VARCHAR(120)    NOT NULL,
    body             VARCHAR(1500)   NOT NULL,
    status           ENUM('pending','published','rejected','hidden') NOT NULL DEFAULT 'published',
    moderation_reason VARCHAR(500)           DEFAULT NULL,
    reported_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    seller_reply     VARCHAR(800)            DEFAULT NULL,
    seller_replied_at DATETIME               DEFAULT NULL,
    edit_locked_at   DATETIME                DEFAULT NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One review per customer per product per order (FR-REV-02).
    UNIQUE KEY uq_review_user_product_order (user_id, product_id, seller_order_id),
    KEY idx_reviews_product_status (product_id, status, created_at),
    KEY idx_reviews_user (user_id),
    KEY idx_reviews_sub (seller_order_id),
    CONSTRAINT fk_reviews_product FOREIGN KEY (product_id)      REFERENCES products (id)      ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user    FOREIGN KEY (user_id)         REFERENCES users (id)         ON DELETE CASCADE,
    CONSTRAINT fk_reviews_sub     FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id) ON DELETE CASCADE,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  13. SUPPORT
-- =============================================================================

CREATE TABLE support_tickets (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_ref      VARCHAR(32)     NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    order_id        BIGINT UNSIGNED         DEFAULT NULL,
    category        ENUM('order','collection','delivery','payment','account','seller','other') NOT NULL,
    subject         VARCHAR(190)    NOT NULL,
    priority        ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    status          ENUM('open','in_progress','waiting_customer','resolved','closed','escalated') NOT NULL DEFAULT 'open',
    assigned_to     BIGINT UNSIGNED         DEFAULT NULL,
    escalated_to    BIGINT UNSIGNED         DEFAULT NULL,
    escalation_reason VARCHAR(1000)         DEFAULT NULL,
    resolved_at     DATETIME                DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_ref (ticket_ref),
    KEY idx_tickets_user (user_id, status),
    KEY idx_tickets_status_time (status, created_at),
    KEY idx_tickets_assignee (assigned_to, status),
    KEY idx_tickets_order (order_id),
    KEY idx_tickets_escalatee (escalated_to),
    CONSTRAINT fk_tickets_user      FOREIGN KEY (user_id)      REFERENCES users (id)  ON DELETE CASCADE,
    CONSTRAINT fk_tickets_order     FOREIGN KEY (order_id)     REFERENCES orders (id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_assignee  FOREIGN KEY (assigned_to)  REFERENCES users (id)  ON DELETE SET NULL,
    CONSTRAINT fk_tickets_escalatee FOREIGN KEY (escalated_to) REFERENCES users (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- is_internal is the important column. The CUSTOMER-facing query filters these
-- rows out in SQL (FR-SUP-02): they are never fetched and then hidden in a
-- template, because a template-level hide is one refactor away from leaking.
CREATE TABLE support_messages (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id     BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED        DEFAULT NULL,
    author_role   ENUM('customer','support','admin','seller','agent','system') NOT NULL,
    body          VARCHAR(3000)   NOT NULL,
    is_internal   TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Covers the customer-facing query: ticket + not internal, in order.
    KEY idx_msgs_ticket_internal_time (ticket_id, is_internal, created_at),
    KEY idx_msgs_author (author_user_id),
    CONSTRAINT fk_msgs_ticket FOREIGN KEY (ticket_id)      REFERENCES support_tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_msgs_author FOREIGN KEY (author_user_id) REFERENCES users (id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  14. PLATFORM
-- =============================================================================

-- APPEND-ONLY. No application code path updates or deletes a row here, for
-- anyone, including administrators (FR-ADM-09). An audit log that privileged
-- users can rewrite tells you nothing.
--
-- entity_type/entity_id are deliberately NOT foreign keys: the log must survive
-- the deletion of whatever it refers to. That is the point of a log.
CREATE TABLE audit_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED         DEFAULT NULL,
    actor_role    VARCHAR(40)             DEFAULT NULL,
    action        VARCHAR(80)     NOT NULL,
    entity_type   VARCHAR(60)             DEFAULT NULL,
    entity_id     VARCHAR(64)             DEFAULT NULL,
    detail        VARCHAR(1000)           DEFAULT NULL,
    before_json   TEXT                    DEFAULT NULL,
    after_json    TEXT                    DEFAULT NULL,
    -- What justified this access, e.g. a ticket ref for a support lookup
    -- (FR-SUP-06).
    justification VARCHAR(120)            DEFAULT NULL,
    ip_address    VARBINARY(16)           DEFAULT NULL,
    user_agent    VARCHAR(255)            DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor_time (actor_user_id, created_at),
    KEY idx_audit_action_time (action, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Business rules an operator can change without a deploy. SECRETS ARE NOT HERE:
-- database credentials, the SMTP password and payment webhook secrets live in
-- .env, outside the web root and outside version control. Putting them in a
-- table would mean a compromised admin session could read them.
CREATE TABLE settings (
    setting_key   VARCHAR(80)     NOT NULL,
    setting_value VARCHAR(500)    NOT NULL,
    value_type    ENUM('string','int','decimal','bool','enum','json') NOT NULL DEFAULT 'string',
    setting_group VARCHAR(40)     NOT NULL DEFAULT 'General',
    description   VARCHAR(255)            DEFAULT NULL,
    updated_by    BIGINT UNSIGNED         DEFAULT NULL,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    KEY idx_settings_group (setting_group),
    KEY idx_settings_updater (updated_by),
    CONSTRAINT fk_settings_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  END OF SCHEMA - 44 tables
-- =============================================================================
