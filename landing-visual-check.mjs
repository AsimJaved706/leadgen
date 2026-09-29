import { chromium } from '@playwright/test';
const browser = await chromium.launch({channel:'chrome',headless:true});
try {
  const page = await browser.newPage({viewport:{width:1440,height:1000}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://localhost:5173/');
  await page.getByRole('heading',{level:1}).waitFor();
  for (const width of [1440,390]) {
    await page.setViewportSize({width,height:1000});
    for(const id of ['features','how-it-works','pricing']) await page.locator('#'+id).scrollIntoViewIfNeeded();
    await page.locator('.landing-cta').scrollIntoViewIfNeeded();
    await page.evaluate(()=>window.scrollTo(0,0));
    await page.waitForTimeout(1000);
    if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw new Error('Overflow at '+width);
    await page.screenshot({path:width===1440?'landing-check.png':'landing-mobile-check.png',fullPage:true});
  }
  await page.emulateMedia({reducedMotion:'reduce'});
  await page.reload();
  const motion=await page.locator('.hero h1').evaluate(el=>getComputedStyle(el).animationName);
  if(motion!=='none')throw new Error('Reduced motion failed');
  if(errors.length)throw new Error(errors.join('\n'));
  console.log('Landing verified: desktop/mobile, no overflow or runtime errors, reduced motion respected.');
} finally {await browser.close();}
