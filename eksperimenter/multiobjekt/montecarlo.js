// =================================================================
//  Monte Carlo-prøvebænk for klyngedeling.js
//
//  Kør med:  node eksperimenter/multiobjekt/montecarlo.js
//
//  Læser lsqFix/fitTrack direkte ud af dashboard.html, så den altid
//  måler mod den kode der faktisk er i drift.
//
//  "falske" spor er spor som ikke kunne matches til et virkeligt
//  objekt — spøgelsesmål. Det er det tal der skal ned.
// =================================================================

const fs=require('fs');
const dash=fs.readFileSync(__dirname + '/../../dashboard.html','utf8');
function udsnit(a,b){ return dash.slice(dash.indexOf(a), dash.indexOf(b)); }
// Alt samles i EN eval, ellers laekker const ikke mellem scopes.
eval([
  udsnit('        const BIN_SEC = 5;','        // Del pejlinger op i tidsbins'),                                  // toENU/fromENU/median/lsqFix
  udsnit('        function computeFixes(','        // Konstant-hastigheds-fit'), // gammel, til sammenligning
  udsnit('        function fitTrack(','        function courseToCardinal('),     // uaendret
  fs.readFileSync(__dirname + '/klyngedeling.js','utf8'),                               // ny klyngedeling
].join('\n'));

const R=6371000,d2r=d=>d*Math.PI/180,r2d=r=>r*180/Math.PI;
const bearing=(a1,o1,a2,o2)=>{const dL=d2r(o2-o1);
  const y=Math.sin(dL)*Math.cos(d2r(a2));
  const x=Math.cos(d2r(a1))*Math.sin(d2r(a2))-Math.sin(d2r(a1))*Math.cos(d2r(a2))*Math.cos(dL);
  return (r2d(Math.atan2(y,x))+360)%360;};
const dist=(a1,o1,a2,o2)=>{const dLa=d2r(a2-a1),dLo=d2r(o2-o1);
  const x=Math.sin(dLa/2)**2+Math.cos(d2r(a1))*Math.cos(d2r(a2))*Math.sin(dLo/2)**2;
  return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));};

const OBS=[
  {lat:55.4411,lon:8.4036,s:'fano'},
  {lat:55.5055,lon:8.4087,s:'saedding'},
  {lat:55.4708,lon:8.4520,s:'storegade'},
  {lat:55.4900,lon:8.5200,s:'jerne'},
  {lat:55.4600,lon:8.5400,s:'novrup'},
  {lat:55.5200,lon:8.4600,s:'gjesing'},
];
// To objekter der flyver i hver sin retning gennem samme område
const OBJ=[
  {navn:'A', start:{lat:55.4594,lon:8.3881,alt:200}, slut:{lat:55.5262,lon:8.5532,alt:700}},
  {navn:'B', start:{lat:55.5300,lon:8.3800,alt:900}, slut:{lat:55.4500,lon:8.5600,alt:400}},
];
const DT=3, STEPS=60;   // 180 sek - realistisk fart

function sandt(o){
  const sp=dist(o.start.lat,o.start.lon,o.slut.lat,o.slut.lon)/(STEPS*DT)*3.6;
  return {fart:sp, kurs:bearing(o.start.lat,o.start.lon,o.slut.lat,o.slut.lon)};
}

// tildeling: hvilke observatører ser hvilket objekt
function lavPunkter(tildeling, stoj, nObj){
  const pts=[], t0=Date.now()-STEPS*DT*1000;
  for(let i=0;i<=STEPS;i++){
    const t=i/STEPS;
    OBJ.slice(0,nObj).forEach((o,oi)=>{
      const ol=o.start.lat+(o.slut.lat-o.start.lat)*t;
      const oo=o.start.lon+(o.slut.lon-o.start.lon)*t;
      const oa=o.start.alt+(o.slut.alt-o.start.alt)*t;
      OBS.forEach((ob,bi)=>{
        if(tildeling[bi]!==oi) return;
        const azi=(bearing(ob.lat,ob.lon,ol,oo)+(Math.random()*2-1)*stoj+360)%360;
        const dm=dist(ob.lat,ob.lon,ol,oo);
        const el=r2d(Math.atan2(oa-2,dm))+(Math.random()*2-1)*(stoj/2);
        pts.push({lat:ob.lat,lon:ob.lon,azi,time:t0+i*DT*1000,userId:ob.s,elev:el,obsAlt:2});
      });
    });
  }
  return pts;
}


// Sand position for objekt oi til tidspunkt-fraktion t
function sandPos(oi,t){const o=OBJ[oi];return{
  lat:o.start.lat+(o.slut.lat-o.start.lat)*t,
  lon:o.start.lon+(o.slut.lon-o.start.lon)*t};}

let OPTS={};
function evaluer(navn, tildeling, stoj, nObj, runs=25){
  const fundet=OBJ.slice(0,nObj).map(()=>[]), farter=OBJ.slice(0,nObj).map(()=>[]),
        kurser=OBJ.slice(0,nObj).map(()=>[]); const falske=[], ialt=[];
  for(let r=0;r<runs;r++){
    const pts=lavPunkter(tildeling,stoj,nObj);
    const t0=Math.min(...pts.map(p=>p.time)), t1=Math.max(...pts.map(p=>p.time));
    const spor=pruneTracks(mergeFragments(buildTracks(computeFixesClustered(pts))), OPTS);
    ialt.push(spor.length);
    const matchet=new Set();
    OBJ.slice(0,nObj).forEach((o,oi)=>{
      const s=sandt(o); let ok=false;
      spor.forEach((k,ki)=>{
        if(ok||matchet.has(ki))return;
        // sammenlign POSITION ved sporets sidste fix-tid
        const f=k.fixes[k.fixes.length-1];
        const frac=(f.time-t0)/(t1-t0);
        const sp=sandPos(oi,Math.max(0,Math.min(1,frac)));
        const afv=dist(sp.lat,sp.lon,f.lat,f.lon);
        const ke=Math.abs(((k.fit.course-s.kurs+540)%360)-180);
        if(afv<1200 && ke<30){ok=true;matchet.add(ki);
          farter[oi].push(k.fit.speed*3.6);kurser[oi].push(ke);}
      });
      fundet[oi].push(ok?1:0);
    });
    falske.push(spor.length-matchet.size);
  }
  const avg=a=>a.length?a.reduce((x,y)=>x+y,0)/a.length:NaN;
  console.log('  '+navn);
  OBJ.slice(0,nObj).forEach((o,i)=>{
    const d=avg(fundet[i])*100;
    console.log('    objekt '+o.navn+': fundet '+d.toFixed(0)+'%'
      +(farter[i].length?'  fart '+avg(farter[i]).toFixed(0)+' km/t (sand '+sandt(OBJ[i]).fart.toFixed(0)+')'
        +'  kursfejl '+avg(kurser[i]).toFixed(1)+'\u00b0':''));
  });
  console.log('    spor i alt: '+avg(ialt).toFixed(1)+'  heraf falske: '+avg(falske).toFixed(1));
}

for (const [maerke,o] of [['A: uden 3-sessions-kravet',{kraevTreVedFlere:false}],['B: MED 3-sessions-kravet ved flere spor',{}]]) {
OPTS=o;
console.log('##################  VARIANT '+maerke+'  ##################');
console.log('=== ET OBJEKT (regression) ===');
evaluer('3 obs ser A, +/-3 grader', [0,0,0,null,null,null], 3, 1);
evaluer('3 obs ser A, +/-8 grader', [0,0,0,null,null,null], 8, 1);
console.log();
console.log('=== TO SAMTIDIGE OBJEKTER ===');
evaluer('2+2 obs, +/-3 grader', [0,0,1,1,null,null], 3, 2);
evaluer('2+2 obs, +/-6 grader', [0,0,1,1,null,null], 6, 2);
evaluer('3 ser A, 1 ser B (B kan ikke fixes)', [0,0,0,1,null,null], 3, 2);
console.log();
console.log('=== TO OBJEKTER, TRE OBSERVAT\u00d8RER PR. OBJEKT ===');
evaluer('3+3 obs, +/-3 grader', [0,0,1,1,1,0], 3, 2);
evaluer('3+3 obs, +/-6 grader', [0,0,1,1,1,0], 6, 2);
console.log();
}
