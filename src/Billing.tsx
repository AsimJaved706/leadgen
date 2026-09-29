import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, CreditCard, ExternalLink, ShieldCheck } from 'lucide-react';
import { api, type Plan, type Workspace } from './api';
import './billing.css';

type Subscription={id:number;status:string;billing_interval:string|null;current_period_end:string|null;cancel_at_period_end:boolean;plan:Plan;provider_customer_id:string|null};
type BillingData={workspace:Workspace;subscription:Subscription|null;plans:Plan[];can_manage:boolean;stripe_configured:boolean};

export function Billing({workspaceId}:{workspaceId:number}){
 const [interval,setInterval]=useState<'month'|'year'>('month'),[busy,setBusy]=useState<number|'portal'|null>(null),[error,setError]=useState('');
 const queryClient=useQueryClient(),searchParams=new URLSearchParams(location.search),returnState=searchParams.get('billing'),sessionId=searchParams.get('session_id');
 const billing=useQuery({queryKey:['billing',workspaceId],queryFn:()=>api<BillingData>(`/workspaces/${workspaceId}/billing`),retry:false});
 const confirmation=useQuery({queryKey:['billing-confirmation',workspaceId,sessionId],queryFn:async()=>{const result=await api<{confirmed:boolean}>(`/workspaces/${workspaceId}/billing/confirm`,'POST',{session_id:sessionId});await Promise.all([queryClient.invalidateQueries({queryKey:['billing',workspaceId]}),queryClient.invalidateQueries({queryKey:['session']})]);return result},enabled:returnState==='success'&&!!sessionId,retry:false});
 useEffect(()=>{const actual=billing.data?.subscription?.billing_interval;if(actual==='month'||actual==='year')setInterval(actual)},[billing.data?.subscription?.billing_interval]);
 async function redirect(path:string,body?:unknown,key:number|'portal'='portal'){setBusy(key);setError('');try{const result=await api<{url:string}>(path,'POST',body);window.location.assign(result.url)}catch(e){setError((e as Error).message);setBusy(null)}}
 if(billing.isPending)return <div className="platform-card platform-empty">Loading billing…</div>;
 if(billing.error)return <div className="platform-error">{billing.error.message}</div>;
 const data=billing.data,subscription=data.subscription,active=['active','trialing','past_due'].includes(subscription?.status||'');
 return <div className="billing-page">
  {returnState==='success'&&confirmation.isPending&&<div className="platform-notice"><Check size={16}/>Checkout completed. Confirming your subscription…</div>}
  {returnState==='success'&&confirmation.isSuccess&&<div className="platform-notice"><Check size={16}/>Payment confirmed. Your subscription is active.</div>}
  {returnState==='success'&&confirmation.error&&<div className="platform-error">Payment succeeded, but confirmation failed: {confirmation.error.message}</div>}
  {returnState==='cancelled'&&<div className="billing-return">Checkout was cancelled. No plan change was made.</div>}
  {!data.stripe_configured&&<div className="platform-error">Stripe test keys have not been configured on the server yet. Add the Stripe secret and webhook signing secret before accepting payments.</div>}
  {error&&<div className="platform-error">{error}</div>}
  <section className="billing-current platform-card"><div><span className="section-eyebrow">CURRENT WORKSPACE PLAN</span><h2>{data.workspace.plan.name}</h2><p>{active?`${subscription?.billing_interval==='year'?'Yearly':'Monthly'} subscription ${subscription?.status}.`:'No active paid subscription.'}{subscription?.current_period_end&&` Current period ends ${new Date(subscription.current_period_end).toLocaleDateString()}.`}</p>{subscription?.cancel_at_period_end&&<span className="billing-cancelled">Cancellation scheduled at period end</span>}</div><div className="billing-safe"><ShieldCheck size={22}/><span><strong>Secure Stripe billing</strong><small>Card details are collected and stored by Stripe.</small></span></div>{subscription?.provider_customer_id&&data.can_manage&&<button className="button" disabled={busy!==null} onClick={()=>redirect(`/workspaces/${workspaceId}/billing/portal`,undefined,'portal')}><CreditCard size={15}/>{busy==='portal'?'Opening…':'Manage billing'}<ExternalLink size={13}/></button>}</section>
  <div className="billing-toggle"><button className={interval==='month'?'active':''} onClick={()=>setInterval('month')}>Monthly</button><button className={interval==='year'?'active':''} onClick={()=>setInterval('year')}>Yearly <span>Save about 2 months</span></button></div>
  <div className="billing-plans">{data.plans.map(plan=>{const free=plan.slug==='free',current=data.workspace.plan.id===plan.id,price=interval==='year'?plan.yearly_price_cents:plan.monthly_price_cents,configured=interval==='year'?!!plan.stripe_yearly_price_id:!!plan.stripe_monthly_price_id;return <article className={`platform-card ${current?'current':''}`} key={plan.id}><span className="billing-plan-name">{plan.name}{current&&<i>Current · {subscription?.billing_interval==='year'?'Yearly':'Monthly'}</i>}</span><strong>${(price/100).toLocaleString()}<small>/{interval==='year'?'year':'month'}</small></strong><ul><li><Check size={14}/>{plan.limits.leads.toLocaleString()} stored leads</li><li><Check size={14}/>{plan.limits.lists.toLocaleString()} lead lists</li><li><Check size={14}/>{plan.limits.members.toLocaleString()} team members</li><li><Check size={14}/>{plan.limits.monthly_emails.toLocaleString()} emails/month</li></ul>{free?<button className="button" disabled>Included free</button>:active?<button className="button" disabled={current||!data.can_manage} onClick={()=>redirect(`/workspaces/${workspaceId}/billing/portal`,undefined,'portal')}>{current?'Active plan':'Change in portal'}</button>:<button className="button primary" disabled={!data.can_manage||!data.stripe_configured||!configured||busy!==null} onClick={()=>redirect(`/workspaces/${workspaceId}/billing/checkout`,{plan_id:plan.id,interval},plan.id)}>{busy===plan.id?'Opening checkout…':!configured?'Stripe price not configured':'Choose plan'}</button>}</article>})}</div>
  <p className="billing-disclosure">Paid plans renew automatically until cancelled. Taxes may apply. Use the Stripe portal to update payment details, download invoices or cancel.</p>
 </div>
}
