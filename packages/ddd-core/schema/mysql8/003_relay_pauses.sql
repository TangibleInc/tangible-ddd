-- tangible/ddd-core Defaults/Pdo: relay pauses by holder (register 3.4, C25).
-- One row per (holder, selector). A selector is an exact event type, `*`, or
-- an fnmatch glob such as `acme_order_*`. held_until NULL = held until
-- released; held_until <= now = released. (UNTIL is a MySQL reserved word.)

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_relay_pauses` (
  `holder`     VARCHAR(191) NOT NULL,
  `selector`   VARCHAR(191) NOT NULL,
  `held_until` DATETIME(6)  NULL,
  `created_at` DATETIME(6)  NOT NULL,
  PRIMARY KEY (`holder`, `selector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
