-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later

ALTER TABLE llx_emrecupel_cat ADD UNIQUE INDEX uk_emrecupel_cat_code (entity, code);
ALTER TABLE llx_emrecupel_cat ADD INDEX idx_emrecupel_cat_selectable (selectable);
