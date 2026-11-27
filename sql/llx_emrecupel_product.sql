-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later
--
-- Recupel status of a product: subject (has codes) or "not subject", origin
-- (imported/produced, bought in Belgium, Belgian second-hand) and declarable
-- net weight override. One row per product.

CREATE TABLE llx_emrecupel_product (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity          integer DEFAULT 1 NOT NULL,
    fk_product      integer NOT NULL,
    status          varchar(16) NOT NULL DEFAULT 'subject',
    origin          varchar(16) NOT NULL DEFAULT '',
    weight_kg       double(24,8) DEFAULT NULL,
    date_creation   datetime NOT NULL,
    fk_user_modif   integer DEFAULT NULL,
    tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
