-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later

ALTER TABLE llx_emrecupel_product_code ADD UNIQUE INDEX uk_emrecupel_product_code (fk_product, code);
