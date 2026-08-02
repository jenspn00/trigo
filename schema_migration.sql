-- =================================================================
-- Trigo skema-migration
-- Tilføjer session_id så dashboardet kan skelne observatører.
-- Køres én gang i phpMyAdmin.
-- =================================================================

-- 1. Tilføj session_id + adresse-kolonne
ALTER TABLE observations
    ADD COLUMN session_id VARCHAR(40) NULL AFTER id,
    ADD COLUMN address VARCHAR(255) NULL AFTER elevation,
    ADD INDEX idx_session (session_id),
    ADD INDEX idx_observed_at (observed_at);

-- 2. Backfill: alle gamle rækker får en pseudo-session ud fra IP/dag.
--    Dette er kun for at gamle data ikke alle ligner "samme bruger".
--    (Du kan også bare lade dem være NULL — så grupperes de som "ukendt".)
UPDATE observations
SET session_id = CONCAT('legacy-', SUBSTR(MD5(CONCAT(id, observed_at)), 1, 10))
WHERE session_id IS NULL;
