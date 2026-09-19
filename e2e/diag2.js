const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  for (const width of [1920, 1600, 1440, 1366, 1280, 1024, 768]) {
    const page = await browser.newPage({ viewport: { width, height: 1400 } });
    await page.goto('https://b2b-crm.local/login');
    await page.fill('input[name="_login"]', 'admin');
    await page.fill('input[name="_password"]', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(800);
    await page.goto('https://b2b-crm.local/dashboard');
    await page.waitForTimeout(800);
    const details = page.locator('details.org-details__box');
    const n = await details.count();
    let overlaps = 0;
    for (let i = 0; i < n; i++) {
      const d = details.nth(i);
      const open = await d.getAttribute('open');
      if (open === null) {
        await d.locator('.org-details__summary').click();
        await page.waitForTimeout(200);
      }
      const btn = d.locator('.org-contacts__add').first();
      if (await btn.count() === 0) continue;
      const cards = d.locator('.org-contacts__card-wrap');
      const cn = await cards.count();
      const bb = await btn.boundingBox();
      for (let j = 0; j < cn; j++) {
        const wrap = await cards.nth(j).boundingBox();
        const card = await cards.nth(j).locator('.card').boundingBox();
        if (!bb || !wrap || !card) continue;
        const overlapV = bb.y < wrap.y + wrap.height && bb.y + bb.height > wrap.y;
        const cardOverflow = card.y + card.height > wrap.y + wrap.height + 1 || card.x + card.width > wrap.x + wrap.width + 1;
        if (overlapV) { overlaps++; console.log(`w=${width} org=${i} card=${j} BUTTON OVERLAPS WRAP`, JSON.stringify({ btn: bb, wrap })); }
        if (cardOverflow) { overlaps++; console.log(`w=${width} org=${i} card=${j} CARD OVERFLOWS WRAP`, JSON.stringify({ card, wrap })); }
      }
    }
    if (overlaps === 0) console.log(`w=${width}: clean`);
    await page.close();
  }
  await browser.close();
  console.log('done');
})();