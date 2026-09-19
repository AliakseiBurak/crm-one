const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const page = await browser.newPage({ viewport: { width: 1920, height: 1400 } });
  await page.goto('https://b2b-crm.local/login');
  await page.fill('input[name="_login"]', 'admin');
  await page.fill('input[name="_password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(3000); console.log('URL:', page.url());
  await page.waitForTimeout(1500);
  await page.screenshot({ path: '/tmp/opencode/dashboard.png', fullPage: true });
  await browser.close();
  console.log('done');
})();