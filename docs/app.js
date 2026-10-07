'use strict';

const MONTHS=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
const WDAYS=['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
const TONES=[
  {id:'chicle',name:'Chicle',h:352,c:0.2},
  {id:'palo',name:'Rosa palo',h:12,c:0.1},
  {id:'fucsia',name:'Fucsia',h:338,c:0.25},
  {id:'coral',name:'Coral',h:20,c:0.16},
  {id:'lila',name:'Lila rosa',h:322,c:0.15},
  {id:'frambuesa',name:'Frambuesa',h:2,c:0.22},
];
const BGS=[
  {id:'nube',name:'Nubes',css:'radial-gradient(circle at 12% 18%, var(--soft) 0, transparent 38%), radial-gradient(circle at 88% 12%, color-mix(in oklch, var(--accent) 35%, transparent) 0, transparent 34%), radial-gradient(circle at 70% 92%, var(--soft) 0, transparent 42%), var(--pale)'},
  {id:'lunares',name:'Lunares',css:'radial-gradient(var(--soft) 22%, transparent 23%) 0 0/40px 40px, radial-gradient(var(--soft) 22%, transparent 23%) 20px 20px/40px 40px, var(--pale)'},
  {id:'rayas',name:'Rayas caramelo',css:'repeating-linear-gradient(135deg, var(--pale) 0 26px, color-mix(in oklch, var(--soft) 70%, white) 26px 52px)'},
  {id:'vichy',name:'Vichy',css:'linear-gradient(90deg, color-mix(in oklch, var(--soft) 55%, transparent) 50%, transparent 50%) 0 0/48px 48px, linear-gradient(color-mix(in oklch, var(--soft) 55%, transparent) 50%, transparent 50%) 0 0/48px 48px, var(--pale)'},
  {id:'atardecer',name:'Atardecer',css:'linear-gradient(160deg, var(--pale) 0%, var(--soft) 50%, oklch(0.86 0.08 55) 100%)'},
  {id:'noche',name:'Noche rosa',css:'radial-gradient(circle at 18% 12%, color-mix(in oklch, var(--accent) 50%, transparent) 0, transparent 42%), radial-gradient(circle at 85% 88%, color-mix(in oklch, var(--accent) 40%, transparent) 0, transparent 48%), oklch(0.24 0.06 345)'},
];
const CATS=[
  {id:'trabajo',name:'Trabajo',h:345,c:0.19},
  {id:'personal',name:'Personal',h:12,c:0.15},
  {id:'salud',name:'Salud',h:322,c:0.15},
  {id:'cumple',name:'Cumple',h:0,c:0.22},
  {id:'casa',name:'Casa',h:28,c:0.13},
];
const REMS=[{v:0,name:'A la hora'},{v:15,name:'15 min antes'},{v:60,name:'1 hora antes'},{v:1440,name:'1 día antes'}];
const STORE='calendario_rosa_v1';
const DEFAULT_WA='+34 711 516 658';

const pad=n=>String(n).padStart(2,'0');
const keyOf=d=>`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
const parseKey=k=>{const [y,m,d]=k.split('-').map(Number);return new Date(y,m-1,d);};
const cap=s=>s.charAt(0).toUpperCase()+s.slice(1);
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const catC=c=>`oklch(0.66 ${c.c} ${c.h})`, catBg=c=>`oklch(0.93 ${(c.c*0.3).toFixed(3)} ${c.h})`, catInk=c=>`oklch(0.38 ${(c.c*0.7).toFixed(3)} ${c.h})`;
const toneVars=t=>({accent:`oklch(0.66 ${t.c} ${t.h})`,soft:`oklch(0.87 ${(t.c*0.42).toFixed(3)} ${t.h})`,deep:`oklch(0.42 ${(t.c*0.7).toFixed(3)} ${t.h})`,pale:`oklch(0.965 ${Math.min(0.02,t.c*0.1).toFixed(3)} ${t.h})`});
const catOf=id=>CATS.find(c=>c.id===id)||CATS[1];
const $=id=>document.getElementById(id);
const isTouch=window.matchMedia('(hover: none)').matches;

/* ---------- Estado ---------- */
let saved=null; try{saved=JSON.parse(localStorage.getItem(STORE)||'null');}catch(e){}
const now0=new Date();
const state={
  tasks: Array.isArray(saved?.tasks)?saved.tasks:[],
  tone: saved?.tone||'chicle', bg: saved?.bg||'nube', wa: saved?.wa??DEFAULT_WA,
  viewY: now0.getFullYear(), viewM: now0.getMonth(), sel: keyOf(now0),
  newCat:'personal', newRem:15,
  settingsOpen:false, toasts:[], celebrate:false,
  notif: typeof Notification==='undefined'?'na':Notification.permission,
};
let dragId=null, moveId=null, celT=null, deferredInstall=null;

function persist(){
  try{localStorage.setItem(STORE,JSON.stringify({tasks:state.tasks,tone:state.tone,bg:state.bg,wa:state.wa}));}catch(e){}
}
function setTasks(fn){ state.tasks=fn(state.tasks); persist(); render(); }

/* ---------- Utilidades ---------- */
function applyVars(){
  const t=TONES.find(x=>x.id===state.tone)||TONES[0], v=toneVars(t), r=document.documentElement.style;
  r.setProperty('--accent',v.accent); r.setProperty('--soft',v.soft); r.setProperty('--deep',v.deep); r.setProperty('--pale',v.pale);
  r.setProperty('--bg',(BGS.find(b=>b.id===state.bg)||BGS[0]).css);
  const meta=document.querySelector('meta[name=theme-color]'); if(meta) meta.setAttribute('content',v.accent);
}
function waDigits(){ let d=(state.wa||'').replace(/\D/g,''); if(d.length===9) d='34'+d; return d; }
function openWa(text){
  const url=`https://wa.me/${waDigits()}?text=${encodeURIComponent(text)}`;
  const w=window.open(url,'_blank'); if(!w) location.href=url;
}
function dayText(k){ const d=parseKey(k); return `${cap(WDAYS[d.getDay()])} ${d.getDate()} de ${MONTHS[d.getMonth()]}`; }
function taskMsg(t){ return `Recordatorio: ${t.title}\n${dayText(t.date)} a las ${t.time}`; }
function byDate(){
  const m={}; state.tasks.forEach(t=>{(m[t.date]=m[t.date]||[]).push(t);});
  Object.values(m).forEach(l=>l.sort((a,b)=>a.time.localeCompare(b.time)));
  return m;
}

/* ---------- Muñecos, cubo y adornos ---------- */
function mascot(kind,s,mood,delay=0){
  const ink='oklch(0.25 0.06 350)';
  const col= kind==='coni'?['oklch(0.985 0.01 350)','oklch(0.88 0.06 350)','oklch(0.72 0.1 350)']
    : kind==='gati'?['oklch(0.93 0.06 25)','oklch(0.78 0.12 18)','oklch(0.55 0.14 15)']
    : ['white','var(--soft)','var(--accent)'];
  const bodyBg=`radial-gradient(circle at 32% 26%, ${col[0]} 0%, ${col[1]} 34%, ${col[2]} 100%)`;
  let k=`<div style="position:absolute;left:15%;bottom:${-s*0.06}px;width:70%;height:${s*0.12}px;border-radius:50%;background:oklch(0.4 0.12 350 / 0.22);filter:blur(3px)"></div>`;
  if(kind==='coni'){
    [0.2,0.58].forEach((l,i)=>{ k+=`<div style="position:absolute;left:${s*l}px;top:${-s*0.3}px;width:${s*0.22}px;height:${s*0.52}px;border-radius:50%;background:${bodyBg};transform:rotate(${i?12:-12}deg);box-shadow:inset 0 0 0 ${s*0.05}px ${col[1]}"><div style="position:absolute;left:30%;top:15%;width:40%;height:60%;border-radius:50%;background:oklch(0.82 0.1 5)"></div></div>`; });
  }
  if(kind==='gati'){
    [0.12,0.58].forEach(l=>{ k+=`<div style="position:absolute;left:${s*l}px;top:0;width:${s*0.3}px;height:${s*0.3}px;border-radius:${s*0.06}px;background:${col[2]};transform:rotate(45deg)"></div>`; });
  }
  let f=''; const ey=s*0.36;
  [0.28,0.6].forEach((l,i)=>{
    if(mood==='happy') f+=`<div style="position:absolute;left:${s*l}px;top:${ey+s*0.03}px;width:${s*0.13}px;height:${s*0.07}px;border-top:${Math.max(2,s*0.04)}px solid ${ink};border-radius:50% 50% 0 0"></div>`;
    else f+=`<div style="position:absolute;left:${s*l}px;top:${ey}px;width:${s*0.11}px;height:${s*0.14}px;border-radius:50%;background:${ink};animation:blink ${4+i*0.01}s ${delay}s infinite"><div style="position:absolute;left:22%;top:14%;width:38%;height:34%;border-radius:50%;background:white"></div></div>`;
  });
  [0.14,0.7].forEach(l=>{ f+=`<div style="position:absolute;left:${s*l}px;top:${s*0.52}px;width:${s*0.15}px;height:${s*0.08}px;border-radius:50%;background:oklch(0.72 0.17 5 / 0.55)"></div>`; });
  f+= mood==='happy'
    ? `<div style="position:absolute;left:50%;top:${s*0.53}px;width:${s*0.18}px;height:${s*0.11}px;transform:translateX(-50%);background:${ink};border-radius:0 0 50% 50% / 0 0 100% 100%"></div>`
    : `<div style="position:absolute;left:50%;top:${s*0.5}px;width:${s*0.12}px;height:${s*0.07}px;transform:translateX(-50%);border-bottom:${Math.max(2,s*0.035)}px solid ${ink};border-radius:0 0 50% 50%"></div>`;
  k+=`<div style="position:absolute;left:0;top:${s*0.1}px;width:${s}px;height:${s*0.92}px;border-radius:50% 50% 46% 46% / 58% 58% 42% 42%;background:${bodyBg};box-shadow:inset ${-s*0.06}px ${-s*0.08}px ${s*0.16}px oklch(0.45 0.15 350 / 0.28), 0 ${s*0.06}px ${s*0.12}px -${s*0.04}px oklch(0.45 0.15 350 / 0.3)">${f}</div>`;
  return `<div style="position:relative;width:${s}px;height:${s*1.04}px;flex:none;animation:bob ${2.4+delay}s ease-in-out ${delay}s infinite">${k}</div>`;
}
function cube(size,faces,anim){
  const half=size/2;
  const tf=['rotateY(0deg)','rotateY(180deg)','rotateY(90deg)','rotateY(-90deg)','rotateX(90deg)','rotateX(-90deg)'];
  const bg=['linear-gradient(145deg, var(--accent), var(--deep))','linear-gradient(145deg, var(--accent), var(--deep))','linear-gradient(145deg, var(--soft), var(--accent))','linear-gradient(145deg, var(--soft), var(--accent))','linear-gradient(145deg, white, var(--soft))','linear-gradient(145deg, var(--accent), var(--deep))'];
  return `<div style="width:${size}px;height:${size}px;position:relative;transform-style:preserve-3d;animation:${anim||'spin3d 10s linear infinite'}">${
    tf.map((t,i)=>`<div style="position:absolute;inset:0;transform:${t} translateZ(${half}px);background:${bg[i]};border-radius:${size*0.12}px;border:2px solid rgba(255,255,255,0.55);display:flex;align-items:center;justify-content:center;font-family:Fredoka,sans-serif;font-weight:700;color:${i===4?'var(--deep)':'white'};font-size:${i<2?size*0.46:size*0.24}px;line-height:1;backface-visibility:hidden">${esc(faces?.[i]??'')}</div>`).join('')
  }</div>`;
}
function buildFloaters(){
  const sphere=s=>`<div style="width:${s}px;height:${s}px;border-radius:50%;background:radial-gradient(circle at 32% 28%, white 0%, var(--soft) 22%, var(--accent) 62%, var(--deep) 100%);box-shadow:0 ${s*0.3}px ${s*0.4}px -${s*0.2}px oklch(0.4 0.14 350 / 0.45)"></div>`;
  const donut=s=>`<div style="perspective:400px"><div style="width:${s}px;height:${s}px;border-radius:50%;background:radial-gradient(circle, transparent 30%, var(--deep) 31%, var(--accent) 40%, var(--soft) 50%, var(--accent) 60%, var(--deep) 69%, transparent 70%);animation:tumble 9s linear infinite;filter:drop-shadow(0 12px 14px oklch(0.4 0.14 350 / 0.35))"></div></div>`;
  const items=[
    {t:'sphere',x:'5%',y:'16%',s:72,d:7},{t:'donut',x:'93%',y:'24%',s:120,d:9},{t:'cube',x:'4%',y:'80%',s:54,d:11},
    {t:'sphere',x:'95%',y:'74%',s:46,d:6},{t:'donut',x:'50%',y:'96%',s:84,d:10},{t:'sphere',x:'62%',y:'3%',s:30,d:5},
  ];
  $('floaters').innerHTML=items.map((it,i)=>`<div style="position:absolute;left:${it.x};top:${it.y};transform:translate(-50%,-50%);perspective:500px"><div style="animation:floaty ${it.d}s ease-in-out ${-i*1.3}s infinite">${
    it.t==='sphere'?sphere(it.s):it.t==='donut'?donut(it.s):cube(it.s,null,'tumble 12s linear infinite')}</div></div>`).join('');
}
function burst(x,y){
  const fx=$('fx'); if(!fx) return;
  const cols=['var(--accent)','var(--soft)','var(--deep)','white','oklch(0.86 0.1 60)'];
  for(let i=0;i<40;i++){
    const d=document.createElement('div'), s=6+Math.random()*8;
    d.style.cssText=`position:absolute;left:${x}px;top:${y}px;width:${s}px;height:${s*(Math.random()<.5?1:1.9)}px;background:${cols[i%cols.length]};border-radius:${Math.random()<.4?'50%':'3px'};box-shadow:0 2px 4px rgba(120,0,60,.2)`;
    fx.appendChild(d);
    if(!d.animate){ setTimeout(()=>d.remove(),50); continue; }
    const a=Math.random()*Math.PI*2, dist=70+Math.random()*190, dx=Math.cos(a)*dist, dy=Math.sin(a)*dist-90;
    const an=d.animate([
      {transform:'translate3d(0,0,0) rotate3d(1,1,0,0deg)',opacity:1},
      {transform:`translate3d(${dx*0.8}px,${dy}px,${(Math.random()-.5)*200}px) rotate3d(${Math.random()},${Math.random()},1,${360*Math.random()+180}deg)`,opacity:1,offset:0.45},
      {transform:`translate3d(${dx}px,${dy+220}px,${(Math.random()-.5)*300}px) rotate3d(${Math.random()},${Math.random()},1,${720+360*Math.random()}deg)`,opacity:0}
    ],{duration:1200+Math.random()*700,easing:'cubic-bezier(.2,.7,.4,1)'});
    an.onfinish=()=>d.remove();
  }
}
function cheer(){ clearTimeout(celT); state.celebrate=true; renderHeader(); celT=setTimeout(()=>{state.celebrate=false;renderHeader();},2600); }

/* ---------- Render ---------- */
let lastView=null;
function renderHeader(){
  const today=new Date(), todayKey=keyOf(today), m=byDate();
  const todayList=m[todayKey]||[], pending=todayList.filter(t=>!t.done).length;
  const allDone=todayList.length>0&&pending===0;
  const mood=state.celebrate||allDone?'happy':'calm';
  $('mascotsHeader').innerHTML=mascot('mochi',52,mood,0)+mascot('coni',42,mood,0.4)+mascot('gati',38,mood,0.8);
  $('speech').textContent= state.celebrate?'¡Bien hecho! Una tarea menos.'
    : todayList.length===0?'Hoy no tienes tareas. Día libre.'
    : allDone?'¡Has terminado todo lo de hoy!'
    : `Hoy tienes ${todayList.length} ${todayList.length===1?'tarea':'tareas'}, te ${pending===1?'queda':'quedan'} ${pending}.`;
  $('monthLabel').textContent=cap(MONTHS[state.viewM]);
  $('yearLabel').textContent=String(state.viewY);
}
function renderGrid(m){
  const todayKey=keyOf(new Date());
  const first=new Date(state.viewY,state.viewM,1), offset=(first.getDay()+6)%7, dim=new Date(state.viewY,state.viewM+1,0).getDate();
  const total=Math.ceil((offset+dim)/7)*7; let html='';
  for(let i=0;i<total;i++){
    const d=new Date(state.viewY,state.viewM,1-offset+i), k=keyOf(d), list=m[k]||[];
    const sel=k===state.sel, isT=k===todayKey, inM=d.getMonth()===state.viewM;
    const bg= sel?'linear-gradient(150deg, var(--accent), var(--deep))':inM?'rgba(255,255,255,0.88)':'rgba(255,255,255,0.4)';
    const border= isT?(sel?'white':'var(--accent)'):'transparent';
    const shadow= sel?'0 18px 30px -14px var(--deep)':'0 4px 10px -8px var(--deep)';
    const numBg= isT&&!sel?'var(--accent)':sel?'rgba(255,255,255,0.22)':'transparent';
    const numColor= isT||sel?'white':inM?'var(--ink)':'oklch(0.55 0.05 350)';
    const chips=list.slice(0,2).map(t=>{const c=catOf(t.cat);return `<div class="chip" draggable="true" data-drag="${esc(t.id)}" style="background:${sel?'rgba(255,255,255,0.92)':catBg(c)};color:${catInk(c)};text-decoration:${t.done?'line-through':'none'}">${esc(t.title)}</div>`;}).join('');
    const dots=list.slice(0,4).map(t=>`<span class="dot" style="background:${sel?'white':catC(catOf(t.cat))};opacity:${t.done?0.45:1}"></span>`).join('');
    html+=`<div class="cell" data-key="${k}" data-inm="${inM?1:0}" role="button" tabindex="0" aria-label="${esc(dayText(k))}${list.length?`, ${list.length} tareas`:''}" style="background:${bg};border-color:${border};box-shadow:${shadow}">
      <div class="cell-top"><span class="num" style="background:${numBg};color:${numColor}">${d.getDate()}</span>${list.length>2?`<span class="more" style="color:${sel?'white':'var(--deep)'}">+${list.length-2}</span>`:''}</div>
      ${chips}${dots?`<div class="dots">${dots}</div>`:''}</div>`;
  }
  const grid=$('grid'); grid.innerHTML=html;
  const view=state.viewY*12+state.viewM;
  if(lastView!==null&&lastView!==view&&grid.animate){
    const dir=view>lastView?1:-1;
    grid.animate([
      {transform:`perspective(1100px) rotateY(${-dir*70}deg) translateX(${dir*60}px)`,opacity:0},
      {transform:'perspective(1100px) rotateY(0deg) translateX(0)',opacity:1}
    ],{duration:620,easing:'cubic-bezier(.2,.9,.3,1.15)'});
  }
  lastView=view;
}
function renderDay(m){
  const selD=parseKey(state.sel), list=m[state.sel]||[], doneN=list.filter(t=>t.done).length;
  $('cubeBox').innerHTML=cube(64,[selD.getDate(),selD.getDate(),cap(MONTHS[selD.getMonth()].slice(0,3)),cap(WDAYS[selD.getDay()].slice(0,3)),'♥','']);
  $('selLabel').textContent=`${cap(WDAYS[selD.getDay()])}, ${selD.getDate()} de ${MONTHS[selD.getMonth()]}`;
  $('selShort').textContent=`Para el ${selD.getDate()} de ${MONTHS[selD.getMonth()]}`;
  $('selSummary').textContent= list.length?`${list.length} ${list.length===1?'tarea':'tareas'} · ${doneN} ${doneN===1?'hecha':'hechas'}`:'Sin tareas';
  $('sendDayBtn').hidden=!list.length;
  $('progressBar').style.width= list.length?`${Math.round(doneN/list.length*100)}%`:'0%';
  $('taskList').innerHTML= list.length ? list.map(t=>{
    const c=catOf(t.cat), id=esc(t.id);
    return `<div class="task" draggable="true" data-drag="${id}">
      <button class="check" data-action="toggle" data-id="${id}" aria-label="${t.done?'Marcar pendiente':'Marcar hecha'}" style="border-color:${catC(c)};background:${t.done?catC(c):'white'}">${t.done?'✓':''}</button>
      <div class="task-body">
        <div class="task-title" style="text-decoration:${t.done?'line-through':'none'}">${esc(t.title)}</div>
        <div class="tags">
          <span class="tag" style="background:var(--pale);color:var(--deep)">${esc(t.time)}</span>
          <span class="tag" style="background:${catBg(c)};color:${catInk(c)}">${esc(c.name)}</span>
          <span class="tag" style="background:oklch(0.96 0.01 350);color:var(--ink)">${esc((REMS.find(r=>r.v===t.rem)||REMS[0]).name)}</span>
        </div>
      </div>
      <div class="task-btns">
        <button class="wa-task" data-action="wa-task" data-id="${id}" title="Enviar aviso por WhatsApp">WhatsApp</button>
        <button class="sq-btn" data-action="move" data-id="${id}" title="Mover a otro día" aria-label="Mover a otro día">📅</button>
        <button class="sq-btn" data-action="delete" data-id="${id}" title="Borrar" aria-label="Borrar">✕</button>
      </div>
    </div>`;}).join('')
  : `<div class="empty"><div style="flex:none">${mascot('coni',64,'happy',0.2)}</div><div style="display:flex;flex-direction:column;gap:4px"><div class="empty-title">Día libre</div><div class="empty-text">Añade una tarea abajo${isTouch?'.':' o arrastra una desde otro día.'}</div></div></div>`;
}
function renderForm(){
  $('catOpts').innerHTML=CATS.map(c=>{const on=c.id===state.newCat;return `<button class="opt" data-action="pick-cat" data-id="${c.id}" aria-pressed="${on}" style="border-color:${on?catC(c):'oklch(0.92 0.02 350)'};background:${on?catBg(c):'white'};color:${on?catInk(c):'var(--ink)'}"><span class="odot" style="background:${catC(c)}"></span>${c.name}</button>`;}).join('');
  $('remOpts').innerHTML=REMS.map(r=>{const on=r.v===state.newRem;return `<button class="opt" data-action="pick-rem" data-id="${r.v}" aria-pressed="${on}" style="border-color:${on?'var(--accent)':'oklch(0.92 0.02 350)'};background:${on?'var(--pale)':'white'};color:${on?'var(--deep)':'var(--ink)'}">${r.name}</button>`;}).join('');
}
function renderToasts(){
  $('toasts').innerHTML=state.toasts.map(n=>`<div class="toast">
    <div class="toast-m">${mascot('mochi',48,'calm',0)}</div>
    <div class="toast-b">
      <div class="toast-k">Recordatorio</div>
      <div class="toast-t">${esc(n.task.title)}</div>
      <div class="toast-w">${esc(dayText(n.task.date))} a las ${esc(n.task.time)}</div>
      <div class="toast-btns">
        <button class="wa-strong" data-action="toast-wa" data-id="${esc(n.id)}">Enviar a mi WhatsApp</button>
        <button class="soft-btn" data-action="toast-close" data-id="${esc(n.id)}">Cerrar</button>
      </div>
    </div></div>`).join('');
}
function renderModal(){
  $('modal').hidden=!state.settingsOpen;
  if(!state.settingsOpen) return;
  $('toneOpts').innerHTML=TONES.map(t=>{const v=toneVars(t);return `<button class="tone" data-action="pick-tone" data-id="${t.id}" style="border-color:${t.id===state.tone?v.accent:'oklch(0.94 0.015 350)'}"><span class="swatch" style="background:radial-gradient(circle at 32% 28%, white 0%, ${v.soft} 24%, ${v.accent} 62%, ${v.deep} 100%);box-shadow:0 10px 16px -8px ${v.deep}"></span><span class="tone-name">${t.name}</span></button>`;}).join('');
  $('bgOpts').innerHTML=BGS.map(b=>`<button class="bgopt" data-action="pick-bg" data-id="${b.id}"><span class="bg-prev" style="background:${b.css};border-color:${b.id===state.bg?'var(--accent)':'white'}"></span><span class="bg-name">${b.name}</span></button>`).join('');
  const wa=$('waInput'); if(document.activeElement!==wa) wa.value=state.wa;
  renderWaDisplay();
  $('notifBtn').textContent= state.notif==='granted'?'Notificaciones activadas':state.notif==='denied'?'Notificaciones bloqueadas en el navegador':state.notif==='na'?'Notificaciones no disponibles en este navegador':'Activar notificaciones';
  $('installSec').hidden=!deferredInstall;
}
function renderWaDisplay(){ const d=waDigits(); $('waDisplay').textContent=d?'+'+d:'—'; }
function render(){
  const m=byDate();
  renderHeader(); renderGrid(m); renderDay(m); renderForm(); renderToasts(); renderModal();
}

/* ---------- Acciones ---------- */
function addTask(el){
  const titleEl=$('newTitle'), title=titleEl.value.trim();
  if(!title){ titleEl.focus(); return; }
  const time=$('newTime').value||'10:00';
  const at=new Date(`${state.sel}T${time}`).getTime();
  const t={id:'t'+Date.now()+Math.random().toString(36).slice(2,6),title,date:state.sel,time,cat:state.newCat,rem:state.newRem,done:false,notified:at<Date.now()};
  titleEl.value='';
  setTasks(ts=>[...ts,t]);
  if(el?.getBoundingClientRect){const r=el.getBoundingClientRect();burst(r.left+r.width/2,r.top+r.height/2);}
  cheer();
}
function moveTask(id,k){
  if(!id||!k) return;
  state.sel=k;
  const d=parseKey(k); state.viewY=d.getFullYear(); state.viewM=d.getMonth();
  setTasks(ts=>ts.map(t=>t.id===id?{...t,date:k,notified:false}:t));
}
function selectDay(k,inM){
  state.sel=k;
  if(!inM){ const d=parseKey(k); state.viewY=d.getFullYear(); state.viewM=d.getMonth(); }
  render();
  if(window.innerWidth<=700) $('dayPanel').scrollIntoView({behavior:'smooth',block:'start'});
}
function shiftMonth(n){ const d=new Date(state.viewY,state.viewM+n,1); state.viewY=d.getFullYear(); state.viewM=d.getMonth(); render(); }

async function notify(t){
  if(typeof Notification==='undefined'||Notification.permission!=='granted') return;
  const title='Recordatorio: '+t.title, opts={body:`${dayText(t.date)} a las ${t.time}`,icon:'icons/icon-192.png',badge:'icons/icon-192.png',tag:t.id};
  try{
    const reg=navigator.serviceWorker&&await navigator.serviceWorker.getRegistration();
    if(reg){ await reg.showNotification(title,opts); return; }
  }catch(e){}
  try{ new Notification(title,opts); }catch(e){}
}
function check(){
  const now=Date.now(), fresh=[]; let changed=false;
  const tasks=state.tasks.map(t=>{
    if(t.notified||t.done) return t;
    const at=new Date(`${t.date}T${t.time}`).getTime(), remAt=at-(t.rem||0)*60000;
    if(now>=remAt){ changed=true; if(now-at<6*3600e3) fresh.push(t); return {...t,notified:true}; }
    return t;
  });
  if(!changed) return;
  fresh.forEach(notify);
  state.toasts=[...state.toasts,...fresh.map(t=>({id:t.id+'_'+now,task:t}))].slice(-3);
  state.tasks=tasks; persist(); render();
}

function exportData(){
  const blob=new Blob([JSON.stringify({app:'calendario-rosa',version:1,exported:new Date().toISOString(),tasks:state.tasks,tone:state.tone,bg:state.bg,wa:state.wa},null,2)],{type:'application/json'});
  const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=`calendario-rosa-${keyOf(new Date())}.json`;
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(a.href),1000);
}
function importData(file){
  const r=new FileReader();
  r.onload=()=>{
    try{
      const d=JSON.parse(r.result);
      if(!Array.isArray(d.tasks)) throw new Error('sin tareas');
      const valid=d.tasks.filter(t=>t&&typeof t.title==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(t.date)&&/^\d{2}:\d{2}$/.test(t.time));
      const mode=confirm(`La copia tiene ${valid.length} tareas.\n\nAceptar = juntarlas con las que ya tienes.\nCancelar = no cargar nada.`);
      if(!mode) return;
      const ids=new Set(state.tasks.map(t=>t.id));
      const merged=[...state.tasks,...valid.filter(t=>!ids.has(t.id)).map(t=>({id:String(t.id||'i'+Math.random().toString(36).slice(2)),title:t.title,date:t.date,time:t.time,cat:CATS.some(c=>c.id===t.cat)?t.cat:'personal',rem:REMS.some(x=>x.v===t.rem)?t.rem:0,done:!!t.done,notified:!!t.notified}))];
      if(TONES.some(x=>x.id===d.tone)) state.tone=d.tone;
      if(BGS.some(x=>x.id===d.bg)) state.bg=d.bg;
      if(typeof d.wa==='string'&&d.wa) state.wa=d.wa;
      applyVars(); setTasks(()=>merged);
      alert('Copia cargada.');
    }catch(e){ alert('Ese archivo no es una copia válida del calendario.'); }
  };
  r.readAsText(file);
}

/* ---------- Eventos ---------- */
document.addEventListener('click',e=>{
  const el=e.target.closest('[data-action],.cell'); if(!el) return;
  if(el.classList.contains('cell')){ selectDay(el.dataset.key,el.dataset.inm==='1'); return; }
  const a=el.dataset.action, id=el.dataset.id;
  if(a==='close-settings'&&el.id==='modal'&&e.target!==el) return;
  const find=()=>state.tasks.find(t=>t.id===id);
  switch(a){
    case 'prev': shiftMonth(-1); break;
    case 'next': shiftMonth(1); break;
    case 'today': { const t=new Date(); state.viewY=t.getFullYear(); state.viewM=t.getMonth(); state.sel=keyOf(t); render(); break; }
    case 'open-settings': state.settingsOpen=true; renderModal(); break;
    case 'close-settings': state.settingsOpen=false; renderModal(); break;
    case 'send-day': { const list=byDate()[state.sel]||[]; openWa(`Tareas del ${dayText(state.sel)}:\n`+list.map(t=>`${t.done?'✓':'•'} ${t.time} ${t.title}`).join('\n')); break; }
    case 'toggle': { const t=find(); if(!t) break; if(!t.done){ burst(e.clientX||innerWidth/2,e.clientY||innerHeight/2); cheer(); } setTasks(ts=>ts.map(x=>x.id===id?{...x,done:!x.done}:x)); break; }
    case 'delete': { const t=find(); if(t&&confirm(`¿Borrar «${t.title}»?`)) setTasks(ts=>ts.filter(x=>x.id!==id)); break; }
    case 'wa-task': { const t=find(); if(t) openWa(taskMsg(t)); break; }
    case 'move': {
      const t=find(); if(!t) break; moveId=id; const inp=$('moveDate'); inp.value=t.date;
      try{ if(inp.showPicker){ inp.showPicker(); break; } }catch(err){}
      const v=prompt('Mover a la fecha (AAAA-MM-DD):',t.date);
      if(v&&/^\d{4}-\d{2}-\d{2}$/.test(v)) moveTask(id,v);
      break;
    }
    case 'pick-cat': state.newCat=id; renderForm(); break;
    case 'pick-rem': state.newRem=Number(id); renderForm(); break;
    case 'add': addTask(el); break;
    case 'toast-wa': { const n=state.toasts.find(x=>x.id===id); if(n) openWa(taskMsg(n.task)); state.toasts=state.toasts.filter(x=>x.id!==id); renderToasts(); break; }
    case 'toast-close': state.toasts=state.toasts.filter(x=>x.id!==id); renderToasts(); break;
    case 'pick-tone': state.tone=id; persist(); applyVars(); render(); break;
    case 'pick-bg': state.bg=id; persist(); applyVars(); renderModal(); break;
    case 'test-wa': openWa('¡Hola! Así te llegarán los avisos de tu calendario rosa.'); break;
    case 'ask-notif':
      if(typeof Notification==='undefined') break;
      Notification.requestPermission().then(p=>{state.notif=p;renderModal();});
      break;
    case 'export': exportData(); break;
    case 'import': $('importFile').click(); break;
    case 'install':
      if(deferredInstall){ deferredInstall.prompt(); deferredInstall.userChoice.finally(()=>{deferredInstall=null;renderModal();}); }
      break;
  }
});
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'&&state.settingsOpen){ state.settingsOpen=false; renderModal(); }
  const cell=e.target.closest?.('.cell');
  if(cell&&(e.key==='Enter'||e.key===' ')){ e.preventDefault(); selectDay(cell.dataset.key,cell.dataset.inm==='1'); }
});
$('newTitle').addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); addTask(e.currentTarget); } });
$('waInput').addEventListener('input',e=>{ state.wa=e.target.value; persist(); renderWaDisplay(); });
$('importFile').addEventListener('change',e=>{ const f=e.target.files?.[0]; if(f) importData(f); e.target.value=''; });
$('moveDate').addEventListener('change',e=>{ if(moveId&&e.target.value) moveTask(moveId,e.target.value); moveId=null; });

// Arrastrar y soltar (ordenador)
document.addEventListener('dragstart',e=>{
  const el=e.target.closest?.('[data-drag]'); if(!el) return;
  dragId=el.dataset.drag; e.dataTransfer.effectAllowed='move';
  try{ e.dataTransfer.setData('text/plain',dragId); }catch(err){}
});
document.addEventListener('dragover',e=>{
  const c=e.target.closest?.('.cell'); if(!c||!dragId) return;
  e.preventDefault();
  document.querySelectorAll('.cell.drop').forEach(x=>x!==c&&x.classList.remove('drop')); c.classList.add('drop');
});
document.addEventListener('drop',e=>{
  const c=e.target.closest?.('.cell'); if(!c) return;
  e.preventDefault(); moveTask(dragId,c.dataset.key); dragId=null;
});
document.addEventListener('dragend',()=>{ dragId=null; document.querySelectorAll('.cell.drop').forEach(x=>x.classList.remove('drop')); });

// Inclinación 3D (solo con ratón)
if(!isTouch){
  const w=$('tiltWrap'), c=$('card');
  w.addEventListener('mousemove',e=>{ const r=c.getBoundingClientRect(); const px=(e.clientX-r.left)/r.width-0.5, py=(e.clientY-r.top)/r.height-0.5; c.style.transform=`rotateY(${(px*7).toFixed(2)}deg) rotateX(${(-py*6).toFixed(2)}deg)`; });
  w.addEventListener('mouseleave',()=>{ c.style.transform=''; });
}

// Instalación y modo sin conexión
window.addEventListener('beforeinstallprompt',e=>{ e.preventDefault(); deferredInstall=e; renderModal(); });
if('serviceWorker' in navigator && location.protocol!=='file:'){
  window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js').catch(()=>{}));
}
document.addEventListener('visibilitychange',()=>{ if(!document.hidden){ check(); render(); } });

/* ---------- Arranque ---------- */
applyVars();
buildFloaters();
render();
check();
setInterval(check,20000);
