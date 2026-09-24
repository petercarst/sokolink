-- =============================================================================
--  Cash on fulfilment: seller opt-out, and the policy limits
-- =============================================================================
--
--  Cash is the one payment method where the platform holds no money and takes
--  no risk - the seller does. They pick, pack and hold goods for somebody who
--  has committed nothing, and if that person never turns up the seller has paid
--  for the picking and lost the shelf space.
--
--  Two things follow, and this migration adds both:
--
--    1. A seller can refuse cash. `sellers.accepts_cod` defaults to 1, so
--       nothing changes for anybody until they choose otherwise.
--
--    2. The platform caps the exposure: how much, how many at once, and how
--       many no-shows before the method is withdrawn from one customer. Those
--       are settings rather than constants because the right numbers are a
--       business decision that will change, and changing them must not need a
--       deployment.
--
--  Forward-only. Safe to run on a populated database: one nullable-free column
--  with a default, and four INSERT IGNOREs.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';

USE sokolink;

-- A seller who does not want to carry the no-show risk. Default 1: this
-- migration changes nobody's behaviour until they switch it off themselves.
ALTER TABLE sellers
    ADD COLUMN accepts_cod TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_accept;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, setting_group, description) VALUES
    ('cod.max_order_value', '200000.00', 'decimal', 'Payments',
     'Cash is not offered above this order total. Large amounts carry more risk and more change to find.'),
    ('cod.max_open_orders', '2', 'int', 'Payments',
     'How many unfinished cash orders one customer may have at once.'),
    ('cod.max_strikes', '2', 'int', 'Payments',
     'Customer-caused no-shows within the window before cash is withdrawn from that customer.'),
    ('cod.strike_window_days', '90', 'int', 'Payments',
     'How far back no-shows are counted. A strike ages out rather than following somebody for ever.');
