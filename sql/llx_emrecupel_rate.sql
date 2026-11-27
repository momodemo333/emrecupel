-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later
--
-- Recupel scale: dated rates per category (amount excl. VAT per appliance).

CREATE TABLE llx_emrecupel_rate (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity          integer DEFAULT 1 NOT NULL,
    category_code   varchar(16) NOT NULL,
    amount_ht       double(24,8) NOT NULL DEFAULT 0,
    amount_ttc      double(24,8) DEFAULT NULL,
    fee_type        varchar(8) NOT NULL DEFAULT 'allin',
    date_start      date NOT NULL,
    date_end        date DEFAULT NULL,
    source          varchar(255) DEFAULT NULL,
    date_creation   datetime NOT NULL,
    tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
