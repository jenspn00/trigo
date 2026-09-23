-- =================================================================
--  Trigo — basisskema
--
--  ADVARSEL: denne fil er REKONSTRUERET ud fra de queries, koden
--  rent faktisk kører — ikke dumpet fra den kørende database.
--  Tabellen `observations` blev oprettet i hånden i phpMyAdmin og
--  fandtes ikke i repoet, så en ny installation kunne ikke bygges
--  op fra kildekoden.
--
--  KØR DEN IKKE PÅ DEN EKSISTERENDE DATABASE. Kør i stedet
--      SHOW CREATE TABLE observations;
--  på produktionsdatabasen og ret denne fil til, hvis der er
--  afvigelser (særligt typer og længder). Herefter er filen
--  sandheden for nye installationer.
--
--  Til en frisk installation: kør kun denne fil. schema_migration.sql
--  skal IKKE køres bagefter — den tilføjer session_id og address til
--  en gammel tabel, og de er allerede med her.
-- =================================================================

CREATE TABLE IF NOT EXISTS observations (
    -- Klienterne danner selv id'et, og de gør det forskelligt:
    -- gun.html og simulator.php laver 13 cifre, save_data.php's
    -- fallback 14, index.html 16. Derfor VARCHAR og ikke BIGINT —
    -- alle endepunkter binder feltet som streng ("s").
    id           VARCHAR(32)  NOT NULL,

    -- Per-browser/enhed-id fra localStorage. Dette er enheden som
    -- dashboardets fusion grupperer efter — to pejlinger med samme
    -- session_id krydses aldrig med hinanden. NULL tillades, fordi
    -- gamle rækker og ikke-migrerede databaser mangler det.
    session_id   VARCHAR(40)  NULL,

    -- Skrives via FROM_UNIXTIME(), så MySQL ejer tidskonverteringen
    -- i både skrive- og læsesiden (se kommentar i save_data.php).
    observed_at  DATETIME     NOT NULL,

    -- Observatørens position. DOUBLE, fordi alle endepunkter binder
    -- disse som "d".
    latitude     DOUBLE       NOT NULL,
    longitude    DOUBLE       NOT NULL,
    altitude     DOUBLE       NULL DEFAULT 0,

    -- Pejlingen: kompasretning 0–360° og elevationsvinkel -90–90°.
    -- Range valideres i save_data.php, ikke i skemaet.
    azimuth      DOUBLE       NOT NULL,
    elevation    DOUBLE       NULL DEFAULT 0,

    -- Reverse-geocoded adresse, skrevet bagefter som baggrundsjob.
    address      VARCHAR(255) NULL,

    PRIMARY KEY (id),
    INDEX idx_session     (session_id),
    INDEX idx_observed_at (observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =================================================================
--  `tracked_objects` — BEVIDST UDELADT
--
--  delete_object.php sletter fra en tabel `tracked_objects` med en
--  kolonne `object_id`, men intet andet sted i projektet opretter,
--  skriver til eller læser fra den. track_objects.php returnerer
--  bare en tom liste med kommentaren "plads til fremtidig
--  triangulering".
--
--  Der er altså ikke nok i koden til at udlede tabellens form, og
--  et gæt ville være værre end ingenting. Findes tabellen i
--  produktionsdatabasen, så dump den med SHOW CREATE TABLE og
--  indsæt den her. Gør den ikke, er delete_object.php dødt kode,
--  der kan slettes.
-- =================================================================
