import { expect, test, type Page } from '@playwright/test';
import { latestStatus, lines, setup, type Setup } from './wp';

/**
 * A visitor submitting a real form on a real page, with the real Gravity
 * Forms: what gds-monitoring logs, and what it leaves alone.
 */
test.describe.configure({ mode: 'serial' });

let site: Setup;

test.beforeEach(() => {
    site = setup();
});

async function fill(page: Page): Promise<void> {
    await page.goto(site.page);
    // By id: the honeypot is a hidden field labelled like a real one.
    await page.locator(`#input_${site.form}_1`).fill('Jane');
}

async function submit(page: Page): Promise<void> {
    await page.locator('input[type="submit"], button[type="submit"]').first().click();
    await expect(page.locator('.gform_confirmation_message')).toBeVisible();
}

test('a submission whose feed fails is logged as a feed error', async ({ page }) => {
    await fill(page);
    await submit(page);

    expect(latestStatus(site.form)).toBe('active');
    expect(lines()).toEqual([
        expect.stringMatching(new RegExp(`^gds-monitoring: gform gdse2efeed error blog 1 form ${site.form} entry \\d+: E2E: the CRM refused it$`)),
    ]);
});

/**
 * A page whose state token no longer matches, as a stale cached page's: the
 * honeypot keeps the submission as spam, and a person should look at it.
 */
test('a submission from a stale page is kept as spam and logged', async ({ page }) => {
    test.skip(Number.parseInt(site.gf, 10) < 3, 'Gravity Forms 2.x does not check the state token in its honeypot');
    await fill(page);
    await page.locator(`input[name="state_${site.form}"]`).evaluate((input: HTMLInputElement) => {
        input.value = btoa('["{}","0000",1]');
    });
    await submit(page);

    expect(latestStatus(site.form)).toBe('spam');
    expect(lines()).toEqual([
        expect.stringMatching(new RegExp(`^gds-monitoring: gform spam blog 1 form ${site.form} entry \\d+ \\(Honeypot Spam Filter\\): Hidden input \\(name: state_${site.form}\\) does not contain the expected value\\.$`)),
    ]);
});

test('a bot filling the honeypot is kept as spam but not logged', async ({ page }) => {
    await fill(page);
    await page.locator('.gform_validation_container input').evaluate((input: HTMLInputElement) => {
        input.value = 'https://spam.example';
    });
    await submit(page);

    expect(latestStatus(site.form)).toBe('spam');
    expect(lines()).toEqual([]);
});
