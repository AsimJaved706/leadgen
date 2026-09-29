import { z } from 'zod';
export const leadSchema = z.object({ name: z.string().trim().min(1), category: z.string().default('Other'), city: z.string().default('Unknown'), country: z.string().default('United States'), email: z.string().default(''), phone: z.string().default(''), website: z.string().default(''), rating: z.coerce.number().min(0).max(5).default(0), reviews: z.coerce.number().min(0).default(0) });
export type Lead = z.infer<typeof leadSchema> & { id: string; list: string; date: string; color: string; address?: string; raw?: Record<string, unknown> };
export const initialLeads: Lead[] = [
 ['Blue Bottle Coffee','Coffee shop','San Francisco','4.7','428','bluebottlecoffee.com','SF Coffee Shops','blue'],
 ['The Grounds of Alexandria','Restaurant','Sydney','4.5','1246','thegrounds.com.au','Sydney Restaurants','green'],
 ['Bloom & Wild','Florist','London','4.8','312','bloomandwild.com','London Local Businesses','pink'],
 ['Equinox SoHo','Fitness center','New York','4.6','286','equinox.com','NYC Fitness Studios','purple'],
 ['The Hoxton, Amsterdam','Hotel','Amsterdam','4.6','892','thehoxton.com','European Hotels','orange'],
 ['Verve Coffee Roasters','Coffee shop','San Francisco','4.8','563','vervecoffee.com','SF Coffee Shops','blue'],
 ['Nomad Design Studio','Design agency','London','4.9','74','nomadstudio.com','London Local Businesses','pink'],
 ['Barry’s Bootcamp','Fitness center','New York','4.7','193','barrys.com','NYC Fitness Studios','purple'],
 ['Single O Surry Hills','Coffee shop','Sydney','4.6','731','singleo.com.au','Sydney Restaurants','green'],
 ['Hotel Pulitzer','Hotel','Amsterdam','4.8','1043','pulitzeramsterdam.com','European Hotels','orange'],
 ['Ritual Coffee Roasters','Coffee shop','San Francisco','4.5','612','ritualcoffee.com','SF Coffee Shops','blue'],
 ['Wild at Heart','Florist','London','4.7','129','wildatheart.com','London Local Businesses','pink'],
].map((a,i)=>({id:String(i+1),name:a[0],category:a[1],city:a[2],country: a[2]==='London'?'United Kingdom':a[2]==='Sydney'?'Australia':a[2]==='Amsterdam'?'Netherlands':'United States',rating:Number(a[3]),reviews:Number(a[4]),website:a[5],list:a[6],color:a[7],email:`hello@${a[5]}`,phone:`+1 (415) 555-${String(1200+i)}`,date:'2026-09-22',address:`${120+i*13} ${['Market Street','Bourke Road','Kings Road','Broadway','Herengracht'][i%5]}`}));
export const initialLists = ['SF Coffee Shops','Sydney Restaurants','London Local Businesses','NYC Fitness Studios','European Hotels'];
export function readStore<T>(key:string, fallback:T):T { try { return JSON.parse(localStorage.getItem(key) || 'null') ?? fallback; } catch { return fallback; } }
export function normalizeImport(rows: Record<string,unknown>[], existing: Lead[]) { const accepted:Lead[]=[]; let duplicates=0; let failed=0; const seen=new Set(existing.map(l=>`${l.name.toLowerCase()}|${l.city.toLowerCase()}`)); for(const row of rows){ const parsed=leadSchema.safeParse({...row,rating:row.rating||row.averageRating||0,reviews:row.reviews||row.reviewCount||0}); if(!parsed.success){failed++;continue;} const key=`${parsed.data.name.toLowerCase()}|${parsed.data.city.toLowerCase()}`; if(seen.has(key)){duplicates++;continue;} seen.add(key); accepted.push({...parsed.data,id:crypto.randomUUID(),list:String(row.list||'Imported leads'),date:new Date().toISOString().slice(0,10),color:'blue',raw:row}); } return {accepted,duplicates,failed}; }
