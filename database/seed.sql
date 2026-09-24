-- =============================================================================
--  SokoLink - SEED DATA
--
--  ##########################################################################
--  ##  DEVELOPMENT AND TESTING ONLY. NEVER LOAD THIS INTO PRODUCTION.      ##
--  ##                                                                      ##
--  ##  Every account below shares one publicly documented password.        ##
--  ##  Every person, business, address and phone number is invented.       ##
--  ##  The production checklist (Phase 6) verifies this file was not run.  ##
--  ##########################################################################
--
--  Shared development password for ALL seeded accounts:
--
--      SokoLink!Dev2026
--
--  Stored as a real bcrypt hash produced by password_hash(), so the Phase 3
--  login flow can be tested against it without special-casing anything.
--
--  Run AFTER database/schema.sql. Idempotent: it truncates the tables it owns
--  before inserting, so it can be re-run without duplicating rows.
--
--  The dataset deliberately contains the awkward cases, because a seed that
--  only shows the happy path hides the bugs:
--    - an order spanning TWO sellers, one collecting and one delivering
--    - an order rejected by a seller, with a reason, refunded
--    - a delivery that failed once and is awaiting a retry
--    - a customer who never consented to marketing
--    - a customer who consented and then withdrew
--    - a product with no consumption hint (nothing is scheduled for it)
--    - a seller application still pending, and one already rejected
--    - an out-of-stock product and a low-stock product
-- =============================================================================

SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `sokolink`;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE audit_log;
TRUNCATE TABLE settings;
TRUNCATE TABLE support_messages;
TRUNCATE TABLE support_tickets;
TRUNCATE TABLE reviews;
TRUNCATE TABLE reorder_reminders;
TRUNCATE TABLE notifications;
TRUNCATE TABLE consent_records;
TRUNCATE TABLE notification_preferences;
TRUNCATE TABLE idempotency_keys;
TRUNCATE TABLE refunds;
TRUNCATE TABLE payment_transactions;
TRUNCATE TABLE payment_intents;
TRUNCATE TABLE delivery_events;
TRUNCATE TABLE delivery_tasks;
TRUNCATE TABLE agent_zones;
TRUNCATE TABLE delivery_agent_profiles;
TRUNCATE TABLE zone_districts;
TRUNCATE TABLE delivery_zones;
TRUNCATE TABLE order_pickups;
TRUNCATE TABLE order_status_history;
TRUNCATE TABLE order_items;
TRUNCATE TABLE seller_orders;
TRUNCATE TABLE orders;
TRUNCATE TABLE cart_items;
TRUNCATE TABLE carts;
TRUNCATE TABLE stock_movements;
TRUNCATE TABLE inventory;
TRUNCATE TABLE product_images;
TRUNCATE TABLE products;
TRUNCATE TABLE categories;
TRUNCATE TABLE store_hours;
TRUNCATE TABLE stores;
TRUNCATE TABLE sellers;
TRUNCATE TABLE seller_applications;
TRUNCATE TABLE customer_addresses;
TRUNCATE TABLE customer_profiles;
TRUNCATE TABLE auth_attempts;
TRUNCATE TABLE user_tokens;
TRUNCATE TABLE user_roles;
TRUNCATE TABLE role_permissions;
TRUNCATE TABLE permissions;
TRUNCATE TABLE roles;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;


-- =============================================================================
--  ROLES AND PERMISSIONS
-- =============================================================================

INSERT INTO roles (id, role_key, name, description) VALUES
(1,'customer','Customer','Buys products, collects or receives delivery'),
(2,'seller','Seller','Lists products and fulfils orders'),
(3,'delivery_agent','Delivery Agent','Carries out assigned deliveries'),
(4,'support','Customer Support','Handles tickets and order problems'),
(5,'admin','Administrator','Operates the platform');

INSERT INTO permissions (id, permission_key, description, area) VALUES
(1,'order.view','View an order they are entitled to see','orders'),
(2,'order.place','Place an order','orders'),
(3,'order.cancel','Cancel an order in an early state','orders'),
(4,'order.transition.accept','Accept or reject an incoming order','orders'),
(5,'order.transition.prepare','Move an order into preparation','orders'),
(6,'order.transition.ready_pickup','Mark an order ready to collect','orders'),
(7,'order.transition.ready_dispatch','Mark an order ready to dispatch','orders'),
(8,'order.monitor.all','See every order across the marketplace','orders'),
(9,'pickup.code.verify','Verify a collection code at the counter','pickup'),
(10,'delivery.task.view','View delivery tasks assigned to them','delivery'),
(11,'delivery.task.assign','Assign a delivery task to an agent','delivery'),
(12,'delivery.status.update','Update the status of a delivery','delivery'),
(13,'delivery.confirm','Confirm a delivery with the recipient code','delivery'),
(14,'delivery.failure.report','Report a failed delivery attempt','delivery'),
(15,'delivery.zone.manage','Create and edit delivery zones','delivery'),
(16,'product.create','Create a product listing','catalogue'),
(17,'product.edit','Edit their own product listing','catalogue'),
(18,'product.moderate','Unpublish or flag any listing','catalogue'),
(19,'category.manage','Manage the category tree','catalogue'),
(20,'inventory.view','View stock levels','inventory'),
(21,'inventory.adjust','Adjust stock with a reason','inventory'),
(22,'store.edit','Edit their own store','stores'),
(23,'seller.approve','Approve or reject a seller application','sellers'),
(24,'user.list','List platform users','users'),
(25,'user.suspend','Suspend or reactivate an account','users'),
(26,'user.role.assign','Grant or revoke roles','users'),
(27,'payment.transaction.view','View payment transactions','payments'),
(28,'payment.refund.request','Request a refund','payments'),
(29,'payment.refund.approve','Approve a refund','payments'),
(30,'ticket.view','View support tickets','support'),
(31,'ticket.reply','Reply to a support ticket','support'),
(32,'ticket.internal_note','Write an internal note','support'),
(33,'ticket.escalate','Escalate a ticket to an administrator','support'),
(34,'dispute.resolve','Resolve an escalated dispute','support'),
(35,'notification.preferences.manage','Manage notification preferences','notifications'),
(36,'notification.delivery_log.view','View notification delivery status','notifications'),
(37,'notification.settings.platform','Configure platform notification rules','notifications'),
(38,'reminder.settings.product','Set reorder consumption guides','notifications'),
(39,'reorder.create','Reorder a previous purchase','orders'),
(40,'review.create','Write a review for a completed purchase','reviews'),
(41,'review.reply','Reply to a review of their product','reviews'),
(42,'review.moderate','Moderate reviews','reviews'),
(43,'report.sales.view','View their own sales figures','reports'),
(44,'report.platform.view','View platform-wide reports','reports'),
(45,'audit.view','Read the audit log','platform'),
(46,'settings.edit','Change platform settings','platform');

-- Customer
INSERT INTO role_permissions (role_id, permission_id) VALUES
(1,1),(1,2),(1,3),(1,27),(1,28),(1,30),(1,31),(1,35),(1,39),(1,40);
-- Seller
INSERT INTO role_permissions (role_id, permission_id) VALUES
(2,1),(2,4),(2,5),(2,6),(2,7),(2,9),(2,16),(2,17),(2,20),(2,21),(2,22),
(2,28),(2,30),(2,31),(2,35),(2,38),(2,41),(2,43);
-- Delivery agent
INSERT INTO role_permissions (role_id, permission_id) VALUES
(3,10),(3,12),(3,13),(3,14),(3,30),(3,31),(3,35);
-- Support
INSERT INTO role_permissions (role_id, permission_id) VALUES
(4,1),(4,3),(4,24),(4,27),(4,28),(4,30),(4,31),(4,32),(4,33),(4,35),(4,36);
-- Administrator (everything except approving their own refunds is not modelled here;
-- admin holds the full set)
INSERT INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions;


-- =============================================================================
--  USERS - the nine test accounts (USER_ROLES_AND_PERMISSIONS.md section 7)
--  All share the password: SokoLink!Dev2026
-- =============================================================================

INSERT INTO users (id, email, password_hash, first_name, last_name, phone, status, email_verified_at, created_at) VALUES
(1,'admin@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Platform','Admin','+255700000001','active','2026-01-04 08:00:00','2026-01-04 08:00:00'),
(2,'seller.mama.lishe@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Rehema','Mushi','+255712345412','active','2026-02-11 09:20:00','2026-02-11 09:20:00'),
(3,'seller.duka.kuu@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Hassan','Mrisho','+255689000203','active','2026-03-02 14:00:00','2026-03-02 14:00:00'),
(4,'seller.bustani@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Anna','Mapunda','+255715550665','active','2026-05-19 07:45:00','2026-05-19 07:45:00'),
(5,'seller.pending@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Hamisi','Sokoni','+255713330336','pending_approval',NULL,'2026-09-21 18:30:00'),
(6,'agent.juma@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Juma','Kileo','+255714441001','active','2026-04-08 06:00:00','2026-04-08 06:00:00'),
(7,'agent.neema@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Neema','Bakari','+255714441002','active','2026-06-15 06:00:00','2026-06-15 06:00:00'),
(8,'support@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Neema','Support','+255700000008','active','2026-02-20 08:00:00','2026-02-20 08:00:00'),
(9,'customer.asha@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Asha','Mwinyi','+255712345118','active','2026-03-14 19:12:00','2026-03-14 19:10:00'),
(10,'customer.baraka@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Baraka','Joseph','+255689000903','active','2026-04-02 12:05:00','2026-04-02 12:00:00'),
(11,'customer.neema@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Neema','Kessy','+255712340220','active','2026-05-30 10:05:00','2026-05-30 10:00:00'),
(12,'customer.grace@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Grace','Temu','+255689000771','active','2026-06-01 09:00:00','2026-06-01 09:00:00'),
(13,'customer.joseph@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Joseph','Shirima','+255712000507','active','2026-06-10 11:00:00','2026-06-10 11:00:00'),
(14,'customer.rashid@sokolink.test','$2y$10$3sA9mylxerG2K7MW0VGTr.TeAnBYk8gD4koflbfEq/SkMz2IqLWna','Rashid','Omary','+255712000888','suspended','2026-06-21 08:05:00','2026-06-21 08:00:00');

UPDATE users SET status_reason = 'Repeated fraudulent chargeback claims' WHERE id = 14;

INSERT INTO user_roles (user_id, role_id, granted_at, granted_by) VALUES
(1,5,'2026-01-04 08:00:00',NULL),
(2,2,'2026-02-11 09:30:00',1),
(3,2,'2026-03-02 14:10:00',1),
(4,2,'2026-05-19 08:00:00',1),
(5,2,'2026-09-21 18:30:00',NULL),
(6,3,'2026-04-08 06:10:00',1),
(7,3,'2026-06-15 06:10:00',1),
(8,4,'2026-02-20 08:10:00',1),
(9,1,'2026-03-14 19:10:00',NULL),
(10,1,'2026-04-02 12:00:00',NULL),
(11,1,'2026-05-30 10:00:00',NULL),
(12,1,'2026-06-01 09:00:00',NULL),
(13,1,'2026-06-10 11:00:00',NULL),
(14,1,'2026-06-21 08:00:00',NULL);

INSERT INTO customer_profiles (user_id, orders_count, lifetime_spend, created_at) VALUES
(9,14,486200.00,'2026-03-14 19:10:00'),
(10,6,152000.00,'2026-04-02 12:00:00'),
(11,9,214800.00,'2026-05-30 10:00:00'),
(12,4,188000.00,'2026-06-01 09:00:00'),
(13,3,96400.00,'2026-06-10 11:00:00'),
(14,2,31200.00,'2026-06-21 08:00:00');


-- =============================================================================
--  DELIVERY ZONES (before addresses, which reference them)
-- =============================================================================

INSERT INTO delivery_zones (id, name, region, base_fee, heavy_surcharge, heavy_threshold_grams, free_threshold, max_attempts, assignment_mode, is_active) VALUES
(1,'DSM Central','Dar es Salaam',6000.00,2000.00,10000,150000.00,3,'admin',1),
(2,'DSM North','Dar es Salaam',6000.00,2000.00,10000,150000.00,3,'admin',1),
(3,'Arusha Central','Arusha',8000.00,3000.00,10000,200000.00,3,'admin',1),
(4,'Mwanza City','Mwanza',7500.00,2500.00,10000,200000.00,3,'admin',0);

INSERT INTO zone_districts (zone_id, region, district) VALUES
(1,'Dar es Salaam','Ilala'),
(1,'Dar es Salaam','Temeke'),
(2,'Dar es Salaam','Kinondoni'),
(2,'Dar es Salaam','Ubungo'),
(3,'Arusha','Arusha City'),
(4,'Mwanza','Nyamagana');

INSERT INTO delivery_agent_profiles (user_id, vehicle_type, max_weight_grams, is_available, deliveries_completed, deliveries_failed) VALUES
(6,'motorcycle',25000,1,148,4),
(7,'motorcycle',25000,1,92,7);

INSERT INTO agent_zones (user_id, zone_id) VALUES (6,1),(6,2),(7,3);


-- =============================================================================
--  CUSTOMER ADDRESSES
--  Address 3 has NO zone: a deliberate case so the collection-only path is
--  exercised rather than theoretical.
-- =============================================================================

INSERT INTO customer_addresses (id, user_id, label, recipient, phone, region, district, ward, street, landmark, instructions, zone_id, is_default) VALUES
(1,9,'Home','Asha Mwinyi','+255712345118','Dar es Salaam','Kinondoni','Msasani','Chole Road 22','Blue gate opposite the pharmacy','Call on arrival, the gate bell does not work.',2,1),
(2,9,'Work','Asha Mwinyi','+255712345118','Dar es Salaam','Ilala','Upanga','Ufukoni Street 4','Third floor, Amani House','Leave with reception if I am not in.',1,0),
(3,9,'Mother','Mariam Mwinyi','+255689000904','Morogoro','Morogoro Urban','Kihonda','Mazimbu Road 11',NULL,NULL,NULL,0),
(4,11,'Home','Neema Kessy','+255712340220','Dar es Salaam','Kinondoni','Kawe','Kawe Beach Road 8','Green roof, second house after the mosque','Cash on delivery - exact change appreciated.',2,1),
(5,12,'Home','Grace Temu','+255689000771','Arusha','Arusha City','Sakina','Sakina Street 3','Behind the secondary school','Heavy item - please ring before arriving.',3,1),
(6,13,'Home','Joseph Shirima','+255712000507','Arusha','Arusha City','Njiro','Njiro Road 14',NULL,NULL,3,1);

UPDATE customer_profiles SET default_address_id = 1 WHERE user_id = 9;
UPDATE customer_profiles SET default_address_id = 4 WHERE user_id = 11;


-- =============================================================================
--  SELLERS, APPLICATIONS AND STORES
-- =============================================================================

INSERT INTO seller_applications (id, user_id, business_name, business_type, registration_number, contact_name, contact_phone, region, district, store_name, street, offers_pickup, offers_delivery, categories_text, status, decision_reason, decided_by, decided_at, submitted_at) VALUES
(28,13,'Pwani Supplies','company','BRELA-551200','Salim Pwani','+255712000448','Tanga','Tanga City','Pwani Supplies','Bandari Road 2',1,1,'Imported household goods.','rejected','Registration number could not be verified with BRELA. Reapply with a copy of the certificate.',1,'2026-09-09 10:15:00','2026-09-08 11:00:00'),
(30,12,'Mlimani Grocers','partnership',NULL,'Zainab Mlimani','+255689000001','Morogoro','Morogoro Urban','Mlimani Grocers','Station Road 4',1,0,'Fresh vegetables and fruit, small quantities of dry goods.','pending_approval',NULL,NULL,NULL,'2026-09-19 09:05:00'),
(31,5,'Sokoni Traders','sole_trader','BRELA-772314','Hamisi Sokoni','+255713330336','Dodoma','Dodoma Urban','Sokoni Traders - Majengo','Kuu Street 19',1,1,'Dry goods, cooking oil, rice, sugar and household cleaning products.','pending_approval',NULL,NULL,NULL,'2026-09-21 18:30:00');

INSERT INTO sellers (id, user_id, slug, business_name, registration_number, contact_email, contact_phone, status, commission_percent, prep_hours, auto_accept, low_stock_threshold, rating_avg, rating_count, approved_at, created_at) VALUES
(1,2,'mama-lishe','Mama Lishe Provisions','BRELA-448201','seller.mama.lishe@sokolink.test','+255712345412','active',5.00,4,0,10,4.60,440,'2026-02-11 09:30:00','2026-02-11 09:20:00'),
(2,3,'duka-kuu','Duka Kuu Wholesalers','BRELA-330198','seller.duka.kuu@sokolink.test','+255689000203','active',5.00,9,0,20,4.80,204,'2026-03-02 14:10:00','2026-03-02 14:00:00'),
(3,4,'bustani-fresh','Bustani Fresh',NULL,'seller.bustani@sokolink.test','+255715550665','active',5.00,2,0,5,4.30,96,'2026-05-19 08:00:00','2026-05-19 07:45:00');

INSERT INTO stores (id, seller_id, slug, name, region, district, street, landmark, phone, pickup_instructions, collection_window_hours, offers_pickup, offers_delivery, status, rating_avg, rating_count) VALUES
(1,1,'mama-lishe-kariakoo','Mama Lishe - Kariakoo','Dar es Salaam','Ilala','Msimbazi Street 114','Opposite Kariakoo Market north gate','+255712345412','Collection counter is inside the main entrance on the left. Please have your collection code ready on your phone.',72,1,1,'published',4.60,312),
(2,1,'mama-lishe-mbezi','Mama Lishe - Mbezi Beach','Dar es Salaam','Kinondoni','Africana Road 7','Next to the Shell station','+255712345877','Ring the bell at the side gate marked COLLECTIONS. Parking available for 10 minutes.',72,1,1,'published',4.40,128),
(3,2,'duka-kuu-arusha','Duka Kuu - Arusha Central','Arusha','Arusha City','Sokoine Road 42','Ground floor, Uhuru Building','+255689000203','Collections are handled at the rear loading bay between 09:00 and 18:00.',72,1,1,'published',4.80,204),
(4,3,'bustani-mwanza','Bustani Fresh - Mwanza','Mwanza','Nyamagana','Kenyatta Road 9','Beside the fish market entrance','+255715550665','Fresh orders are held in the chiller. Please collect within 4 hours of the ready notification.',4,1,0,'published',4.30,96);

-- Local wall-clock hours. 0 = Monday .. 6 = Sunday.
INSERT INTO store_hours (store_id, day_of_week, opens_at, closes_at, is_closed) VALUES
(1,0,'07:30:00','20:00:00',0),(1,1,'07:30:00','20:00:00',0),(1,2,'07:30:00','20:00:00',0),
(1,3,'07:30:00','20:00:00',0),(1,4,'07:30:00','20:00:00',0),(1,5,'08:00:00','21:00:00',0),(1,6,'09:00:00','17:00:00',0),
(2,0,'07:30:00','20:00:00',0),(2,1,'07:30:00','20:00:00',0),(2,2,'07:30:00','20:00:00',0),
(2,3,'07:30:00','20:00:00',0),(2,4,'07:30:00','20:00:00',0),(2,5,'08:00:00','21:00:00',0),(2,6,NULL,NULL,1),
(3,0,'07:30:00','20:00:00',0),(3,1,'07:30:00','20:00:00',0),(3,2,'07:30:00','20:00:00',0),
(3,3,'07:30:00','20:00:00',0),(3,4,'07:30:00','20:00:00',0),(3,5,'08:00:00','21:00:00',0),(3,6,'09:00:00','17:00:00',0),
(4,0,'06:00:00','18:00:00',0),(4,1,'07:30:00','20:00:00',0),(4,2,'07:30:00','20:00:00',0),
(4,3,'07:30:00','20:00:00',0),(4,4,'07:30:00','20:00:00',0),(4,5,'06:00:00','18:00:00',0),(4,6,'09:00:00','17:00:00',0);


-- =============================================================================
--  CATEGORIES
-- =============================================================================

INSERT INTO categories (id, parent_id, slug, name, icon, depth, sort_order, default_consumption_days, is_active) VALUES
(1,NULL,'food-cupboard','Food Cupboard','basket',0,1,NULL,1),
(2,NULL,'household','Household & Cleaning','home',0,2,NULL,1),
(3,NULL,'personal-care','Personal Care','heart',0,3,NULL,1),
(4,NULL,'fresh','Fresh Produce','leaf',0,4,NULL,1),
(5,NULL,'baby','Baby & Child','baby',0,5,NULL,1),
(6,NULL,'beverages','Drinks','cup',0,6,NULL,1),
(11,1,'cooking-oil','Cooking Oil & Fats','bottle',1,1,45,1),
(12,1,'rice-grains','Rice & Grains','grain',1,2,60,1),
(13,1,'flour','Flour & Baking','grain',1,3,30,1),
(14,1,'sugar-salt','Sugar, Salt & Spices','spice',1,4,40,1),
(15,1,'tea-coffee','Tea & Coffee','cup',1,5,40,1),
(21,2,'laundry','Laundry','shirt',1,1,50,1),
(22,2,'cleaning','Surface Cleaning','spray',1,2,55,1),
(23,2,'paper','Paper & Disposables','roll',1,3,NULL,1),
(31,3,'soap-bath','Soap & Bath','drop',1,1,42,1),
(32,3,'hair-care','Hair Care','drop',1,2,70,1),
(33,3,'oral-care','Oral Care','smile',1,3,45,1),
(41,4,'vegetables','Vegetables','leaf',1,1,7,1),
(42,4,'fruit','Fruit','leaf',1,2,7,1),
(43,4,'dairy-eggs','Dairy & Eggs','egg',1,3,5,1),
(51,5,'nappies','Nappies & Wipes','baby',1,1,18,1),
(52,5,'baby-food','Baby Food','bowl',1,2,21,1),
(61,6,'water','Water','drop',1,1,12,1),
(62,6,'soft-drinks','Soft Drinks','cup',1,2,14,1),
(63,6,'juice','Juice','cup',1,3,14,1);


-- =============================================================================
--  PRODUCTS
--  Note product 108 (Karatasi Kitchen Roll): is_consumable = 1 but
--  typical_consumption_days = NULL, AND its category (23, Paper & Disposables)
--  has no default_consumption_days either. Both are deliberate: with no seller
--  hint, no category default and no repeat history, the estimator has nothing
--  to work from and must schedule NOTHING rather than guess (FR-CRM-09).
--
--  Leaving the category default set would have made this product fall back to
--  category_default, and the "schedule nothing" branch would never be reached
--  by any seeded data.
-- =============================================================================

INSERT INTO products (id, seller_id, category_id, slug, name, brand, sku, description, price, compare_at_price, unit, pack_size, weight_grams, allows_pickup, allows_delivery, is_consumable, typical_consumption_days, status, rating_avg, rating_count) VALUES
(101,1,11,'alizeti-sunflower-oil-5l','Alizeti Pure Sunflower Cooking Oil','Alizeti','ALZ-OIL-5L','Cold-pressed sunflower oil in a 5 litre jerrycan. Light flavour, high smoke point, suited to everyday frying and deep frying. Sold by weight-checked jerrycan with a tamper-evident seal.',28500.00,31000.00,'jerrycan','5 L',5200,1,1,1,45,'published',4.70,214),
(102,2,12,'mbeya-white-rice-25kg','Mbeya Premium White Rice','Mbeya Harvest','MBH-RCE-25','Long-grain white rice grown in the Mbeya highlands, double-sifted and stone-free. 25 kg sack, suitable for households buying in bulk or for small catering operations.',92000.00,NULL,'sack','25 kg',25000,1,1,1,60,'published',4.80,96),
(103,1,13,'sembe-maize-flour-10kg','Sembe Fine Maize Flour','Nyumbani','NYB-SMB-10','Finely milled white maize flour for ugali and porridge. Milled weekly and date-stamped on the bag.',24000.00,NULL,'bag','10 kg',10000,1,1,1,30,'published',4.50,143),
(104,2,14,'kilombero-brown-sugar-2kg','Kilombero Brown Sugar','Kilombero','KLB-SGR-2','Unrefined brown cane sugar with a light molasses note. 2 kg resealable pack.',7800.00,8500.00,'pack','2 kg',2000,1,1,1,35,'published',4.40,67),
(105,1,15,'chai-bora-loose-leaf-500g','Chai Bora Loose Leaf Tea','Chai Bora','CHB-TEA-500','Strong black loose-leaf tea from the Usambara estates. Brews dark and takes milk well.',6200.00,NULL,'pack','500 g',500,1,1,1,40,'published',4.90,188),
(106,2,21,'jamaa-laundry-soap-bar-6pk','Jamaa Laundry Soap Bars','Jamaa','JMA-LSB-6','Multipurpose blue laundry bars for hand washing. Pack of six 800 g bars.',11400.00,NULL,'pack','6 x 800 g',4800,1,1,1,50,'published',4.20,54),
(107,1,22,'safi-multi-surface-cleaner-2l','Safi Multi-Surface Cleaner','Safi','SAF-MSC-2L','Concentrated citrus cleaner for floors, tiles and worktops. Dilute one capful per five litres of water.',9800.00,NULL,'bottle','2 L',2100,1,1,1,55,'published',4.10,39),
(108,2,23,'karatasi-kitchen-roll-4pk','Karatasi Kitchen Roll','Karatasi','KRT-KRL-4','Two-ply absorbent kitchen roll, four rolls per pack.',8600.00,NULL,'pack','4 rolls',900,1,1,1,NULL,'published',3.90,22),
(109,1,31,'mwangaza-bath-soap-4pk','Mwangaza Moisturising Bath Soap','Mwangaza','MWG-BSP-4','Glycerine-enriched bath soap with a light coconut scent. Pack of four 175 g bars.',9200.00,10400.00,'pack','4 x 175 g',700,1,1,1,42,'published',4.60,121),
(110,1,32,'nywele-shea-hair-food-250ml','Nywele Shea Hair Food','Nywele','NYW-SHF-250','Shea butter and coconut hair dressing for dry and coiled hair. 250 ml jar.',13500.00,NULL,'jar','250 ml',300,1,1,1,70,'published',4.70,78),
(111,2,33,'meno-safi-toothpaste-150ml','Meno Safi Fluoride Toothpaste','Meno Safi','MNS-TPS-150','Everyday fluoride toothpaste with a mild mint flavour. 150 ml tube.',4600.00,NULL,'tube','150 ml',180,1,1,1,45,'published',4.30,64),
(112,3,41,'bustani-tomatoes-1kg','Vine Tomatoes','Bustani Fresh','BST-TOM-1','Firm vine tomatoes picked the same morning. Sold by the kilogram, chiller-held until collection.',3200.00,NULL,'kg','1 kg',1000,1,0,1,7,'published',4.50,31),
(113,3,42,'bustani-bananas-bunch','Sweet Bananas','Bustani Fresh','BST-BAN-B','Small sweet bananas sold by the bunch, roughly 1.2 kg.',2800.00,NULL,'bunch','approx 1.2 kg',1200,1,0,1,6,'published',4.20,18),
(114,3,43,'ziwa-fresh-milk-1l','Ziwa Fresh Full-Cream Milk','Ziwa','ZWA-MLK-1','Pasteurised full-cream milk, 1 litre carton. Chiller-held; collect within four hours.',2400.00,NULL,'carton','1 L',1030,1,0,1,4,'published',4.60,44),
(115,2,51,'mtoto-nappies-size4-50pk','Mtoto Dry Nappies Size 4','Mtoto','MTO-NAP-4','Size 4 (7-18 kg) disposable nappies with a wetness indicator. Jumbo pack of 50.',34000.00,37500.00,'pack','50 nappies',2600,1,1,1,18,'published',4.70,152),
(116,2,52,'mtoto-cereal-400g','Mtoto Multigrain Baby Cereal','Mtoto','MTO-CRL-400','Iron-fortified multigrain cereal for infants from six months. 400 g tin.',12800.00,NULL,'tin','400 g',400,1,1,1,21,'published',4.40,58),
(117,1,61,'chemchem-water-12x1-5l','Chemchem Drinking Water','Chemchem','CHM-WTR-12','Purified drinking water, twelve 1.5 litre bottles per shrink-wrapped pack.',13200.00,NULL,'pack','12 x 1.5 L',18000,1,1,1,12,'published',4.50,83),
(118,2,63,'embe-mango-juice-1l','Embe Mango Juice','Embe','EMB-JCE-1','Mango juice from concentrate with no added sugar. 1 litre carton.',4200.00,NULL,'carton','1 L',1050,1,1,1,14,'published',4.00,27);


-- =============================================================================
--  INVENTORY
--  Product 106 is OUT of stock; 103, 110 and 113 are LOW. Both cases must be
--  visible in a seed, because both have their own UI path.
-- =============================================================================

INSERT INTO inventory (product_id, store_id, qty_on_hand, qty_reserved, low_stock_threshold) VALUES
(101,1,40,2,10),(101,2,26,0,10),
(102,3,18,0,5),
(103,1,5,0,10),(103,2,2,0,10),
(104,3,140,0,20),
(105,1,54,2,10),
(106,3,0,0,20),
(107,1,20,1,10),(107,2,11,0,10),
(108,3,88,0,20),
(109,1,30,0,10),(109,2,16,0,10),
(110,1,12,0,10),
(111,3,210,0,20),
(112,4,40,0,5),
(113,4,3,0,5),
(114,4,60,0,5),
(115,3,26,1,20),
(116,3,34,0,20),
(117,1,60,2,10),(117,2,35,0,10),
(118,3,120,0,20);

INSERT INTO stock_movements (product_id, store_id, movement_type, qty_delta, qty_after, reason_code, note, actor_user_id, created_at) VALUES
(101,1,'receipt',48,48,'delivery_in','Weekly delivery from the mill',2,'2026-09-15 06:00:00'),
(101,1,'fulfilment',-6,42,NULL,'Collected orders',2,'2026-09-19 12:00:00'),
(103,1,'adjustment',-3,5,'count','Stock count correction after a recount',2,'2026-09-20 08:00:00'),
(106,3,'adjustment',-1,0,'count','Stock count was wrong - only 1 pack physically present, then sold',3,'2026-09-17 13:40:00'),
(113,4,'receipt',12,12,'delivery_in','Morning market delivery',4,'2026-09-21 05:30:00'),
(113,4,'fulfilment',-9,3,NULL,'Collected orders',4,'2026-09-21 16:00:00');


-- =============================================================================
--  ORDERS
--  SL-2026-9F3K2A deliberately spans TWO sellers: one collecting, one
--  delivering. That is the case a flat order table cannot represent.
-- =============================================================================

INSERT INTO orders (id, order_number, user_id, contact_email, contact_phone, contact_name, payment_status, payment_method, currency, items_subtotal, delivery_total, discount_total, grand_total, placed_at, paid_at, expires_at) VALUES
(1,'SL-2026-9F3K2A',9,'customer.asha@sokolink.test','+255712345118','Asha Mwinyi','paid','sandbox','TZS',74900.00,6000.00,0.00,80900.00,'2026-09-20 06:14:00','2026-09-20 06:15:00',NULL),
(2,'SL-2026-7B1X9C',10,'customer.baraka@sokolink.test','+255689000903','Baraka Joseph','paid','sandbox','TZS',48000.00,0.00,0.00,48000.00,'2026-09-22 04:02:00','2026-09-22 04:03:00',NULL),
(3,'SL-2026-2D8M4T',11,'customer.neema@sokolink.test','+255712340220','Neema Kessy','pending_cod','cash','TZS',36200.00,6000.00,0.00,42200.00,'2026-09-21 16:40:00',NULL,NULL),
(4,'SL-2026-5H2P7Q',9,'customer.asha@sokolink.test','+255712345118','Asha Mwinyi','paid','sandbox','TZS',36200.00,0.00,0.00,36200.00,'2026-08-06 07:20:00','2026-08-06 07:21:00',NULL),
(5,'SL-2026-3K9W1E',13,'customer.joseph@sokolink.test','+255712000507','Joseph Shirima','refunded','sandbox','TZS',34200.00,6000.00,0.00,40200.00,'2026-09-17 10:11:00','2026-09-17 10:12:00',NULL),
(6,'SL-2026-8T4R6Y',12,'customer.grace@sokolink.test','+255689000771','Grace Temu','paid','sandbox','TZS',92000.00,8000.00,0.00,100000.00,'2026-09-19 08:30:00','2026-09-19 08:31:00',NULL),
(7,'SL-2026-1A5B3C',9,'customer.asha@sokolink.test','+255712345118','Asha Mwinyi','paid','sandbox','TZS',32600.00,6000.00,0.00,38600.00,'2026-07-14 12:00:00','2026-07-14 12:01:00',NULL);

INSERT INTO seller_orders (id, order_id, sub_number, seller_id, store_id, fulfilment_method, status, status_reason, subtotal, delivery_fee, total, commission_amount, accepted_at, ready_at, completed_at, created_at, updated_at) VALUES
(1,1,'SL-2026-9F3K2A-1',1,1,'pickup','ready_for_pickup',NULL,40900.00,0.00,40900.00,2045.00,'2026-09-20 07:02:00','2026-09-21 07:41:00',NULL,'2026-09-20 06:14:00','2026-09-21 07:41:00'),
(2,1,'SL-2026-9F3K2A-2',2,3,'delivery','out_for_delivery',NULL,34000.00,6000.00,40000.00,1700.00,'2026-09-20 09:20:00','2026-09-21 14:22:00',NULL,'2026-09-20 06:14:00','2026-09-22 05:10:00'),
(3,2,'SL-2026-7B1X9C-1',1,2,'pickup','awaiting_seller',NULL,48000.00,0.00,48000.00,2400.00,NULL,NULL,NULL,'2026-09-22 04:02:00','2026-09-22 04:03:00'),
(4,3,'SL-2026-2D8M4T-1',1,1,'delivery','preparing',NULL,36200.00,6000.00,42200.00,1810.00,'2026-09-21 17:05:00',NULL,NULL,'2026-09-21 16:40:00','2026-09-22 03:15:00'),
(5,4,'SL-2026-5H2P7Q-1',1,1,'pickup','completed',NULL,36200.00,0.00,36200.00,1810.00,'2026-08-06 08:00:00','2026-08-06 16:02:00','2026-08-07 12:05:00','2026-08-06 07:20:00','2026-08-07 12:05:00'),
(6,5,'SL-2026-3K9W1E-1',2,3,'delivery','rejected_seller','Stock count was wrong - only 1 pack physically in the store',34200.00,6000.00,40200.00,0.00,NULL,NULL,NULL,'2026-09-17 10:11:00','2026-09-17 13:44:00'),
(7,6,'SL-2026-8T4R6Y-1',2,3,'delivery','delivery_failed',NULL,92000.00,8000.00,100000.00,4600.00,'2026-09-19 09:00:00','2026-09-20 10:00:00',NULL,'2026-09-19 08:30:00','2026-09-21 11:20:00'),
(8,7,'SL-2026-1A5B3C-1',2,3,'delivery','completed',NULL,32600.00,6000.00,38600.00,1630.00,'2026-07-14 13:30:00','2026-07-15 11:00:00','2026-07-16 09:30:00','2026-07-14 12:00:00','2026-07-16 09:30:00');

INSERT INTO order_items (seller_order_id, product_id, name_snapshot, sku_snapshot, pack_size_snapshot, unit_price, qty, line_total) VALUES
(1,101,'Alizeti Pure Sunflower Cooking Oil','ALZ-OIL-5L','5 L',28500.00,1,28500.00),
(1,105,'Chai Bora Loose Leaf Tea','CHB-TEA-500','500 g',6200.00,2,12400.00),
(2,115,'Mtoto Dry Nappies Size 4','MTO-NAP-4','50 nappies',34000.00,1,34000.00),
(3,103,'Sembe Fine Maize Flour','NYB-SMB-10','10 kg',24000.00,2,48000.00),
(4,117,'Chemchem Drinking Water','CHM-WTR-12','12 x 1.5 L',13200.00,2,26400.00),
(4,107,'Safi Multi-Surface Cleaner','SAF-MSC-2L','2 L',9800.00,1,9800.00),
(5,101,'Alizeti Pure Sunflower Cooking Oil','ALZ-OIL-5L','5 L',27000.00,1,27000.00),
(5,109,'Mwangaza Moisturising Bath Soap','MWG-BSP-4','4 x 175 g',9200.00,1,9200.00),
(6,106,'Jamaa Laundry Soap Bars','JMA-LSB-6','6 x 800 g',11400.00,3,34200.00),
(7,102,'Mbeya Premium White Rice','MBH-RCE-25','25 kg',92000.00,1,92000.00),
(8,104,'Kilombero Brown Sugar','KLB-SGR-2','2 kg',7800.00,3,23400.00),
(8,111,'Meno Safi Fluoride Toothpaste','MNS-TPS-150','150 ml',4600.00,2,9200.00);

INSERT INTO order_status_history (seller_order_id, from_status, to_status, actor_user_id, actor_type, reason, created_at) VALUES
(1,NULL,'pending_payment',9,'customer',NULL,'2026-09-20 06:14:00'),
(1,'pending_payment','awaiting_seller',NULL,'system','Payment confirmed by verified callback','2026-09-20 06:15:00'),
(1,'awaiting_seller','confirmed',2,'seller',NULL,'2026-09-20 07:02:00'),
(1,'confirmed','preparing',2,'seller',NULL,'2026-09-21 05:30:00'),
(1,'preparing','ready_for_pickup',2,'seller','Collection code issued','2026-09-21 07:41:00'),
(2,NULL,'pending_payment',9,'customer',NULL,'2026-09-20 06:14:00'),
(2,'pending_payment','awaiting_seller',NULL,'system','Payment confirmed by verified callback','2026-09-20 06:15:00'),
(2,'awaiting_seller','confirmed',3,'seller',NULL,'2026-09-20 09:20:00'),
(2,'confirmed','preparing',3,'seller',NULL,'2026-09-21 08:00:00'),
(2,'preparing','ready_for_dispatch',3,'seller','Delivery task created','2026-09-21 14:22:00'),
(2,'ready_for_dispatch','assigned',1,'admin','Assigned to Juma Kileo','2026-09-21 15:05:00'),
(2,'assigned','picked_up',6,'agent',NULL,'2026-09-22 04:40:00'),
(2,'picked_up','out_for_delivery',6,'agent',NULL,'2026-09-22 05:10:00'),
(3,NULL,'pending_payment',10,'customer',NULL,'2026-09-22 04:02:00'),
(3,'pending_payment','awaiting_seller',NULL,'system','Payment confirmed by verified callback','2026-09-22 04:03:00'),
(4,NULL,'pending_payment',11,'customer','Cash on delivery selected','2026-09-21 16:40:00'),
(4,'pending_payment','awaiting_seller',NULL,'system',NULL,'2026-09-21 16:40:00'),
(4,'awaiting_seller','confirmed',2,'seller',NULL,'2026-09-21 17:05:00'),
(4,'confirmed','preparing',2,'seller',NULL,'2026-09-22 03:15:00'),
(5,NULL,'pending_payment',9,'customer',NULL,'2026-08-06 07:20:00'),
(5,'pending_payment','awaiting_seller',NULL,'system',NULL,'2026-08-06 07:21:00'),
(5,'awaiting_seller','confirmed',2,'seller',NULL,'2026-08-06 08:00:00'),
(5,'confirmed','preparing',2,'seller',NULL,'2026-08-06 15:10:00'),
(5,'preparing','ready_for_pickup',2,'seller',NULL,'2026-08-06 16:02:00'),
(5,'ready_for_pickup','collected',2,'seller','Collection code verified','2026-08-07 12:05:00'),
(5,'collected','completed',NULL,'system',NULL,'2026-08-07 12:05:00'),
(6,NULL,'pending_payment',13,'customer',NULL,'2026-09-17 10:11:00'),
(6,'pending_payment','awaiting_seller',NULL,'system',NULL,'2026-09-17 10:12:00'),
(6,'awaiting_seller','rejected_seller',3,'seller','Stock count was wrong - only 1 pack physically in the store','2026-09-17 13:40:00'),
(6,'rejected_seller','refund_pending',NULL,'system','Reservation released','2026-09-17 13:40:00'),
(6,'refund_pending','refunded',NULL,'system','Refunded to original method','2026-09-17 13:44:00'),
(7,NULL,'pending_payment',12,'customer',NULL,'2026-09-19 08:30:00'),
(7,'pending_payment','awaiting_seller',NULL,'system',NULL,'2026-09-19 08:31:00'),
(7,'awaiting_seller','confirmed',3,'seller',NULL,'2026-09-19 09:00:00'),
(7,'confirmed','preparing',3,'seller',NULL,'2026-09-20 07:15:00'),
(7,'preparing','ready_for_dispatch',3,'seller',NULL,'2026-09-20 10:00:00'),
(7,'ready_for_dispatch','assigned',1,'admin','Assigned to Neema Bakari','2026-09-20 10:30:00'),
(7,'assigned','out_for_delivery',7,'agent',NULL,'2026-09-21 09:05:00'),
(7,'out_for_delivery','delivery_failed',7,'agent','Recipient absent - attempt 1 of 3','2026-09-21 11:20:00'),
(8,NULL,'pending_payment',9,'customer',NULL,'2026-07-14 12:00:00'),
(8,'pending_payment','awaiting_seller',NULL,'system',NULL,'2026-07-14 12:01:00'),
(8,'awaiting_seller','confirmed',3,'seller',NULL,'2026-07-14 13:30:00'),
(8,'confirmed','preparing',3,'seller',NULL,'2026-07-15 08:00:00'),
(8,'preparing','ready_for_dispatch',3,'seller',NULL,'2026-07-15 11:00:00'),
(8,'ready_for_dispatch','assigned',1,'admin',NULL,'2026-07-15 12:00:00'),
(8,'assigned','out_for_delivery',6,'agent',NULL,'2026-07-16 07:00:00'),
(8,'out_for_delivery','delivered',6,'agent','Recipient code verified','2026-07-16 09:30:00'),
(8,'delivered','completed',NULL,'system',NULL,'2026-07-16 09:30:00');

-- Collection codes are stored HASHED. For local testing the plaintext for
-- sub-order 1 is K7M2QP; the hash below is SHA-256 of that string. Nothing in
-- the application can read a code back - it can only verify or regenerate one.
INSERT INTO order_pickups (seller_order_id, store_id, code_hash, code_issued_at, window_from, window_to, instructions_snapshot, verify_attempts, collected_at, collected_by_user_id) VALUES
(1,1,SHA2('K7M2QP',256),'2026-09-21 07:41:00','2026-09-21 06:00:00','2026-09-24 17:00:00','Collection counter is inside the main entrance on the left. Please have your collection code ready on your phone.',0,NULL,NULL),
(3,2,NULL,NULL,NULL,NULL,'Ring the bell at the side gate marked COLLECTIONS. Parking available for 10 minutes.',0,NULL,NULL),
(5,1,NULL,NULL,'2026-08-07 06:00:00','2026-08-10 17:00:00','Collection counter is inside the main entrance on the left.',1,'2026-08-07 12:05:00',2);

INSERT INTO delivery_tasks (id, task_ref, seller_order_id, zone_id, agent_user_id, status, recipient_name, recipient_phone, address_line, landmark, instructions, fee, cod_amount, code_hash, attempts, max_attempts, assigned_at, delivered_at, created_at) VALUES
(1,'DEL-4412',2,2,6,'out_for_delivery','Asha Mwinyi','+255712345118','Chole Road 22, Msasani, Kinondoni, Dar es Salaam','Blue gate opposite the pharmacy','Call on arrival, the gate bell does not work.',6000.00,NULL,SHA2('4471',256),0,3,'2026-09-21 15:05:00',NULL,'2026-09-21 14:22:00'),
(2,'DEL-4398',7,3,7,'failed','Grace Temu','+255689000771','Sakina Street 3, Arusha City, Arusha','Behind the secondary school','Heavy item - please ring before arriving.',8000.00,NULL,NULL,1,3,'2026-09-20 10:30:00',NULL,'2026-09-20 10:00:00'),
(3,'DEL-4102',8,2,6,'delivered','Asha Mwinyi','+255712345118','Chole Road 22, Msasani, Kinondoni, Dar es Salaam','Blue gate opposite the pharmacy',NULL,6000.00,NULL,NULL,1,3,'2026-07-15 12:00:00','2026-07-16 09:30:00','2026-07-15 11:00:00'),
(4,'DEL-4421',4,2,NULL,'unassigned','Neema Kessy','+255712340220','Kawe Beach Road 8, Kawe, Kinondoni, Dar es Salaam','Green roof, second house after the mosque','Cash on delivery - exact change appreciated.',6000.00,42200.00,NULL,0,3,NULL,NULL,'2026-09-22 03:15:00');

INSERT INTO delivery_events (task_id, event_type, from_status, to_status, reason_code, note, contacted_recipient, actor_user_id, created_at) VALUES
(1,'assigned',NULL,'assigned',NULL,'Assigned by administrator',NULL,1,'2026-09-21 15:05:00'),
(1,'status_change','assigned','picked_up',NULL,NULL,NULL,6,'2026-09-22 04:40:00'),
(1,'status_change','picked_up','out_for_delivery',NULL,NULL,NULL,6,'2026-09-22 05:10:00'),
(2,'assigned',NULL,'assigned',NULL,'Assigned by administrator',NULL,1,'2026-09-20 10:30:00'),
(2,'status_change','assigned','out_for_delivery',NULL,NULL,NULL,7,'2026-09-21 09:05:00'),
(2,'attempt_failed','out_for_delivery','failed','recipient_absent','Nobody at the address, phone unanswered after three attempts.',1,7,'2026-09-21 11:20:00'),
(3,'assigned',NULL,'assigned',NULL,NULL,NULL,1,'2026-07-15 12:00:00'),
(3,'status_change','assigned','out_for_delivery',NULL,NULL,NULL,6,'2026-07-16 07:00:00'),
(3,'status_change','out_for_delivery','delivered',NULL,'Recipient code verified',NULL,6,'2026-07-16 09:30:00');


-- =============================================================================
--  PAYMENTS
--  Every transaction is against the development SANDBOX gateway. No money has
--  moved and no live provider is connected.
-- =============================================================================

INSERT INTO payment_intents (id, order_id, gateway, gateway_intent_ref, amount, currency, status, created_at) VALUES
(1,1,'sandbox','SBXI-9F3K2A',80900.00,'TZS','succeeded','2026-09-20 06:14:00'),
(2,2,'sandbox','SBXI-7B1X9C',48000.00,'TZS','succeeded','2026-09-22 04:02:00'),
(3,4,'sandbox','SBXI-5H2P7Q',36200.00,'TZS','succeeded','2026-08-06 07:20:00'),
(4,5,'sandbox','SBXI-3K9W1E',40200.00,'TZS','succeeded','2026-09-17 10:11:00'),
(5,6,'sandbox','SBXI-8T4R6Y',100000.00,'TZS','succeeded','2026-09-19 08:30:00'),
(6,7,'sandbox','SBXI-1A5B3C',38600.00,'TZS','succeeded','2026-07-14 12:00:00');

INSERT INTO payment_transactions (transaction_ref, order_id, intent_id, gateway, gateway_reference, direction, amount, currency, status, signature_verified, processed_at, created_at) VALUES
('TXN-88210',1,1,'sandbox','SBX-8f31c2a9','charge',80900.00,'TZS','paid',1,'2026-09-20 06:15:00','2026-09-20 06:15:00'),
('TXN-88206',2,2,'sandbox','SBX-1a7d4e02','charge',48000.00,'TZS','paid',1,'2026-09-22 04:03:00','2026-09-22 04:03:00'),
('TXN-88140',4,3,'sandbox','SBX-2b61ff04','charge',36200.00,'TZS','paid',1,'2026-08-06 07:21:00','2026-08-06 07:21:00'),
('TXN-88199',5,4,'sandbox','SBX-4c90b115','charge',40200.00,'TZS','paid',1,'2026-09-17 10:12:00','2026-09-17 10:12:00'),
('TXN-88200',5,4,'sandbox','SBX-4c90b115-R','refund',40200.00,'TZS','refunded',1,'2026-09-17 13:44:00','2026-09-17 13:44:00'),
('TXN-88185',6,5,'sandbox','SBX-77aa2f18','charge',100000.00,'TZS','paid',1,'2026-09-19 08:31:00','2026-09-19 08:31:00'),
('TXN-88120',7,6,'sandbox','SBX-5d22ab71','charge',38600.00,'TZS','paid',1,'2026-07-14 12:01:00','2026-07-14 12:01:00'),
('TXN-88170',3,NULL,'cash','COD-2D8M4T','charge',42200.00,'TZS','pending',0,NULL,'2026-09-21 16:40:00');

INSERT INTO refunds (refund_ref, order_id, seller_order_id, amount, reason, status, requested_by, approved_by, requested_at, approved_at, completed_at) VALUES
('RFD-1204',5,6,40200.00,'Seller rejected - stock count error','refunded',NULL,1,'2026-09-17 13:40:00','2026-09-17 13:42:00','2026-09-17 13:44:00');


-- =============================================================================
--  NOTIFICATIONS, CONSENT AND REMINDERS
--
--  Asha  (9)  consented and still consents      -> reminders are eligible
--  Baraka(10) NEVER consented                   -> skip reason: no_consent
--  Grace (12) consented then WITHDREW           -> skip reason: consent_withdrawn
--
--  Those three cases exist so that "we do not message people who said no" is a
--  testable assertion rather than a claim.
-- =============================================================================

INSERT INTO notification_preferences (user_id, category, channel, is_enabled) VALUES
(9,'order_updates','email',1),(9,'pickup_delivery','email',1),(9,'support','email',1),(9,'reorder','email',1),(9,'offers','email',0),
(10,'order_updates','email',1),(10,'pickup_delivery','email',1),(10,'support','email',1),(10,'reorder','email',0),(10,'offers','email',0),
(11,'order_updates','email',1),(11,'pickup_delivery','email',1),(11,'support','email',1),(11,'reorder','email',1),(11,'offers','email',0),
(12,'order_updates','email',1),(12,'pickup_delivery','email',1),(12,'support','email',1),(12,'reorder','email',0),(12,'offers','email',0),
(13,'order_updates','email',1),(13,'pickup_delivery','email',1),(13,'support','email',1),(13,'reorder','email',1),(13,'offers','email',0);

INSERT INTO consent_records (user_id, consent_type, granted, version, source, created_at) VALUES
(9,'terms',1,'v1.0','registration','2026-03-14 19:10:00'),
(9,'privacy',1,'v1.0','registration','2026-03-14 19:10:00'),
(9,'marketing',1,'v1.0','registration','2026-03-14 19:12:00'),
(10,'terms',1,'v1.0','registration','2026-04-02 12:00:00'),
(10,'privacy',1,'v1.0','registration','2026-04-02 12:00:00'),
(11,'terms',1,'v1.0','registration','2026-05-30 10:00:00'),
(11,'marketing',1,'v1.0','checkout','2026-05-30 10:20:00'),
(12,'terms',1,'v1.0','registration','2026-06-01 09:00:00'),
(12,'marketing',1,'v1.0','registration','2026-06-01 09:00:00'),
(12,'marketing',0,'v1.0','support_request','2026-09-15 07:10:00'),
(13,'terms',1,'v1.0','registration','2026-06-10 11:00:00'),
(13,'marketing',1,'v1.0','registration','2026-06-10 11:00:00');

INSERT INTO notifications (user_id, channel, category, is_marketing, template_key, status, skip_reason, attempts, provider_response, send_after, sent_at, reference_type, reference_id, created_at) VALUES
(9,'email','pickup_delivery',0,'order.ready_for_pickup','delivered',NULL,1,'250 OK','2026-09-21 07:41:00','2026-09-21 07:41:12','seller_order',1,'2026-09-21 07:41:00'),
(9,'email','pickup_delivery',0,'order.out_for_delivery','delivered',NULL,1,'250 OK','2026-09-22 05:10:00','2026-09-22 05:10:09','seller_order',2,'2026-09-22 05:10:00'),
(9,'sms','pickup_delivery',0,'order.ready_for_pickup','skipped','skipped_no_provider',0,NULL,'2026-09-21 07:41:00',NULL,'seller_order',1,'2026-09-21 07:41:00'),
(9,'email','reorder',1,'reorder.reminder','delivered',NULL,1,'250 OK','2026-09-14 06:00:00','2026-09-14 06:00:04','product',101,'2026-09-14 06:00:00'),
(10,'email','reorder',1,'reorder.reminder','skipped','no_consent',0,NULL,'2026-09-20 03:00:00',NULL,'product',103,'2026-09-20 03:00:00'),
(11,'email','reorder',1,'reorder.reminder','skipped','already_repurchased',0,NULL,'2026-09-20 03:00:00',NULL,'product',117,'2026-09-20 03:00:00'),
(12,'email','pickup_delivery',0,'delivery.failed','failed',NULL,3,'451 Temporary local problem, retry later','2026-09-21 11:20:00',NULL,'seller_order',7,'2026-09-21 11:20:00'),
(12,'email','reorder',1,'reorder.reminder','skipped','consent_withdrawn',0,NULL,'2026-09-16 03:00:00',NULL,'product',102,'2026-09-16 03:00:00'),
(9,'email','order_updates',0,'order.collected','delivered',NULL,1,'250 OK','2026-08-07 12:05:00','2026-08-07 12:05:06','seller_order',5,'2026-08-07 12:05:00'),
(13,'email','order_updates',0,'order.rejected','delivered',NULL,1,'250 OK','2026-09-17 13:41:00','2026-09-17 13:41:05','seller_order',6,'2026-09-17 13:41:00');

-- Every row records HOW the date was estimated, and every skip records WHY.
INSERT INTO reorder_reminders (user_id, product_id, source_seller_order_id, cycle_key, basis, basis_detail, last_purchased_at, next_due_at, state, skip_reason) VALUES
(9,101,5,'so:5','observed_interval','Median of 3 prior repeats: 44 days','2026-08-07 12:05:00','2026-09-20 06:00:00','sent',NULL),
(9,109,5,'so:5','seller_hint','42 days x 1 pack','2026-08-07 12:05:00','2026-09-18 06:00:00','skipped','cooldown'),
(11,117,4,'so:4','seller_hint','12 days x 2 packs','2026-09-21 16:40:00','2026-10-15 06:00:00','scheduled',NULL),
(10,103,3,'so:3','seller_hint','30 days x 2 bags','2026-08-20 09:00:00','2026-09-20 06:00:00','skipped','no_consent'),
(12,102,7,'so:7','observed_interval','Median of 2 prior repeats: 58 days','2026-07-25 08:00:00','2026-09-21 06:00:00','skipped','consent_withdrawn'),
(9,104,8,'so:8','seller_hint','35 days x 3 packs','2026-07-16 09:30:00','2026-10-24 06:00:00','scheduled',NULL),
-- Kitchen roll has no seller hint and only one purchase, so NOTHING is scheduled.
(13,108,NULL,'manual:108','none','No seller hint and only one purchase - nothing scheduled','2026-09-01 10:00:00',NULL,'not_scheduled','insufficient_data');


-- =============================================================================
--  REVIEWS - every one tied to a COMPLETED seller_order (FR-REV-01)
-- =============================================================================

INSERT INTO reviews (product_id, user_id, seller_order_id, rating, title, body, status, seller_reply, seller_replied_at, created_at) VALUES
(101,9,5,5,'Good value for the 5 litre size','I have bought this four times now. The seal was intact each time and the oil is clean with no smell. Collecting from Kariakoo took about two minutes once I had the code.','published','Thank you Asha, we always check the seals before the collection counter.','2026-08-29 06:40:00','2026-08-28 09:14:00'),
(109,9,5,4,'Lathers well','Nice scent and it lasts. Four bars is about six weeks for us.','published',NULL,NULL,'2026-08-28 09:20:00'),
(104,9,8,5,'Proper brown sugar','Real molasses taste, not just dyed white sugar.','published',NULL,NULL,'2026-07-20 07:00:00'),
(111,9,8,4,'Does the job','Mild mint, the children do not complain.','published',NULL,NULL,'2026-07-20 07:05:00');


-- =============================================================================
--  SUPPORT
-- =============================================================================

INSERT INTO support_tickets (id, ticket_ref, user_id, order_id, category, subject, priority, status, assigned_to, escalated_to, escalation_reason, resolved_at, created_at, updated_at) VALUES
(1,'TKT-2026-0412',10,2,'collection','Collection code not working','high','open',NULL,NULL,NULL,NULL,'2026-09-22 05:40:00','2026-09-22 05:40:00'),
(2,'TKT-2026-0411',12,6,'delivery','Where is my delivery?','high','in_progress',8,NULL,NULL,NULL,'2026-09-21 12:05:00','2026-09-21 14:30:00'),
(3,'TKT-2026-0409',13,5,'payment','Refund for rejected order','normal','escalated',8,1,'Third rejection from Duka Kuu this month for stock-count errors. Worth an inventory review with the seller.',NULL,'2026-09-18 09:12:00','2026-09-20 08:00:00'),
(4,'TKT-2026-0407',12,NULL,'account','Stop the reorder reminders','low','resolved',8,NULL,NULL,'2026-09-15 07:10:00','2026-09-15 06:30:00','2026-09-15 07:10:00'),
(5,'TKT-2026-0402',9,7,'order','Wrong pack size delivered','normal','waiting_customer',8,NULL,NULL,NULL,'2026-09-10 15:20:00','2026-09-11 08:00:00');

INSERT INTO support_messages (ticket_id, author_user_id, author_role, body, is_internal, created_at) VALUES
(1,10,'customer','I am at the Mbezi Beach store and the counter says my code is not recognised. The order still shows as awaiting seller in my account.',0,'2026-09-22 05:40:00'),
(2,12,'customer','The agent marked my delivery as failed but nobody called me. I was home all morning.',0,'2026-09-21 12:05:00'),
(2,8,'support','Checked the task: agent Neema Bakari logged recipient_absent at 11:20. Phone on file is +255 6** *** 771. Asking the agent for the call log before replying.',1,'2026-09-21 13:10:00'),
(2,8,'support','Sorry about that Grace. I can see the failed attempt was logged at 11:20. I have asked the agent to confirm what happened and arranged a second attempt for tomorrow morning. Your order is not cancelled and you have not been charged again.',0,'2026-09-21 14:30:00'),
(3,13,'customer','The seller rejected my order for soap bars. The app says refunded but I have not seen the money.',0,'2026-09-18 09:12:00'),
(3,8,'support','The refund was recorded on 17 September. Sandbox refunds do not move real money, so nothing will appear in your account during this testing period.',0,'2026-09-18 11:00:00'),
(3,8,'support','Escalating: this is the third rejection from Duka Kuu this month for stock-count errors. Worth an inventory review with the seller.',1,'2026-09-20 08:00:00'),
(4,12,'customer','I want to stop the emails about buying rice again.',0,'2026-09-15 06:30:00'),
(4,8,'support','Done - marketing consent withdrawn and recorded at your request. You will still get messages about orders you place. Every reminder email also has a one-click unsubscribe link that works without logging in.',0,'2026-09-15 07:10:00'),
(5,9,'customer','I ordered 2 kg sugar packs and received 1 kg packs.',0,'2026-09-10 15:20:00'),
(5,8,'support','Thanks Asha. Could you send a photo of the packs and the receipt so I can raise this with the seller?',0,'2026-09-11 08:00:00');


-- =============================================================================
--  AUDIT LOG - append-only
-- =============================================================================

INSERT INTO audit_log (actor_user_id, actor_role, action, entity_type, entity_id, detail, justification, ip_address, created_at) VALUES
(6,'delivery_agent','delivery.status.update','delivery_tasks','1','picked_up -> out_for_delivery',NULL,INET6_ATON('41.86.10.4'),'2026-09-22 05:10:00'),
(8,'support','support.customer_record.view','users','12','Opened customer record from ticket','TKT-2026-0411',INET6_ATON('196.44.2.10'),'2026-09-21 14:30:00'),
(1,'admin','delivery.task.assign','delivery_tasks','1','Assigned to agent#6 Juma Kileo',NULL,INET6_ATON('41.59.1.2'),'2026-09-21 15:05:00'),
(2,'seller','order.transition.ready_for_pickup','seller_orders','SL-2026-9F3K2A-1','Collection code issued (hash stored)',NULL,INET6_ATON('41.222.5.9'),'2026-09-21 07:41:00'),
(3,'seller','order.transition.reject','seller_orders','SL-2026-3K9W1E-1','Reason: stock count was wrong',NULL,INET6_ATON('41.188.7.3'),'2026-09-17 13:40:00'),
(8,'support','notification.consent.withdraw','users','12','On customer request','TKT-2026-0407',INET6_ATON('196.44.2.10'),'2026-09-15 07:10:00'),
(1,'admin','user.suspend','users','14','Reason: repeated fraudulent chargeback claims',NULL,INET6_ATON('41.59.1.2'),'2026-08-30 14:12:00'),
(1,'admin','seller.reject','seller_applications','28','Reason: registration number unverifiable',NULL,INET6_ATON('41.59.1.2'),'2026-09-09 10:15:00'),
(1,'admin','refund.approve','refunds','RFD-1204','Approved full refund of 40200.00 TZS',NULL,INET6_ATON('41.59.1.2'),'2026-09-17 13:42:00');


-- =============================================================================
--  SETTINGS - business rules only. Secrets live in .env, never here.
-- =============================================================================

INSERT INTO settings (setting_key, setting_value, value_type, setting_group, description) VALUES
('platform.name','SokoLink','string','General','Name shown in the interface and in emails'),
('platform.currency','TZS','string','General','ISO currency code'),
('platform.timezone_display','Africa/Dar_es_Salaam','string','General','Display timezone; storage is always UTC'),
('orders.unpaid_expiry_minutes','60','int','Orders','How long an unpaid order holds its stock reservation'),
('pickup.collection_window_hours','72','int','Orders','Default hours before an uncollected order is flagged overdue'),
('delivery.max_attempts','3','int','Delivery','Attempts before a task returns to the seller'),
('delivery.assignment_mode','admin','enum','Delivery','admin = an administrator assigns; pool = agents claim'),
('commission.default_percent','5.0','decimal','Finance','Commission recorded per sale. Payouts are not built in v1'),
('reminders.cooldown_days','14','int','Retention','Minimum days between reminders for the same product'),
('reminders.monthly_cap','4','int','Retention','Maximum marketing messages per customer per month'),
('notify.quiet_hours_start','21','int','Retention','Marketing is held from this hour, display timezone'),
('notify.quiet_hours_end','7','int','Retention','Marketing resumes at this hour, display timezone'),
('notify.retry_max','3','int','Retention','Retries for a transient send failure'),
('notify.sender_address','no-reply@sokolink.test','string','Retention','From address for outbound email'),
('cod.max_order_value','200000.00','decimal','Payments','Cash is not offered above this order total'),
('cod.max_open_orders','2','int','Payments','Unfinished cash orders one customer may have at once'),
('cod.max_strikes','2','int','Payments','Customer-caused no-shows within the window before cash is withdrawn'),
('cod.strike_window_days','90','int','Payments','How far back no-shows are counted before they age out');


-- =============================================================================
--  END OF SEED
-- =============================================================================
