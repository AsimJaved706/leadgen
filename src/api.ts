export type Plan = {id:number;name:string;slug:string;monthly_price_cents:number;yearly_price_cents:number;limits:Record<string,number>;is_active:boolean;stripe_monthly_price_id?:string|null;stripe_yearly_price_id?:string|null};
export type Workspace = {id:number;name:string;plan:Plan;plan_id:number;suspended_at:string|null;leads_count?:number;members_count?:number;owner?:{name:string;email:string}};
export type User = {id:number;name:string;email:string;is_super_admin:boolean;suspended_at:string|null;created_at:string;workspaces:Workspace[];workspaces_count?:number};
export type Page<T> = {data:T[];current_page:number;last_page:number;total:number};
export class ApiError extends Error { constructor(message:string,public status:number){super(message)} }
let csrf='';
export async function api<T>(path:string,method='GET',body?:unknown):Promise<T>{
 if(method!=='GET'&&!csrf){const r=await fetch('/api/csrf',{credentials:'same-origin',headers:{Accept:'application/json'}});if(!r.ok)throw new ApiError('Cannot connect to the API. Please try again.',r.status);csrf=(await r.json()).token;}
 const multipart=body instanceof FormData;
 const response=await fetch('/api'+path,{method,credentials:'same-origin',headers:{Accept:'application/json',...(!multipart?{'Content-Type':'application/json'}:{}),...(method!=='GET'?{'X-CSRF-TOKEN':csrf}:{})},...(body?{body:multipart?body:JSON.stringify(body)}:{})});
 if(response.status===204)return undefined as T;
 const data=await response.json().catch(()=>({message:'The API is unavailable. Please try again shortly.'}));
 if(!response.ok){if(response.status===419)csrf='';throw new ApiError(data.errors?Object.values(data.errors).flat().join(' '):data.message||'Request failed.',response.status)}
 if(path==='/login'||path==='/register'||path==='/logout')csrf='';
 return data;
}
