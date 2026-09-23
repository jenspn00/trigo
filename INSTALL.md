# Trigo — opdatering (v5): Robusthed og ydeevne

Bygger oven på v4.2 nedenfor. Ingen database-ændringer. Ingen ændring af
estimatoren (LSQ/track-fit).

## Filer der skal uploades (overskriv)

- `.htaccess` — blokerer nu også `.ini`, `error_log`, `db_config*.php`,
  `helpers.php` og `.git`
- `save_data.php`, `fetch_log.php`, `dashboard.html`
- `index.html`, `gun.html`, `sw.js` (cache v5)
- `simulator.php`, `sse_stream.php`, `getdata.php`

Tilføj evt. `define('SIMULATOR_KEY', '...');` i `db_config.php` — så
kræver `simulator.php` et `?key=...`, og fremmede kan ikke fylde
databasen med falske flyvninger. Uden konstanten virker den som før.

## Løser v4.2's åbne punkt: 500-rækkers-loftet

Dashboardets rå krydsninger parres nu kun med den tidsmæssigt nærmeste
pejling fra hver anden session (lineært i stedet for O(n²)), og maks. 200
tegnes. Derfor er loftet i `fetch_log.php` hævet til **1500** — nok til tre
observatører i track-mode over hele 5-minutters-vinduet. Den gule
afkortningsnote vises stadig, hvis loftet rammes.
Målt lokalt med 900 track-mode-pejlinger: den gamle løkke frøs siden
(ingen render på 30 sek), nu ~1 sek.

## Øvrige rettelser

- **Kompas 360° afvist:** telefonens afrunding kunne give 360°, som
  `save_data.php` afviste ("Ugyldig azimuth") — observationen gik tabt.
  Nu foldes azimut ind i [0, 360) på både klient og server.
- **Dashboard én poll bagud:** service workeren cachede `fetch_log.php`
  (cache-first). PHP og andre domæner (fliser, CDN, Nominatim) går nu
  udenom SW-cachen, som heller ikke længere vokser med hver kortflise.
- **Stored XSS:** klient-id og adresser blev indsat rå i dashboardets
  HTML. Id skal nu være numerisk (ellers genereres det på serveren), og
  al servertekst escapes.
- **Tidsvinduet** styrer nu også hvor meget der hentes (før altid 5 min),
  og en ændring slår igennem med det samme.
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

Kør `simulator.php` (med `?key=...` hvis sat) → `dashboard.html` skal vise
~253 km/t, kurs ~54° NØ. Tjek at `observation_log.txt` giver 403.

---

# Trigo — opdatering (v4.2): Sikkerhed, tidszoner og session-id

Fire ting fra gennemgangen af projektet. To af dem var reelle fejl, der
ramte data; én var et hul, der lå åbent på nettet.

## 1. Log-filer lå offentligt tilgængelige (hastesag)

`save_data.php` skriver én linje pr. observation til `observation_log.txt`
med **IP-adresse + GPS-position + adresse**. Filen står i `.gitignore`, men
det holdt den kun ude af git — den kunne hentes af hvem som helst på
`https://trigo.industridata.dk/observation_log.txt`.

`.htaccess` blokerer nu `.txt`, `.log`, `.sql` og `.md` samt `db_config.php`
(sidstnævnte for det tilfælde, at PHP-handleren en dag fejler og filen
serveres som ren tekst med database-kodeordet i). `robots.txt` er undtaget.

**Tjek efter upload:** hent `https://trigo.industridata.dk/observation_log.txt`
i browseren. Du skal få 403 Forbidden. Gør du ikke det, honorerer serveren
ikke `.htaccess`, og filen skal i stedet flyttes uden for webroot.

**Ikke gjort:** selve IP-logningen er urørt. IP sammen med GPS-position er
personhenførbare data, så overvej om feltet overhovedet skal med i loggen —
men det er en beslutning om projektets dataindsamling, ikke en fejl.

## 2. `gun.html` sendte intet session_id

Gun-enheden sendte `id`, `timestamp`, `position` og `azimuth` — men aldrig
`session_id`. Rækkerne landede med `session_id = NULL`, og dashboardet falder
da tilbage til `row-<id>` pr. række. Hver eneste pejling blev altså sin egen
"observatør", og reglen om aldrig at krydse to pejlinger fra samme observatør
var sat ud af kraft: én gun-enhed alene kunne producere falske krydsninger og
falske LSQ-fixes ud af sine egne på hinanden følgende pejlinger.

`gun.html` bruger nu samme `localStorage`-nøgle som `index.html`, så telefon
og gun på samme browser tæller som én observatør.

## 3. Tidsstempler: Safari viste intet track

Dashboardet fik en nøgen DATETIME-streng ("2026-09-23 09:00:00") og kaldte
`new Date()` på den. Chrome tolker den som browserens lokaltid; **Safari kan
slet ikke parse formatet og giver Invalid Date**. På iPhone og iPad betød det,
at `computeFixes()` sprang hver eneste pejling over, så track-kortet forsvandt
helt — og samtidig faldt tidsvindue-filteret sammen, fordi `NaN > x` altid er
false, så alle par blev krydset uanset tid.

`fetch_log.php` leverer nu også `observed_at_ms` (epoch i millisekunder,
udregnet af MySQL). `observed_at` er bevaret uændret.

Samtidig er skrivesiden lagt om: `save_data.php` og `simulator.php` indsætter
via `FROM_UNIXTIME()` i stedet for PHP's `date()`. Før skrev PHP i sin
tidszone, mens læsesiden filtrerer med MySQL's `NOW()` i MySQL's tidszone —
og intet sted i projektet sætter nogen af dem. Var de forskellige, landede
rækkerne forskudt og faldt ud af 5-minutters-vinduet. Nu konverterer MySQL
både ind og ud, så de to følges ad uanset serverens opsætning.
(Tidszone-dumpet i `simulator.php` er stadig nyttigt — men bør nu vise det
samme hele vejen.)

## 4. Afkortning ved 500 rækker er ikke længere tavs

`fetch_log.php` henter højst 500 rækker. I track-mode sender én observatør en
pejling hvert 0,8 sek = 75 rækker/min, så **tre observatører fylder ~1125
rækker på standardvinduets 5 minutter**. Loftet rammes altså ved helt
almindelig brug — netop det scenarie simulatoren tester — og dashboardet
viste så kun de nyeste ~2 minutter uden at sige det. Det går ud over
track-fittet, som skal bruge historik for at måle fart og kurs.

Loftet står indtil videre, men `fetch_log.php` melder nu `truncated` tilbage,
og dashboardet viser en gul note i sidebaren med, hvor meget du faktisk ser.

**Ikke løst:** selve loftet. At hæve det alene gør ondt værre, fordi
dashboardets rå krydsningsløkke er O(n²) — 500 punkter er allerede 125.000
par pr. opdatering. Den rigtige løsning er at hæve loftet **og** samtidig
begrænse det røde "rå krydsninger"-lag, som alligevel kun er med til
sammenligning. Det er et bevidst valg om, hvad der skal være synligt, og
venter på en beslutning.

## Nyt: `schema.sql`

Der fandtes ingen `CREATE TABLE` i repoet — kun `schema_migration.sql`, som
`ALTER`er. Tabellen `observations` eksisterede altså kun på den kørende
server, og en frisk installation kunne ikke bygges op fra kildekoden.

`schema.sql` er **rekonstrueret ud fra de queries, koden kører** — ikke
dumpet fra databasen. Kør den ikke på den eksisterende database. Kør i stedet
`SHOW CREATE TABLE observations;` i phpMyAdmin og ret filen til, hvis der er
afvigelser. Derefter er den sandheden for nye installationer.

Bemærk også: `delete_object.php` sletter fra en tabel `tracked_objects`, som
intet andet i projektet opretter, skriver til eller læser fra. Findes den ikke
i databasen, er `delete_object.php` dødt kode.

## Filer der skal uploades (overskriv)

- `.htaccess` — blokerer log- og konfigurationsfiler
- `dashboard.html` — epoch-tidsstempler + afkortningsnote
- `fetch_log.php` — `observed_at_ms` + `truncated`
- `save_data.php` — `FROM_UNIXTIME()`
- `simulator.php` — `FROM_UNIXTIME()`
- `gun.html` — session_id

Ingen database-ændringer. `schema.sql` er kun til nye installationer.

---

# Trigo — opdatering (v4.1): Nyt baggrundskort

CARTO har lukket for anonym brug af `basemaps.cartocdn.com`. Fliserne kom
tilbage med “API KEY REQUIRED” brændt ind i selve billedet, så dashboardets
kort stod som et gråt gitter med tekst hen over. Intet andet var ramt —
Leaflet, fusionen, `fetch_log.php` og Nominatim-adresseopslaget bruger
ingen nøgle.

Vi skifter til **Esri “Dark Gray Canvas”**, som ikke kræver nøgle. Alternativet
var en gratis CARTO-nøgle, men den ville ligge i klartekst i `dashboard.html`,
som enhver kan hente fra webserveren.

## Filer der skal uploades (overskriv)

- `dashboard.html` — nyt baggrundskort

Ingen database-ændringer. Ingen `sw.js`-bump nødvendig: service workeren kører
network-first på HTML, så dashboardet henter den nye version ved næste
indlæsning.

## Detaljer værd at kende

- **Esri-URL'en har `{y}` før `{x}`** — modsat CARTO og OSM. Byttes de om,
  bliver kortet tomt uden fejlmeddelelse.
- **`maxNativeZoom: 16`.** Esri har kun fliser til zoom 16, men dashboardet
  zoomer til 17, når man klikker en krydsning i listen. Med `maxNativeZoom`
  skalerer Leaflet z16-flisen op (en anelse udtværet) i stedet for at vise
  et tomt kort.
- **Failsafe:** falder Esri også bort, skifter dashboardet automatisk til
  standard-OSM efter 4 fejlende fliser. Lyst kort mod mørk sidebar, men
  bedre end intet kort — og der står en linje i browserkonsollen.

## Test efter upload

Åbn `dashboard.html` og bekræft at kortet tegner Esbjerg/Fanø mørkegråt uden
tekst hen over. Klik en krydsning i sidebar-listen (zoom 17) og se at kortet
stadig har fliser. Attributionen nederst til højre skal nu sige “Esri”.

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
