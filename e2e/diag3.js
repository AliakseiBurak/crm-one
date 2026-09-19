const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const page = await browser.newPage({ viewport: { width: 1920, height: 1400 } });
  await page.goto('https://b2b-crm.local/login');
  await page.fill('input[name="_login"]', 'admin');
  await page.fill('input[name="_password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(800);
  await page.goto('https://b2b-crm.local/dashboard');
  await page.waitForTimeout(800);
  const card = page.locator('.org-contacts__card-wrap .card').first();
  const cs = await card.evaluate((el) => {
    const s = getComputedStyle(el);
    return {
      boxSizing: s.boxSizing, width: s.width, height: s.height,
      padding: s.padding, minWidth: s.minWidth, display: s.display,
    };
  });
  console.log(JSON.stringify(cs, null, 1));
  await browser.close();
})();