-- tangible/ddd-symfony: relay pauses by holder (register 3.4, C25).
-- One row per (holder, selector). A selector is an exact event type, `*`, or a
-- glob such as `acme_order_*` (fnmatch syntax). until NULL = held until released;
-- until <= now = released.

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_relay_pauses (
    holder     VARCHAR(191) NOT NULL,
    selector   VARCHAR(255) NOT NULL,
    until      TIMESTAMPTZ  NULL,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (holder, selector)
);
