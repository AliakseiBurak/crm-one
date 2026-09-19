const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const page = await browser.newPage({ viewport: { width: 1920, height: 1400 } });
  await page.goto('https://b2b-crm.local/login');
  await page.fill('input[name="_login"]', 'admin');
  await page.fill('input[name="_password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(1500);
  console.log('after login URL:', page.url());
  await page.goto('https://b2b-crm.local/dashboard');
  await page.waitForTimeout(1500);
  console.log('dashboard URL:', page.url());
  const details = page.locator('details.org-details__box');
  const n = await details.count();
  console.log('details:', n);
  for (let i = 0; i < n; i++) {
    const d = details.nth(i);
    const open = await d.getAttribute('open');
    if (open === null) {
      await d.locator('.org-details__summary').click();
      await page.waitForTimeout(300);
    }
    const btn = d.locator('.org-contacts__add').first();
    if (await btn.count() === 0) continue;
    const cards = d.locator('.org-contacts__card-wrap');
    const cn = await cards.count();
    const bb = await btn.boundingBox();
    let worst = null;
    for (let j = 0; j < cn; j++) {
      const cb = await cards.nth(j).boundingBox();
      if (!bb || !cb) continue;
      const overlapV = bb.y < cb.y + cb.height && bb.y + bb.height > cb.y;
      if (overlapV) worst = { j, btn: bb, card: cb };
    }
    console.log('--- details', i, 'cards:', cn, worst ? 'OVERLAP!' : 'no overlap', 'btn.top=' + Math.round(bb && bb.y), 'grid cards top/bottom range ok');
    if (worst) console.log(JSON.stringify(worst, null, 1));
  }
  await browser.close();
  console.log('done');
})();