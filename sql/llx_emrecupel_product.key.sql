-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later

ALTER TABLE llx_emrecupel_product ADD UNIQUE INDEX uk_emrecupel_product (fk_product);
