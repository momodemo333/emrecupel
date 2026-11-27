-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later
--
-- Snapshot of the appliances sold on validated customer invoices (one row per
-- invoice line and Recupel code), written at validation and removed when the
-- invoice goes back to draft or is deleted. Source of the declaration report.

CREATE TABLE llx_emrecupel_ledger (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity          integer DEFAULT 1 NOT NULL,
    fk_facture      integer NOT NULL,
    fk_facturedet   integer NOT NULL,
    fk_product      integer NOT NULL,
    invoice_type    smallint NOT NULL DEFAULT 0,
    date_doc        date NOT NULL,
    code            varchar(16) NOT NULL,
    use_type        varchar(16) NOT NULL DEFAULT 'household',
    fee_type        varchar(8) NOT NULL DEFAULT 'allin',
    units           double(24,8) NOT NULL DEFAULT 0,
    weight_kg       double(24,8) DEFAULT NULL,
    origin          varchar(16) NOT NULL DEFAULT '',
    country_code    varchar(3) DEFAULT NULL,
    export          tinyint NOT NULL DEFAULT 0,
    bucket          varchar(16) NOT NULL DEFAULT '',
    rate_ht         double(24,8) DEFAULT NULL,
    amount_ht       double(24,8) DEFAULT NULL,
    decision        varchar(32) NOT NULL DEFAULT '',
    display_mode    varchar(16) NOT NULL DEFAULT '',
    date_creation   datetime NOT NULL
) ENGINE=innodb;
