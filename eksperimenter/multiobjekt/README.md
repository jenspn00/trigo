# Flere samtidige objekter — måleresultater

Denne mappe indeholder **prøvebænken og måletallene**. Selve algoritmen
ligger i `dashboard.html` bag tilvalget "Flere samtidige objekter", som
er slået FRA som standard.

Ingen filer herfra skal uploades til serveren.

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

## I drift som tilvalg

Koden ligger nu i `dashboard.html` bag afkrydsningsfeltet **"Flere
samtidige objekter"** under tidsvinduet. Standard er FRA, så dashboardet
opfører sig nøjagtig som hidtil for det almindelige tilfælde, hvor alle
kigger på det samme objekt. Valget huskes i `localStorage`.

Der ligger **ingen kopi af algoritmen i denne mappe**. Prøvebænken læser
alt — `lsqFix`, `computeFixes`, klyngedelingen og `fitTrack` — direkte ud
af `dashboard.html`, så den altid måler det, der faktisk kører. To kopier
ville drive fra hinanden, præcis som PHP- og JS-udgaven af
skæringsmatematikken allerede har gjort.

## Måleresultater

Monte Carlo, 40 kørsler pr. række, 180 sekunder, 5-sekunders bins.
Et objekt tæller som "fundet" kun hvis et spor ligger inden for 1200 m af
objektets sande position **og** rammer kursen inden for 30°. "Falske" spor
er spor, der ikke kunne matches til noget virkeligt objekt.

Kør selv: `node eksperimenter/multiobjekt/montecarlo.js`

Bemærk at tallene svinger mærkbart mellem kørsler — mere mellem to
kørsler af samme scenarie end mellem de to varianter nedenfor. Læs dem
som størrelsesordener, ikke som præcise værdier.

### Ét objekt — må ikke blive dårligere end i dag

| Scenarie | Fundet | Fart (sand 256) | Kursfejl | Spor | Falske |
|---|---|---|---|---|---|
| 3 obs, ±3° | 100 % | 253 km/t | 0,4° | 1,1 | 0,1 |
| 3 obs, ±8° | 78 % | 248 km/t | 2,7° | 1,3 | 0,5 |

Ingen regression. Og når tilvalget er slået FRA, røres denne kode slet
ikke — så kører `computeFixes` + `fitTrack` som før.

### To samtidige objekter (sendt konfiguration)

| Scenarie | A fundet | B fundet | Spor | Falske |
|---|---|---|---|---|
| 2+2 obs, ±3° | 95 % | 100 % | 4,5 | **2,6** |
| 2+2 obs, ±6° | 50 % | 98 % | 4,0 | **2,6** |
| 3+3 obs, ±3° | 88 % | 73 % | 3,6 | **2,0** |
| 3 ser A, 1 ser B | 93 % | **75 %** | 2,2 | 0,5 |

Begge objekter genfindes pålideligt med rigtig fart og kurs. Prisen er
2-3 spøgelsesspor.

Sidste række er den ubehagelige: objekt B ses kun af én observatør og kan
**ikke** fixes, men der vises alligevel et "B" i 75 % af kørslerne. Det er
ren opfindelse. Den slags spor bæres altid af kun to sessioner og får
derfor advarslen i sidebaren — det er hele grunden til, at den advarsel
findes.

### Fravalgt variant: krav om tre sessioner

`pruneTracks(spor, { kraevTreVedFlere: true })` kræver tre sessioner bag et
spor, når der er flere spor, og bruger et 2-sessions-spor kun hvis intet
spor har stærkere støtte:

| Scenarie | A fundet | B fundet | Falske |
|---|---|---|---|
| 2+2 obs, ±3° | **30 %** | 70 % | 0,9 |
| 3+3 obs, ±3° | 88 % | 70 % | 1,6 |
| 3 ser A, 1 ser B | 93 % | **0 %** | 0,3 |

Den afviser den umulige sag helt korrekt (0 % mod 75 %) og halverer
spøgelserne. Men den koster to tredjedele af de ægte fund i 2+2, fordi et
objekt set af to observatører kun har to sessioner bag sig — og et
spøgelse opsamler af og til en tredje session og udkonkurrerer dermed de
ægte mål. 30 % detektion gør funktionen ubrugelig i netop det tilfælde,
den er lavet til, og derfor er den ikke valgt.

Vil man hellere have færre falske spor end flere fund, er det én
parameter at ændre i `processData`.

## Den grænse der ikke kan kodes væk

Med to observatører pr. objekt er et spøgelse **matematisk ikke til at
skelne** fra et ægte mål ud fra geometri alene. Det er et kendt resultat i
bearings-only tracking, ikke en mangel ved koden. Højde-uenighed hjælper
kun når objekterne flyver i forskellig højde; flyver de i samme højde,
er der ingen information tilbage at skelne på.

Konsekvensen er, at dashboardet i denne tilstand viser 2-3 fly, der ikke
findes, hver gang der er to objekter i luften. Derfor er det et tilvalg
og ikke standard, og derfor bærer hvert usikkert spor en advarsel.

## Næste skridt, hvis det skal bedre

Spøgelserne kan ikke fjernes med mere geometri. Det der ville hjælpe er
mere information pr. pejling:

- **Bedre elevationsdata.** Højde-uenighed er den eneste rigtige skelnen
  vi har, og den er begrænset af elevationsstøjen. En gun-enhed med
  BNO055 måler elevation langt bedre end en telefon.
- **Lad observatøren mærke objektet.** Kan to observatører angive, at de
  kigger på *det samme* objekt (farve, type, et nummer i app'en), falder
  hele spøgelsesproblemet bort.
- **Flere observatører pr. objekt.** Tre er nok til at give ægte mål et
  reelt fortrin i støtte.
