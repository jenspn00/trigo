# Flere samtidige objekter — prototype og måleresultater

Dette er et **eksperiment, ikke i drift**. `dashboard.html` kører stadig
enkelt-objekt-estimatoren fra v4. Ingen filer herfra skal uploades.

Formålet er den kendte begrænsning fra `INSTALL.md`:

> Estimatoren antager ét objekt ad gangen — to samtidige objekter vil
> forvirre fixene. Næste naturlige skridt er spatial clustering af
> pejlinger pr. bin før LSQ.

## Det viste sig at være to problemer, ikke ét

Klyngedeling pr. bin er kun halvdelen. Selv med perfekte klynger fitter
`fitTrack` stadig én ret linje gennem begge objekters fixes. Der skal
også en **kobling på tværs af bins**, så fixene fordeles på hvert sit
spor, før der fittes. Prototypen gør begge dele:

1. `binIntersections` — alle parvise skæringer i binet, kun på tværs af
   sessioner, kun foran begge observatører.
2. `clusterPoints` + `mergeNearby` — klyngedeling af skæringspunkterne.
   Sammensmeltningen er nødvendig: klyngedeling med fast radius splitter
   ellers ét objekt op i flere klynger, og så bliver én helikopter til to
   parallelle spor.
3. `clusterBin` — tildeler pejlinger til hver klynge og kaster
   spøgelsesmål væk på **højde-uenighed** (se nedenfor).
4. `computeFixesClustered` — ét LSQ-fix pr. klynge i stedet for ét pr. bin.
5. `buildTracks` — grådig nærmeste-nabo-kobling med port.
6. `mergeFragments` — samler spor der er knækket over.
7. `pruneTracks` — rangerer spor og kasserer spøgelser.

## Spøgelsesmål er hele vanskeligheden

Ser observatør 1 på objekt A og observatør 3 på objekt B, så skærer deres
to pejlelinjer også hinanden — i et punkt hvor der ikke er noget. Det er
et **spøgelsesmål**, og geometrisk er det lige så troværdigt som et ægte:
to linjer skærer altid hinanden i præcis ét punkt, så residualet er nul i
begge tilfælde.

Målt på ét tidsbin med 4 observatører (2 på hvert objekt) gav det:

```
Rå skæringer: 16 fordelt på fire observatør-par
  fano×saedding: 4   (ægte objekt A)
  jerne×storegade: 4 (ægte objekt B)
  fano×storegade: 4  (SPØGELSE)
  fano×jerne: 4      (SPØGELSE)
```

Spøgelserne er altså lige så mange som de ægte mål. To ting bruges til at
skille dem ad:

- **Højde-uenighed.** Kigger to observatører på hver sit objekt i hver sin
  højde, er deres elevationsvinkler uenige om højden i skæringspunktet.
  Det virker kun når objekterne faktisk flyver i forskellig højde.
- **Sessionsstøtte.** Et ægte mål set af 3 observatører bæres af 3
  skæringspar; et spøgelse opstår altid af præcis 2 linjer. Derfor
  rangeres spor efter sessionstal før sporlængde — ellers når et langt
  spøgelse frem før det ægte mål og lægger beslag på pejlingerne.

## Måleresultater

Monte Carlo, 25 kørsler pr. række, 180 sekunder, 5-sekunders bins.
Et objekt tæller som "fundet" kun hvis et spor ligger inden for 1200 m af
objektets sande position og rammer kursen inden for 30°. "Falske" spor er
spor, der ikke kunne matches til noget virkeligt objekt.

Kør selv: `node eksperimenter/multiobjekt/montecarlo.js`

### Ét objekt — må ikke blive dårligere end i dag

| Scenarie | Fundet | Fart (sand 256) | Kursfejl | Spor | Falske |
|---|---|---|---|---|---|
| 3 obs, ±3° | 100 % | 249 km/t | 0,9° | 1,2 | 0,2 |
| 3 obs, ±8° | 76 % | 243 km/t | 2,2° | 1,3 | 0,6 |

Ingen regression: ét objekt giver stadig ét spor med samme fart og kurs
som den nuværende estimator.

### To samtidige objekter

| Scenarie | A fundet | B fundet | Spor | Falske |
|---|---|---|---|---|
| 2+2 obs, ±3° | 84 % | 96 % | 3,2 | **1,4** |
| 2+2 obs, ±6° | 68 % | 92 % | 3,7 | **2,1** |
| 3+3 obs, ±3° | 96 % | 96 % | 3,3 | **1,4** |
| 3+3 obs, ±6° | 64 % | 100 % | 3,8 | **2,2** |
| 3 ser A, 1 ser B | 96 % | 36 % | 1,6 | 0,3 |

Begge objekter genfindes altså pålideligt med rigtig fart og kurs. **Men
der kommer 1-2 spøgelsesspor med.**

Bemærk sidste række: objekt B ses kun af én observatør og kan derfor slet
ikke fixes — de 36 % er spøgelser, der tilfældigvis lander nær B. Det er
præcis den slags falske fund, der er farlige.

### Variant med krav om 3 sessioner

Kræves der 3 sessioner bag et spor, når der er flere spor
(`pruneTracks(spor, {})` mod `{kraevTreVedFlere:false}`):

| Scenarie | A fundet | B fundet | Falske |
|---|---|---|---|
| 2+2 obs, ±3° | 52 % | 60 % | 0,9 |
| 3+3 obs, ±3° | 72 % | 84 % | 1,4 |
| 3 ser A, 1 ser B | 92 % | **0 %** | 0,2 |

Den umulige sag bliver rigtigt afvist (0 % i stedet for 36 %), men det
koster halvdelen af de ægte fund, fordi et objekt set af to observatører
kun har to sessioner bag sig.

## Hvorfor det ikke er sat i drift

Med to observatører pr. objekt er et spøgelse **matematisk ikke til at
skelne** fra et ægte mål ud fra geometri alene. Det er et kendt resultat i
bearings-only tracking, ikke en mangel ved koden. Højde-uenighed hjælper
kun når objekterne flyver i forskellig højde; flyver de i samme højde,
er der ingen information tilbage at skelne på.

Konsekvensen er, at et dashboard med denne kode ville vise 1-2 fly, der
ikke findes, hver gang der er to objekter i luften. Om det er en
forbedring i forhold til i dag — hvor to objekter i stedet giver ét
forvirret spor — er en vurdering af, hvad der er værst: at vise noget
forkert, eller at vise noget forvirret.

## Hvis den skal i drift

Anbefaling: sæt den ind bag et valg i dashboardet, med enkelt-objekt som
standard, og vis **sessionstallet pr. spor** i track-kortet, så et spor
båret af kun to sessioner kan ses som usikkert. Så er informationen der,
uden at spøgelserne præsenteres med samme vægt som ægte mål.

Det kræver desuden ændringer i `renderEstimate`, som i dag er skrevet til
ét spor: ét track-kort, én farve, én fremskrivningspil.
