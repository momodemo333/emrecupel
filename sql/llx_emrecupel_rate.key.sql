-- Copyright (C) 2026 E-dem SRL <support@e-dem.com>
-- GPL-3.0-or-later

ALTER TABLE llx_emrecupel_rate ADD UNIQUE INDEX uk_emrecupel_rate_start (entity, category_code, date_start);
ALTER TABLE llx_emrecupel_rate ADD INDEX idx_emrecupel_rate_lookup (category_code, date_start, date_end);
