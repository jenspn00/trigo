# Trigo — opdatering (v5): Robusthed, sikkerhed og ydeevne

Ingen database-ændringer. Ingen ændring af estimatoren (LSQ/track-fit).

## Filer der skal uploades (overskriv)

- `.htaccess` — blokerer direkte adgang til logfiler, `.sql`, `.md`,
  `db_config*.php` og `.git`. **Vigtigt:** `observation_log.txt`
  indeholder IP-adresser + positioner og kunne før hentes af alle.
- `save_data.php` — v5 (se nedenfor)
- `fetch_log.php` — tidszone-robust tidsvindue + `observed_ms`
- `dashboard.html` — v5
- `index.html`, `gun.html`, `sw.js` (cache v5)
- `simulator.php`, `sse_stream.php`, `getdata.php`

Tilføj evt. `define('SIMULATOR_KEY', '...');` i `db_config.php` — så
kræver `simulator.php` et `?key=...`, og fremmede kan ikke fylde
databasen med falske flyvninger. Uden konstanten virker den som før.

## Rettede fejl

- **Kompas 360° afvist:** telefonens afrunding kunne give 360°, som
  `save_data.php` afviste ("Ugyldig azimuth") — observationen gik tabt.
  Nu foldes azimut ind i [0, 360) på både klient og server.
- **gun.html sendte ingen `session_id`:** hver gun-pejling blev sin egen
  "bruger", så to pejlinger fra samme gun blev krydset med hinanden.
  Bruger nu samme `trigo_session_id` som `index.html`.
- **Dashboard én poll bagud:** service workeren cachede `fetch_log.php`
  (cache-first). PHP og andre domæner (tiles, CDN, Nominatim) går nu
  udenom SW-cachen, som heller ikke længere vokser med hver kort-tile.
- **Tidszone-forskydning:** `fetch_log.php` sammenlignede PHP-skrevne
  tider med MySQL `NOW()`. Er de to tidszoner forskellige, var vinduet
  forskudt med timer. Grænsen beregnes nu i PHP.
- **Safari:** kunne ikke parse `"YYYY-MM-DD HH:MM:SS"` → alle tider NaN.
  Dashboardet bruger nu `observed_ms` fra serveren.
- **Track-mode frøs dashboardet:** alle par inden for tidsvinduet gav
  titusindvis af rå krydsninger. Nu parres hver pejling kun med den
  tidsmæssigt nærmeste fra hver anden session, og maks. 200 tegnes.
  (900 pejlinger: før fryser siden, nu ~1 s.)
- **Tidsvinduet** styrer nu også hvor meget der hentes (før altid 5 min),
  og en ændring slår igennem med det samme.
- **Stored XSS:** klient-id og adresser blev indsat rå i dashboardets
  HTML. Id skal nu være numerisk (ellers genereres det på serveren), og
  al servertekst escapes.
- **Nominatim-belastning:** adressen genbruges fra samme session, når
  observatøren står inden for 100 m af sidste opslag. Dashboardets
  adresse-kø kører nu som én kø med fælles rate limit.
- **Mistede track-data på mobil:** `beforeunload` fyrer sjældent på
  mobil. Bufferen flushes nu også ved `visibilitychange`/`pagehide`.
- `sse_stream.php` lavede en ny prepared statement hvert 2. sek og
  holdt en PHP-worker for evigt; nu én statement og maks. 5 min pr.
  forbindelse (EventSource genforbinder selv).
- `getdata.php` accepterer kun POST ≤ 8 KB (disken kunne fyldes).

## Test efter upload

Som for v4 (simulator → dashboard, ~253 km/t, kurs ~54° NØ). Tjek
desuden at `https://<domæne>/observation_log.txt` giver 403.

---

# Trigo — opdatering (v4): Track-mode + rigtig track-estimering

Denne version er "den anden chance": den ændrer projektets fysik fra
enkelt-snapshots til kontinuerlige pejlestrømme, og fra rå parvise
krydsninger til en egentlig estimator der leverer det oprindelige løfte —
**position, højde, fart og kurs**.

## Filer der skal uploades (overskriv)

- `index.html` — observatør-app v4 (track-mode)
- `dashboard.html` — dashboard v4 (LSQ-fusion + track-fit)
- `save_data.php` — batch-understøttelse
- `sw.js` — network-first for HTML, cache v4

Ingen database-ændringer. `schema_migration.sql` fra v3 skal stadig være
kørt (session_id + address-kolonner).

## Nyt i observatør-appen

- **Kort tryk** på udløseren = én observation (som før).
- **Langt tryk (½ sek)** = TRACK-MODE: hold sigtekornet på objektet, og
  appen optager en pejling hver 0,8 sek. Rød pulserende knap + REC-badge
  med tæller. **Kort tryk stopper igen.**
- Observationer sendes i batches af 4 (og rest ved stop / sendBeacon ved
  luk), så serveren ikke hamres med enkelt-requests.
- 15 sekunders tracking ≈ 19 pejlinger. Det er dét, der gør estimatet
  robust over for kompasstøj (±5–15° på en telefon).

## Nyt i dashboardet

- **LSQ-fusion:** alle pejlinger samles i 5-sekunders tidsbins. Hvert bin
  med pejlinger fra ≥2 sessioner giver ét vægtet least-squares fix
  (punktet der minimerer de vinkelrette afstande til alle pejlelinjer).
  - Iterativ outlier-frasortering (kompas-spikes ryger ud).
  - IRLS-vægtning 1/afstand² — nære observatører vejer tungere.
  - Geometri-tjek: kræver ≥4° vinkelspredning, ellers intet fix.
- **Track-fit:** konstant-hastigheds-model over fixene (≥3 fixes over
  ≥8 sek) → **fart (km/t), kurs og stigning (m/min)** vises i et lilla
  track-kort i sidebaren, plus fremskrivnings-pil (60 sek) på kortet.
- Højde pr. fix: median af `obsAlt + afstand·tan(elevation)` med
  MAD-baseret støjundertrykkelse.
- De rå parvise krydsninger findes stadig (rødt lag), og den gamle
  "forbind krydsninger"-linje ligger som fravalgt lag ("Track (gml.
  metode)") til sammenligning.

## Valideret mod simulatoren-scenariet (Monte Carlo, 20 kørsler pr. række)

Helikopter Fanø → lufthavnen, sand fart 253,5 km/t, kurs 54,4°:

| Scenarie                  | Estimeret fart | Kurs-fejl |
|---------------------------|---------------|-----------|
| 3 observatører, ±2° støj  | 252 km/t      | 0,2°      |
| 2 observatører, ±5° støj  | 253 km/t      | 3,0°      |
| 3 observatører, ±8° + 5% spikes | 233 km/t | 1,1°     |
| 2 observatører, ±8° + 5% spikes | 219 km/t | 7,3°     |

Fit-RMS vises ærligt i track-kortet, så man kan se hvor meget estimatet
er værd.

## Test efter upload

1. Kør `simulator.php` i browseren (indsætter 3 min helikopter-flyvning
   med 3 sim-observatører).
2. Åbn `dashboard.html`, sæt tidsvinduet passende — det lilla track-kort
   skal vise ~250 km/t, kurs ~54° NØ, stigning ~+90 m/min.
3. På telefon: opdatér PWA (network-first SW gør det nu automatisk ved
   næste åbning med netforbindelse), prøv langt tryk på udløseren.

## Kendt begrænsning (næste skridt)

Estimatoren antager ét objekt ad gangen — realistisk for use-casen
(alle reagerer på det samme objekt), men to samtidige objekter vil
forvirre fixene. Næste naturlige skridt er spatial clustering af
pejlinger pr. bin før LSQ, så flere objekter kan adskilles.
