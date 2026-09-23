// =================================================================
//  PROTOTYPE — flere samtidige objekter
//
//  Dette er IKKE i drift. dashboard.html kører stadig den
//  enkelt-objekt-estimator, der er beskrevet i INSTALL.md v4.
//
//  Filen løser den kendte begrænsning: ét objekt ad gangen.
//  Se README.md i denne mappe for måleresultater og for hvorfor
//  den ikke uden videre kan sættes i drift endnu.
//
//  Afhænger af lsqFix(), toENU(), fromENU() og median() fra
//  dashboard.html samt fitTrack() samme sted.
// =================================================================

// ============================================================
//  PROTOTYPE: spatial clustering pr. tidsbin + spor-kobling
// ============================================================

// --- 1. Parvise skæringer inde i ét bin (kun på tværs af sessioner) ---
function binIntersections(items) {
    const out = [];
    for (let i = 0; i < items.length; i++) {
        for (let j = i + 1; j < items.length; j++) {
            const A = items[i], B = items[j];
            if (A.b.userId === B.b.userId) continue;      // aldrig samme observatør
            const det = A.d.x * B.d.y - A.d.y * B.d.x;
            if (Math.abs(det) < 1e-6) continue;           // parallelle
            const rx = B.p.x - A.p.x, ry = B.p.y - A.p.y;
            const t = (rx * B.d.y - ry * B.d.x) / det;    // langs A
            const u = (rx * A.d.y - ry * A.d.x) / det;    // langs B
            if (t <= 0 || u <= 0) continue;               // bag om en observatør
            if (t > 100000 || u > 100000) continue;       // urimelig afstand
            out.push({ x: A.p.x + t * A.d.x, y: A.p.y + t * A.d.y, i, j, range: Math.min(t, u) });
        }
    }
    return out;
}

// --- 2. Greedy "leder"-klyngedeling: tættest befolkede punkt vinder ---
function clusterPoints(pts, eps) {
    const brugt = new Array(pts.length).fill(false);
    const klynger = [];
    while (true) {
        let bedst = -1, bedstAntal = 0;
        for (let i = 0; i < pts.length; i++) {
            if (brugt[i]) continue;
            let n = 0;
            for (let j = 0; j < pts.length; j++) {
                if (brugt[j]) continue;
                if (Math.hypot(pts[i].x - pts[j].x, pts[i].y - pts[j].y) <= eps) n++;
            }
            if (n > bedstAntal) { bedstAntal = n; bedst = i; }
        }
        if (bedst < 0) break;
        const med = [];
        for (let j = 0; j < pts.length; j++) {
            if (brugt[j]) continue;
            if (Math.hypot(pts[bedst].x - pts[j].x, pts[bedst].y - pts[j].y) <= eps) { med.push(pts[j]); brugt[j] = true; }
        }
        const cx = med.reduce((s, p) => s + p.x, 0) / med.length;
        const cy = med.reduce((s, p) => s + p.y, 0) / med.length;
        klynger.push({ x: cx, y: cy, n: med.length, punkter: med });
    }
    return klynger;
}

// --- 3. Klyngedel ét bin -> liste af pejle-delmængder ---
// SPØGELSESMÅL: krydser obs1's pejling mod objekt A og obs3's pejling mod
// objekt B hinanden, opstaar et falsk "mål". Med præcis to observatører
// pr. objekt er et spøgelse geometrisk lige så troværdigt som et ægte mål
// - to linjer skærer altid hinanden i præcis ét punkt, så residualet er
// nul i begge tilfaelde.
//
// Elevationsvinklen afgør sagen: kigger de to observatører på hver sit
// objekt i hver sin højde, er de UENIGE om højden i skæringspunktet.
// Et ægte mål giver enighed. Det er den eneste information der kan skille
// dem ad i et enkelt tidsbin, og den er allerede i data.
function clusterBin(bearings) {
    const lat0 = bearings.reduce((s, b) => s + b.lat, 0) / bearings.length;
    const lon0 = bearings.reduce((s, b) => s + b.lon, 0) / bearings.length;
    const cosLat0 = Math.cos(lat0 * Math.PI / 180);
    const items = bearings.map(b => {
        const th = b.azi * Math.PI / 180;
        return {
            b,
            p: { x: (b.lon - lon0) * cosLat0 * M_PER_DEG_LAT, y: (b.lat - lat0) * M_PER_DEG_LAT },
            d: { x: Math.sin(th), y: Math.cos(th) },
            n: { x: Math.cos(th), y: -Math.sin(th) }
        };
    });

    const kryds = binIntersections(items);
    if (kryds.length === 0) return [];

    const medRange = kryds.map(k => k.range).sort((a, b) => a - b)[Math.floor(kryds.length / 2)];
    const eps = Math.max(400, 0.18 * medRange);
    const gate = Math.max(500, eps * 1.3);

    // Klyngerne konkurrerer IKKE om pejlingerne. En pejling mod objekt A
    // kan indgaa i flere kandidater; det er sporkoblingen bagefter der
    // afgør hvilke kandidater der holder over tid.
    // Leder-klyngedeling med fast radius splitter gerne EET objekt i flere
    // klynger, fordi punkter lige uden for radius bliver til egen klynge.
    // En sammensmeltningsrunde samler dem igen; ægte objekter ligger
    // kilometer fra hinanden og smelter ikke sammen.
    const klynger = mergeNearby(clusterPoints(kryds, eps), eps * 2)
        .sort((a, b) => b.n - a.n);

    const resultat = [];
    const set = [];
    for (const k of klynger) {
        const valgt = [];
        for (const it of items) {
            const dx = k.x - it.p.x, dy = k.y - it.p.y;
            const s = it.d.x * dx + it.d.y * dy;
            if (s <= 0) continue;
            const r = Math.abs(it.n.x * dx + it.n.y * dy);
            if (r > gate) continue;
            valgt.push({ it, s });
        }
        const sessioner = new Set(valgt.map(v => v.it.b.userId));
        if (valgt.length < 2 || sessioner.size < 2) continue;

        // Højde-enighed pr. session: median af obsAlt + s*tan(elev)
        const perSession = new Map();
        for (const v of valgt) {
            const e = v.it.b.elev;
            if (isNaN(e) || Math.abs(e) >= 89) continue;
            const h = (v.it.b.obsAlt || 0) + v.s * Math.tan(e * Math.PI / 180);
            if (h < -200 || h > 30000) continue;
            if (!perSession.has(v.it.b.userId)) perSession.set(v.it.b.userId, []);
            perSession.get(v.it.b.userId).push(h);
        }
        if (perSession.size >= 2) {
            const sessionHøjder = [...perSession.values()].map(median);
            const hMin = Math.min(...sessionHøjder), hMax = Math.max(...sessionHøjder);
            const hMid = (hMin + hMax) / 2;
            // Tolerancen maa være rundhåndet: elevationsstøj gange
            // afstand giver hurtigt hundreder af meter.
            const tol = Math.max(250, 0.6 * Math.abs(hMid));
            if (hMax - hMin > tol) continue;             // uenige -> spøgelse
        }

        // Samme objekt maa ikke tælles to gange i samme bin: har denne
        // kandidat stort set de samme pejlinger som en allerede accepteret,
        // er det den samme ting set gennem en lidt anden klynge.
        const nøgler = new Set(valgt.map(v => v.it.b.userId + '@' + v.it.b.time));
        let dublet = false;
        for (const tidl of resultat) {
            const t = new Set(tidl.map(b => b.userId + '@' + b.time));
            let fælles = 0;
            for (const n of nøgler) if (t.has(n)) fælles++;
            if (fælles / Math.min(nøgler.size, t.size) > 0.7) { dublet = true; break; }
        }
        if (dublet) continue;
        if (set.some(o => Math.hypot(o.x - k.x, o.y - k.y) < eps * 0.75)) continue;
        set.push(k);
        resultat.push(valgt.map(v => v.it.b));
    }
    return resultat;
}

// Smelt klynger sammen hvis centroiderne ligger tæt
function mergeNearby(klynger, afstand) {
    const ud = [];
    for (const k of klynger) {
        const naer = ud.find(o => Math.hypot(o.x - k.x, o.y - k.y) <= afstand);
        if (naer) {
            const n = naer.n + k.n;
            naer.x = (naer.x * naer.n + k.x * k.n) / n;
            naer.y = (naer.y * naer.n + k.y * k.n) / n;
            naer.n = n;
        } else {
            ud.push({ x: k.x, y: k.y, n: k.n });
        }
    }
    return ud;
}

// --- 4. computeFixes: ét fix PR. KLYNGE pr. bin (før: ét pr. bin) ---
function computeFixesClustered(points) {
    const bins = new Map();
    for (const p of points) {
        if (isNaN(p.time)) continue;
        const key = Math.floor(p.time / (BIN_SEC * 1000));
        if (!bins.has(key)) bins.set(key, []);
        bins.get(key).push(p);
    }
    const fixes = [];
    for (const [key, arr] of [...bins.entries()].sort((a, b) => a[0] - b[0])) {
        if (new Set(arr.map(p => p.userId)).size < 2) continue;
        for (const delm of clusterBin(arr)) {
            const f = lsqFix(delm);
            if (f) fixes.push({ ...f, time: (key + 0.5) * BIN_SEC * 1000 });
        }
    }
    return fixes;
}

// --- 5. Kobl fixes på tværs af bins til separate spor ---
// Greedy nærmeste-nabo med port. Et spor med fart forudsiger hvor det bør
// være; et spor med ét fix kan kun gætte på "samme sted".
function buildTracks(fixes, opts = {}) {
    const MAX_HUL_MS = opts.maxGapMs ?? 20000;   // luk spor der ikke er set
    const lat0 = fixes.length ? fixes[0].lat : 0;
    const lon0 = fixes.length ? fixes[0].lon : 0;
    const cosLat0 = Math.cos(lat0 * Math.PI / 180);
    const enu = f => ({
        x: (f.lon - lon0) * cosLat0 * M_PER_DEG_LAT,
        y: (f.lat - lat0) * M_PER_DEG_LAT
    });

    const spor = [];
    for (const f of [...fixes].sort((a, b) => a.time - b.time)) {
        const p = enu(f);
        let bedst = null, bedstDist = -Infinity;
        for (const s of spor) {
            const dt = (f.time - s.sidsteTid) / 1000;
            if (dt <= 0 || f.time - s.sidsteTid > MAX_HUL_MS) continue;
            // Forudsig position ud fra sporets nuværende hastighed
            const px = s.sidste.x + (s.vx || 0) * dt;
            const py = s.sidste.y + (s.vy || 0) * dt;
            const d = Math.hypot(p.x - px, p.y - py);
            // Port: fart gange tid plus en fast slæk for fix-støj
            const fart = Math.hypot(s.vx || 0, s.vy || 0);
            const port = Math.max(800, fart * dt * 1.5 + 600);
            // Ved tvivl vinder det LAENGSTE spor, ikke blot det nærmeste.
            // Ellers vipper koblingen frem og tilbage og skærer ét objekt
            // op i parallelle stumper.
            if (d >= port) continue;
            const score = s.fixes.length * 1e6 - d;
            if (score > bedstDist) { bedstDist = score; bedst = s; }
        }
        if (bedst) {
            const dt = (f.time - bedst.sidsteTid) / 1000;
            // Glidende hastighedsestimat (simpel eksponentiel udjævning)
            const nyVx = (p.x - bedst.sidste.x) / dt;
            const nyVy = (p.y - bedst.sidste.y) / dt;
            bedst.vx = bedst.vx == null ? nyVx : 0.5 * bedst.vx + 0.5 * nyVx;
            bedst.vy = bedst.vy == null ? nyVy : 0.5 * bedst.vy + 0.5 * nyVy;
            bedst.sidste = p; bedst.sidsteTid = f.time; bedst.fixes.push(f);
        } else {
            spor.push({ fixes: [f], sidste: p, sidsteTid: f.time, vx: null, vy: null });
        }
    }
    return spor.map(s => s.fixes);
}

// --- 6. Fjern spøgelsesspor ---
// Et spøgelse laves af pejlinger der ALLEREDE er forklaret af et ægte
// spor. Vi rangerer sporene efter kvalitet, lader de bedste lægge beslag
// på deres pejlinger, og kasserer spor hvis pejlinger stort set alle
// er brugt i forvejen.
function pruneTracks(spor, opts = {}) {
    const MIN_FIXES = opts.minFixes ?? 4;
    const MIN_SPAN  = opts.minSpanSec ?? 15;
    const MAX_DELT  = opts.maxDelt ?? 0.45;  // hvor stor overlapning tolereres

    const nøgle = b => b.userId + '@' + b.time;

    const kandidater = spor
        .map(fixes => {
            if (fixes.length < MIN_FIXES) return null;
            const span = (fixes[fixes.length - 1].time - fixes[0].time) / 1000;
            if (span < MIN_SPAN) return null;
            const fit = fitTrack(fixes);
            if (!fit) return null;
            // Linearitet: et ægte objekt flyver lige, et spøgelse slingrer.
            const straekning = fit.speed * span;
            if (fit.rmsFit > Math.max(200, 0.3 * straekning)) return null;
            const pejlinger = new Set();
            for (const f of fixes) for (const b of (f.members || [])) pejlinger.add(nøgle(b));
            // RANGERING - afgørende for at slaa spøgelser ud.
            // Et ægte mål set af 3 observatører bæres af 3 pejlelinjer
            // (C(3,2)=3 skæringspar); et spøgelse opstaar altid af
            // præcis 2 linjer fra hver sit objekt. Sessionstallet er
            // derfor den stærkeste indikator vi har, og det skal veje
            // tungere end sporlængden - ellers når et langt spøgelse
            // frem før det ægte mål og lægger beslag på pejlingerne.
            const medSess = fixes.reduce((s, f) => s + f.nSessions, 0) / fixes.length;
            const score = medSess * 1e6 + fixes.length * 1e3 - fit.rmsFit;
            return { fixes, fit, pejlinger, medSess, score };
        })
        .filter(Boolean)
        .sort((a, b) => b.score - a.score);

    const brugt = new Set();
    const beholdt = [];
    for (const k of kandidater) {
        let delt = 0;
        for (const p of k.pejlinger) if (brugt.has(p)) delt++;
        if (k.pejlinger.size && delt / k.pejlinger.size > MAX_DELT) continue;

        // Samme objekt to gange? Overlapper sporet et allerede beholdt spor
        // i tid, og ligger de to inden for få hundrede meter af hinanden
        // på de fælles tidspunkter, er det den samme ting.
        const dublet = beholdt.some(b => sammeObjekt(b.fixes, k.fixes));
        if (dublet) continue;

        for (const p of k.pejlinger) brugt.add(p);
        beholdt.push(k);
    }

    // Når der kun er EET spor, er der intet spøgelse at forveksle det
    // med - så slippes det igennem uanset sessionstal (det er dagens
    // adfærd, og den maa ikke forringes). Er der FLERE spor, kan to af
    // dem være et spøgelsespar, og så kræves der tre sessioner bag.
    if (beholdt.length > 1 && opts.kraevTreVedFlere !== false) {
        const stærke = beholdt.filter(k => k.medSess >= 2.5);
        if (stærke.length) return stærke;
    }
    return beholdt;
}

// --- 7. Smelt spor-fragmenter sammen ---
// Sporkoblingen taber tråden når et fix støjer ud over porten, og saa
// starter der et nyt spor på det samme objekt. Her samles stumper hvis
// det ene slutter hvor det andet begynder - baade i tid og i kinematik.
function mergeFragments(spor, opts = {}) {
    const MAX_HUL = opts.maxGapMs ?? 25000;
    const M = M_PER_DEG_LAT;

    let liste = spor.map(f => [...f].sort((a, b) => a.time - b.time))
                    .filter(f => f.length);
    let ændret = true;
    while (ændret) {
        ændret = false;
        ydre:
        for (let i = 0; i < liste.length; i++) {
            for (let j = 0; j < liste.length; j++) {
                if (i === j) continue;
                const A = liste[i], B = liste[j];
                const aSlut = A[A.length - 1], bStart = B[0];
                const hul = bStart.time - aSlut.time;
                if (hul <= 0 || hul > MAX_HUL) continue;

                // A's hastighed ud fra dens to sidste fixes
                let vx = 0, vy = 0;
                if (A.length >= 2) {
                    const p = A[A.length - 2], dt = (aSlut.time - p.time) / 1000;
                    if (dt > 0) {
                        const cos = Math.cos(p.lat * Math.PI / 180);
                        vx = ((aSlut.lon - p.lon) * cos * M) / dt;
                        vy = ((aSlut.lat - p.lat) * M) / dt;
                    }
                }
                const dts = hul / 1000;
                const cos = Math.cos(aSlut.lat * Math.PI / 180);
                const fx = (bStart.lon - aSlut.lon) * cos * M - vx * dts;
                const fy = (bStart.lat - aSlut.lat) * M - vy * dts;
                const fart = Math.hypot(vx, vy);
                const port = Math.max(1000, fart * dts * 1.2 + 800);
                if (Math.hypot(fx, fy) > port) continue;

                liste[i] = A.concat(B);
                liste.splice(j, 1);
                ændret = true;
                break ydre;
            }
        }
    }
    return liste;
}

// To spor regnes som samme objekt hvis de overlapper i tid og ligger tæt
function sammeObjekt(a, b, tol = 1500) {
    const bVed = t => {
        let bedst = null, bd = Infinity;
        for (const f of b) { const d = Math.abs(f.time - t); if (d < bd) { bd = d; bedst = f; } }
        return bd <= 6000 ? bedst : null;
    };
    let fælles = 0, tæt = 0;
    for (const f of a) {
        const g = bVed(f.time);
        if (!g) continue;
        fælles++;
        const cos = Math.cos(f.lat * Math.PI / 180);
        const dx = (g.lon - f.lon) * cos * M_PER_DEG_LAT;
        const dy = (g.lat - f.lat) * M_PER_DEG_LAT;
        if (Math.hypot(dx, dy) < tol) tæt++;
    }
    return fælles >= 2 && tæt / fælles > 0.6;
}
