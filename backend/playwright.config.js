import { defineConfig } from '@playwright/test';

const port = Number(process.env.E2E_PORT || 8080);
const baseURL = process.env.E2E_BASE_URL || `http://127.0.0.1:${port}`;
const php = process.env.PHP_BINARY || 'php';
const laravelRouter = '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI ? [['line'], ['html', { open: 'never' }]] : 'line',
    use: {
        baseURL,
        browserName: 'chromium',
        locale: 'ar-SA',
        timezoneId: 'Asia/Riyadh',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: process.env.E2E_EXTERNAL_SERVER ? undefined : {
        // Invoke PHP's server directly so CI/local environment variables reach
        // the application consistently (artisan serve may filter them on Windows).
        command: `"${php}" -S 127.0.0.1:${port} ${laravelRouter}`,
        cwd: './public',
        url: `${baseURL}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
