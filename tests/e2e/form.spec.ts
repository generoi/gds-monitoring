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
        expect.stringMatching(new RegExp(`^gds-monitoring: report gform blog 1: gdse2efeed error form ${site.form} entry \\d+: E2E: the CRM refused it$`)),
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
        expect.stringMatching(new RegExp(`^gds-monitoring: report gform blog 1: spam form ${site.form} entry \\d+ \\(Honeypot Spam Filter\\): Hidden input \\(name: state_${site.form}\\) does not contain the expected value\\.$`)),
    ]);
});

/**
 * A choice the form no longer offers, as from a page cached before the form
 * was edited: Gravity Forms refuses it and saves nothing.
 */
test('a submission failing the state check is logged', async ({ page }) => {
    site = setup('validate-state');
    await fill(page);
    await page.locator(`#input_${site.form}_2 option[value="large"]`).evaluate((option: HTMLOptionElement) => {
        option.value = 'medium';
    });
    await page.locator(`#input_${site.form}_2`).selectOption('medium');
    await page.locator('input[type="submit"], button[type="submit"]').first().click();

    await expect(page.locator(`#field_${site.form}_2 .gfield_validation_message`)).toBeVisible();
    expect(lines()).toEqual([`gds-monitoring: report gform blog 1: state invalid form ${site.form} fields 2`]);
});

/**
 * The same refusal posted straight from the page's HTML, as spam bots do: no
 * script ran, so Gravity Forms' version_hash is missing.
 */
test('a scriptless submission failing the state check is not logged', async ({ page }) => {
    site = setup('validate-state');
    await fill(page);
    const fields = await page.locator(`#gform_${site.form}`).evaluate((form: HTMLFormElement) => {
        const data = Object.fromEntries(new FormData(form) as unknown as Iterable<[string, string]>);
        delete data.version_hash;
        return data;
    });
    fields.input_2 = 'medium';

    const response = await page.request.post(site.page, { multipart: fields });

    // Refused all the same, so a missing line means it wasn't logged.
    expect(await response.text()).toContain('gform_validation_error');
    expect(lines()).toEqual([]);
});

/** A page whose Gravity Forms scripts fail to load, as when blocked or broken. */
async function withoutGravityFormsScripts(page: Page): Promise<void> {
    await page.route('**/plugins/gravityforms/**/*.js*', (route) => route.abort());
}

/**
 * Gravity Forms' honeypot keeps a person whose page didn't run its script as
 * spam, for the version_hash that script adds: on a site where it fails to
 * load, every real entry. Our own script ran, so it's a person.
 */
test('a person whose Gravity Forms script did not run is logged as spam', async ({ page }) => {
    await withoutGravityFormsScripts(page);
    await fill(page);
    await page.locator('input[type="submit"], button[type="submit"]').first().click();
    await page.waitForLoadState('load');

    expect(latestStatus(site.form)).toBe('spam');
    expect(lines()).toEqual([
        expect.stringMatching(new RegExp(`^gds-monitoring: report gform blog 1: spam form ${site.form} entry \\d+ \\(Honeypot Spam Filter\\): The version_hash was not included in the submission\\.$`)),
    ]);
});

test('a bot posting the page untouched is kept as spam but not logged', async ({ page }) => {
    await fill(page);
    const fields = await page.locator(`#gform_${site.form}`).evaluate((form: HTMLFormElement) => Object.fromEntries(new FormData(form) as unknown as Iterable<[string, string]>));

    await page.request.post(site.page, { multipart: fields });

    expect(latestStatus(site.form)).toBe('spam');
    expect(lines()).toEqual([]);
});

test('a refusal is logged when only Gravity Forms\' script failed', async ({ page }) => {
    site = setup('validate-state');
    await withoutGravityFormsScripts(page);
    await fill(page);
    await page.locator(`#input_${site.form}_2 option[value="large"]`).evaluate((option: HTMLOptionElement) => {
        option.value = 'medium';
    });
    await page.locator(`#input_${site.form}_2`).selectOption('medium');
    await page.locator('input[type="submit"], button[type="submit"]').first().click();

    await expect(page.locator(`#field_${site.form}_2 .gfield_validation_message`)).toBeVisible();
    expect(lines()).toEqual([`gds-monitoring: report gform blog 1: state invalid form ${site.form} fields 2`]);
});

async function upload(page: Page): Promise<void> {
    await page.locator(`#gform_multifile_upload_${site.form}_3 input[type="file"]`).setInputFiles({
        name: 'note.txt', mimeType: 'text/plain', buffer: Buffer.from('hello'),
    });
}

test('a multi-file upload is not logged', async ({ page }) => {
    await fill(page);
    await upload(page);

    await expect(page.locator(`#gform_preview_${site.form}_3`)).toContainText('note.txt');
    await expect(page.getByText('Your session has expired')).toHaveCount(0);
    expect(lines()).toEqual([]);
});

/**
 * The page's upload nonce expired, as on a page cached for more than a day:
 * the visitor's file is refused before the form is sent.
 */
test('an upload refused for an expired nonce is logged', async ({ page }) => {
    site = setup('expire-uploads');
    await fill(page);
    await page.waitForTimeout(4000);
    await upload(page);

    await expect(page.getByText('Your session has expired')).toBeVisible();
    await expect.poll(lines).toEqual([`gds-monitoring: report gform blog 1: upload nonce expired form ${site.form} field 3`]);
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
