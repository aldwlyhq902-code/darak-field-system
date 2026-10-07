import crypto from 'node:crypto';
import { expect, test } from '@playwright/test';

const demoPassword = process.env.DARAK_DEMO_PASSWORD || 'Darak-E2E-Password-2026!';

function decodeBase32(value) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const character of value.replace(/=+$/u, '').replace(/\s+/gu, '').toUpperCase()) {
        const index = alphabet.indexOf(character);
        if (index < 0) throw new Error(`Invalid base32 character: ${character}`);
        bits += index.toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let offset = 0; offset + 8 <= bits.length; offset += 8) {
        bytes.push(Number.parseInt(bits.slice(offset, offset + 8), 2));
    }
    return Buffer.from(bytes);
}

function totp(secret, timestamp = Date.now()) {
    const counter = BigInt(Math.floor(timestamp / 30_000));
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(counter);
    const digest = crypto.createHmac('sha1', decodeBase32(secret)).update(message).digest();
    const offset = digest.at(-1) & 0x0f;
    const binary = (digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;
    return binary.toString().padStart(6, '0');
}

async function hasHorizontalOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

test('owner completes MFA and critical RTL mobile interactions stay accessible', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { name: 'مرحبًا بعودتك' })).toBeVisible();

    await page.locator('#panel-email').fill('owner@darak.test');
    await page.locator('#panel-password').fill(demoPassword);
    await page.getByRole('button', { name: 'دخول إلى اللوحة' }).click();

    await expect(page).toHaveURL(/\/two-factor\/setup$/u);
    await expect(page.getByAltText('رمز QR لإعداد التحقق بخطوتين')).toBeVisible();
    const manualText = await page.locator('.manual-key').textContent();
    const secret = manualText.match(/Key:\s*([A-Z2-7]+)/u)?.[1];
    expect(secret).toBeTruthy();
    await page.locator('#mfa-code').fill(totp(secret));
    await page.getByRole('button', { name: 'تفعيل وإكمال الدخول' }).click();

    await expect(page.getByRole('heading', { name: 'تم تأمين الحساب' })).toBeVisible();
    await page.getByRole('link', { name: /الانتقال إلى اللوحة/u }).click();
    await expect(page).toHaveURL(/\/board$/u);
    await expect(page.getByRole('heading', { name: 'لوحة اليوم' })).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('[data-sidebar-open]')).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);
    await page.locator('[data-sidebar-open]').click();
    await expect(page.locator('[data-sidebar-open]')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('[data-sidebar-close]').first()).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-sidebar-open]')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('[data-sidebar-open]')).toBeFocused();

    await page.goto('/commercial');
    await expect(page.getByRole('heading', { name: 'العروض والتحصيل' })).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);

    await page.goto('/sales/app');
    expect(await hasHorizontalOverflow(page)).toBe(false);
    const openLead = page.locator('[data-open-modal="lead-modal"]').first();
    await openLead.click();
    const modal = page.locator('#lead-modal');
    await expect(modal).toBeVisible();
    await expect(modal.locator('[data-close-modal]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
    await expect(openLead).toBeFocused();

    // 0.3.0 regression: once leads had follow-up dates on several days, the
    // calendar strip's min-content widened the one-column grid and the whole
    // sales app became ~650px wide on a 390px phone.
    for (let day = 1; day <= 4; day += 1) {
        await page.locator('[data-open-modal="lead-modal"]').first().click();
        await modal.locator('[name=company_name]').fill(`مطاعم ومطابخ الضيافة الشعبية المركزية للحفلات والمناسبات ${day}`);
        await modal.locator('[name=estimated_value]').fill(String(15_000 * day));
        await modal.locator('[name=next_action_on]').fill(new Date(Date.now() + day * 86_400_000).toISOString().slice(0, 10));
        await modal.getByRole('button', { name: 'إضافة إلى مساري' }).click();
        await page.waitForLoadState('domcontentloaded');
    }
    await expect(page.locator('.calendar-day')).toHaveCount(4);
    expect(await hasHorizontalOverflow(page)).toBe(false);
    for (const selector of ['.calendar-card', '.follow-list', '.pipeline']) {
        const box = await page.locator(selector).first().boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(390);
    }
});
