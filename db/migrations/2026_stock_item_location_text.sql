-- Umístění na skladové kartě: volný text, kde kus fyzicky je (showroom, u partnera,
-- v opravně), i mimo evidované sklady.
--
-- PROČ: lokace skladu (warehouse_locations) platí jen pro sledované kusy a mění se
-- skladovým pohybem. Firma, která má zboží vystavené nebo zapůjčené, potřebuje
-- u karty jednoduchou poznámku o místě, podle které jde filtrovat seznam karet.
-- Pole nevstupuje do ocenění ani do pohybů, duplikace karty ho nepřenáší.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, CREATE INDEX IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS location_text VARCHAR(100) NULL DEFAULT NULL
      COMMENT 'umístění karty (volný text), NULL = neuvedeno'
      AFTER note;

CREATE INDEX IF NOT EXISTS idx_si_supplier_location ON stock_items (supplier_id, location_text);
