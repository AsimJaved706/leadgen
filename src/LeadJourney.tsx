import { useEffect, useState, type CSSProperties } from 'react';
import { ArrowRight, Check, CheckCheck, Search, UsersRound, CalendarDays, Trophy, MousePointer2, Pause, Play, RotateCcw, Sparkles, MapPin } from 'lucide-react';
import './lead-journey.css';

const stages = ['Google Maps','CSV import','Stored leads','Lead lists','Campaigns'];
const icons = [MapPin,MousePointer2,UsersRound,CheckCheck,CalendarDays];
const people = ['John Smith','Sarah Chen','Alex Morgan'];
const companies = ['ABC HVAC','Northstar Heating','Toronto Air Co.'];
const fields = ['Email','Phone','Website','Category','Location','Opening hours'];
const finder = ['Reading extension CSV','Validating lead records','Cleaning contact details','Skipping duplicate businesses','Saving leads to workspace'];

export function LeadJourney(){
 const [scene,setScene]=useState(0),[step,setStep]=useState(0),[playing,setPlaying]=useState(true),[reduced,setReduced]=useState(false);
 useEffect(()=>{const media=matchMedia('(prefers-reduced-motion: reduce)');const update=()=>{setReduced(media.matches);if(media.matches){setPlaying(false);setStep(6)}};update();media.addEventListener('change',update);return()=>media.removeEventListener('change',update)},[]);
 useEffect(()=>{if(!playing||reduced)return;const timer=setInterval(()=>{if(!document.hidden)setStep(s=>(s+1)%9)},1600);return()=>clearInterval(timer)},[playing,reduced]);
 const select=(i:number)=>{setScene(i);setStep(reduced?6:0)};
 return <div className={`journey ${playing&&!reduced?'journey-playing':''}`}>
  <div className="journey-top"><span><span className="journey-dot"/> HOW LEADSPACE WORKS</span><span className="journey-example">Product workflow</span></div>
  <div className="journey-heading"><div><h2>From Google Maps to organized outreach.</h2><p>See how your extracted businesses move through the Leadspace workspace.</p></div><Sparkles size={24}/></div>
  <div className="journey-tabs" role="tablist" aria-label="Workflow preview">{['Workspace flow','CSV import','Lead details'].map((label,i)=><button key={label} id={`journey-tab-${i}`} role="tab" aria-selected={scene===i} aria-controls="journey-panel" onClick={()=>select(i)}>{label}</button>)}</div>
  <div className="journey-panel" id="journey-panel" role="tabpanel" aria-labelledby={`journey-tab-${scene}`}>
   {scene===0?<><div className="journey-stages">{stages.map((stage,i)=>{const Icon=icons[i];return <div className={Math.min(step,4)>=i?'reached':''} key={stage}><span><Icon size={20}/></span><strong>{stage}</strong>{i<4&&<ArrowRight className="stage-arrow" size={17}/>}</div>})}</div><div className="journey-track"><div className="journey-lanes" aria-hidden="true">{stages.map(s=><i key={s}/>)}</div>{people.map((name,i)=>{const position=Math.max(0,Math.min(4,step-i));return <div key={name} className={`moving-lead ${position>=2?'qualified':''}`} style={{'--stage':position,'--row':i} as CSSProperties}><span className="lead-initials">{companies[i][0]}</span><div><strong>{companies[i]}</strong><small>{position<2?'Extension record':position<4?'Saved to workspace':'Ready for email'}</small></div>{position>=2&&<Check key={`${i}-check`} className="qualification-check" size={15}/>}</div>})}</div><div className="journey-caption"><span className="journey-check"><Check size={15}/></span><p>{step<2?'Extract businesses from Google Maps and download the CSV.':step<4?'Leadspace cleans, deduplicates, stores and organizes each record.':'Use saved templates to send or schedule an email campaign.'}</p></div></>:
   scene===1?<div className="finder-scene"><div className="finder-search"><Search size={21}/><span>results-google-maps.csv</span><span className="finder-demo">Extension export</span></div><div className="finder-body"><ol>{finder.map((text,i)=><li key={text} className={step>=i?'found':''}><span>{step>=i?<Check size={15}/>:i+1}</span>{text}</li>)}</ol><div className="finder-results">{companies.map((company,i)=><article key={company} className={step>=i+2?'result-visible':''}><div className="finder-avatar">{company[0]}</div><div><strong>{company}</strong><p><MapPin size={12}/> Toronto, Canada</p><small>Saved lead record</small></div><Check size={17}/></article>)}{step<2&&<p className="finder-placeholder">Preparing your import…</p>}</div></div></div>:
   <div className="enrichment-scene"><div className="enrichment-contact"><span className="enrichment-avatar">A</span><h3>ABC HVAC</h3><p>HVAC contractor</p><span><MapPin size={14}/> Toronto, Canada</span><div className="enrichment-score"><small>Record completeness</small><strong>{[0,18,36,54,72,88,100,100,100][step]}<span>%</span></strong><div><i style={{width:`${[0,18,36,54,72,88,100,100,100][step]}%`}}/></div></div></div><div className="enrichment-fields">{fields.map((field,i)=><div key={field} className={step>=i?'enriched':''}><span>{field}</span><strong>{step>=i?<><Check size={16}/> Stored</>:'Processing…'}</strong></div>)}</div></div>}
  </div>
  <div className="journey-footer"><p>Sample records · This animation reflects CSV import, database storage, lead lists, templates and scheduled email campaigns available in Leadspace.</p><div><button aria-label="Replay workflow animation" onClick={()=>{setStep(reduced?6:0);setPlaying(!reduced)}}><RotateCcw size={15}/></button><button disabled={reduced} onClick={()=>setPlaying(!playing)} aria-label={playing?'Pause workflow animation':'Play workflow animation'}>{playing&&!reduced?<Pause size={15}/>:<Play size={15}/>} {reduced?'Motion off':playing?'Pause':'Play'}</button></div></div>
 </div>
}
