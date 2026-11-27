-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later
--
-- Recupel codes of a product, with the number of appliances per sold unit
-- (luminaire + its lamps, camera kit: several rows).

CREATE TABLE llx_emrecupel_product_code (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_product      integer NOT NULL,
    code            varchar(16) NOT NULL,
    units           double(24,8) NOT NULL DEFAULT 1,
    position        integer NOT NULL DEFAULT 0,
    tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
