-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later
--
-- Recupel scale: categories (national list, shared by all entities).

CREATE TABLE llx_emrecupel_cat (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity          integer DEFAULT 1 NOT NULL,
    code            varchar(16) NOT NULL,
    parent_code     varchar(16) DEFAULT NULL,
    label_fr        varchar(255) NOT NULL DEFAULT '',
    label_nl        varchar(255) NOT NULL DEFAULT '',
    label_en        varchar(255) NOT NULL DEFAULT '',
    use_type        varchar(16) NOT NULL DEFAULT 'household',
    position        integer NOT NULL DEFAULT 0,
    active          tinyint NOT NULL DEFAULT 1,
    selectable      tinyint NOT NULL DEFAULT 1,
    date_creation   datetime NOT NULL,
    tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
