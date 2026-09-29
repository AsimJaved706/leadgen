import { chromium } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { writeFileSync, unlinkSync } from 'node:fs';
import path from 'node:path';
const backend=path.resolve('backend');
const db=path.join(backend,'database',`browser-test-${Date.now()}.sqlite`);
const password=randomBytes(20).toString('hex')+'Aa1';
const env={...process.env,APP_ENV:'testing',APP_DEBUG:'false',DB_CONNECTION:'sqlite',DB_DATABASE:db,SESSION_DRIVER:'database',BROWSER_TEST_PASSWORD:password};
const servers=[];let browser;
writeFileSync(db,'');
try {
 execFileSync('php',['artisan','migrate','--force'],{cwd:backend,env,stdio:'pipe'});
 execFileSync('php',['artisan','db:seed','--class=PlanSeeder','--force'],{cwd:backend,env,stdio:'pipe'});
 execFileSync('php',['tests/browser-fixture.php'],{cwd:backend,env,stdio:'pipe'});
 servers.push(spawn('php',['-S','127.0.0.1:8001','-t','public','public/index.php'],{cwd:backend,env:{...env,APP_ENV:'local'},stdio:'ignore',windowsHide:true}));
 servers.push(spawn(process.execPath,['node_modules/vite/bin/vite.js','--host','127.0.0.1','--port','5174','--strictPort'],{env:{...process.env,LEADSPACE_API_TARGET:'http://127.0.0.1:8001'},stdio:'ignore',windowsHide:true}));
 for(let i=0;i<50;i++){try{const response=await fetch('http://127.0.0.1:5174/api/plans');if(response.ok)break}catch{}await new Promise(r=>setTimeout(r,200));}
 browser=await chromium.launch({channel:'chrome',headless:true});
 const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:5174');await page.getByRole('heading',{name:'Local discoveries. Limitless possibilities.'}).waitFor();
 await page.getByRole('heading',{name:'Professional',exact:true}).waitFor();await page.screenshot({path:'landing-check.png',fullPage:true});
 await page.goto('http://127.0.0.1:5174/register');
 await page.getByLabel('Your name').fill('Browser Member');await page.getByLabel('Workspace name').fill('Browser Workspace');await page.getByLabel('Email address').fill('member@example.test');
 await page.getByLabel('Password',{exact:true}).fill(password);await page.getByLabel('Confirm password').fill(password);await page.getByRole('button',{name:'Create your workspace'}).click();
 await page.getByRole('heading',{name:'Overview',exact:true}).waitFor();
 await page.getByRole('heading',{name:'Lead growth',exact:true}).waitFor();
 if(await page.locator('.live-dashboard .stat-main').first().innerText()!=='0')throw new Error('New dashboard must show zero real leads');
 await page.getByLabel('Reporting period').selectOption('7');
 await page.getByRole('button',{name:'Add lead'}).click();await page.getByLabel('Business name').fill('Persistent Coffee');await page.getByLabel('City',{exact:true}).fill('Austin');await page.getByLabel('Email',{exact:true}).fill('coffee@example.test');await page.getByRole('button',{name:'Save to workspace'}).click();
 await page.getByRole('cell',{name:'Persistent Coffee',exact:true}).waitFor();await page.reload();await page.getByRole('cell',{name:'Persistent Coffee',exact:true}).waitFor();
 await page.getByRole('heading',{name:'Lead growth',exact:true}).waitFor();
 if(await page.locator('.live-dashboard .stat-main').first().innerText()!=='1')throw new Error('Dashboard lead count did not update');
 await page.screenshot({path:'.npm-cache/live-dashboard-check.png',fullPage:true});
 await page.getByRole('button',{name:'Lists',exact:true}).click();await page.getByRole('button',{name:'Create list'}).click();await page.getByLabel('List name').fill('Austin collection');await page.getByRole('button',{name:'Save to workspace'}).click();await page.getByRole('heading',{name:'Austin collection'}).waitFor();
 await page.getByRole('button',{name:'Email settings',exact:true}).click();
 await page.getByLabel('SMTP host').fill('smtp.example.test');await page.getByLabel('Username').fill('apikey');await page.getByLabel('Password').fill('secret-test-password');await page.getByLabel('From email').fill('sender@example.test');await page.getByLabel('From name').fill('Browser Workspace');await page.getByLabel('Activate campaign sending').check();await page.getByRole('button',{name:'Save SMTP settings'}).click();await page.getByText('SMTP settings saved.').waitFor();
 await page.getByRole('button',{name:'Templates',exact:true}).click();await page.getByRole('button',{name:'New template'}).click();await page.getByLabel('Template name').fill('Browser introduction');await page.getByLabel('Email subject').fill('Hello {{lead.name}}');await page.getByLabel('HTML email body').fill('<h1>Hello {{lead.name}}</h1><p>A note for your business.</p>');await page.getByRole('button',{name:'Save template'}).click();await page.getByRole('heading',{name:'Browser introduction'}).waitFor();
 await page.getByRole('button',{name:'Campaigns',exact:true}).click();await page.getByRole('button',{name:'New campaign'}).click();await page.getByLabel('Campaign name').fill('Browser draft');await page.getByLabel('Email template').selectOption({index:1});await page.getByRole('button',{name:'Create campaign'}).click();await page.getByText('Browser draft',{exact:true}).waitFor();await page.getByText('draft',{exact:true}).waitFor();
 await page.goto('http://127.0.0.1:5174/admin');await page.getByRole('heading',{name:'Administrator access required'}).waitFor();
 await page.goto('http://127.0.0.1:5174/app');await page.getByRole('button',{name:'Sign out',exact:true}).click();await page.getByRole('heading',{name:'Welcome back.'}).waitFor();
 await page.getByLabel('Email address').fill('admin@example.test');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.getByRole('heading',{name:'Platform overview'}).waitFor();
 await page.getByText('Browser Workspace',{exact:true}).waitFor();await page.screenshot({path:'admin-check.png',fullPage:true});
 await page.getByRole('button',{name:'Users',exact:true}).click();const member=page.getByRole('row').filter({hasText:'member@example.test'});await member.getByRole('button',{name:'Suspend',exact:true}).click();await page.getByRole('button',{name:'Confirm change'}).click();await member.getByText('Suspended',{exact:true}).waitFor();
 await member.getByRole('button',{name:'Restore',exact:true}).click();await page.getByRole('button',{name:'Confirm change'}).click();await member.getByText('Active',{exact:true}).waitFor();
 await page.getByRole('button',{name:'Plans',exact:true}).click();const free=page.locator('.admin-plans article').filter({has:page.getByRole('heading',{name:'Free',exact:true})});await free.getByRole('button',{name:'Edit plan'}).click();await page.getByLabel('leads',{exact:true}).fill('700');await page.getByRole('button',{name:'Save plan'}).click();await free.getByText('700',{exact:true}).waitFor();
 await page.getByRole('button',{name:'Audit log',exact:true}).click();await page.getByText('plan.updated',{exact:true}).waitFor();
 const csrfResponse=await page.request.post('http://127.0.0.1:5174/api/logout',{data:{},headers:{Accept:'application/json'}});if(csrfResponse.status()!==419)throw new Error('Missing CSRF token was not rejected');
 await page.setViewportSize({width:390,height:844});await page.goto('http://127.0.0.1:5174');await page.getByRole('heading',{name:'Professional',exact:true}).waitFor();await page.screenshot({path:'landing-mobile-check.png',fullPage:true});
 if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw new Error('Landing page overflows mobile viewport');
 await page.goto('http://127.0.0.1:5174/admin');await page.getByRole('heading',{name:'Platform overview'}).waitFor();await page.getByRole('button',{name:'Toggle menu'}).click();await page.getByRole('button',{name:'Users',exact:true}).click();await page.getByRole('heading',{name:'Users',exact:true}).waitFor();
 if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw new Error('Admin overflows mobile viewport');
 if(errors.length)throw new Error(errors.join('\n'));
 console.log('PASS: landing, registration, persisted leads, lists, admin route guard, login/logout, suspension/restore, plan editing, audit, CSRF, mobile layouts.');
} finally {
 if(browser)await browser.close();
 for(const server of servers){if(server.exitCode===null){server.kill();await new Promise(resolve=>server.once('exit',resolve));}}
 for(const file of [db,db+'-shm',db+'-wal']){try{unlinkSync(file)}catch{}}
}
