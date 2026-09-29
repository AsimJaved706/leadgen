import { chromium } from '@playwright/test';
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://localhost:5173/');
 await page.locator('.moving-lead.qualified').first().waitFor();
 await page.getByRole('button',{name:'Pause workflow animation'}).click();
 const position=await page.locator('.moving-lead').first().getAttribute('style');
 await page.waitForTimeout(1800);
 if(position!==await page.locator('.moving-lead').first().getAttribute('style'))throw Error('Pause failed');
 await page.locator('.journey').screenshot({path:'journey-desktop.png'});
 await page.setViewportSize({width:390,height:844});
 for(const name of ['Workspace flow','CSV import','Lead details']){
  await page.getByRole('tab',{name,exact:true}).click();
  if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('Overflow: '+name);
 }
 await page.getByRole('button',{name:'Play workflow animation',exact:true}).click();
 await page.locator('.enriched').first().waitFor();
 await page.emulateMedia({reducedMotion:'reduce'});
 await page.getByRole('button',{name:'Play workflow animation',exact:true}).waitFor();
 if(!await page.getByRole('button',{name:'Play workflow animation',exact:true}).isDisabled())throw Error('Reduced motion failed');
 await page.locator('.journey').screenshot({path:'journey-mobile.png'});
 if(errors.length)throw Error(errors.join('\n'));
 console.log('Passed: moving cards, qualification, pause, three mobile scenes, reduced motion, no runtime errors.');
}finally{await browser.close()}
