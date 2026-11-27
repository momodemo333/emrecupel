-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later

ALTER TABLE llx_emrecupel_ledger ADD INDEX idx_emrecupel_ledger_period (entity, date_doc);
ALTER TABLE llx_emrecupel_ledger ADD INDEX idx_emrecupel_ledger_facture (fk_facture);
ALTER TABLE llx_emrecupel_ledger ADD UNIQUE INDEX uk_emrecupel_ledger_line (fk_facturedet, code);
