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
