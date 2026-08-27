import { performance } from 'node:perf_hooks';

const url = process.argv[2] || 'http://127.0.0.1:8080/up';
const total = Number(process.argv[3] || 60);
const concurrency = Number(process.argv[4] || 6);
const p95BudgetMs = Number(process.argv[5] || 500);

if (![total, concurrency, p95BudgetMs].every(Number.isFinite) || total < 1 || concurrency < 1 || p95BudgetMs < 1) {
    throw new Error('Usage: http-load-smoke.mjs [url] [requests] [concurrency] [p95-budget-ms]');
}

let nextRequest = 0;
let failures = 0;
const durations = [];

async function worker() {
    while (nextRequest < total) {
        nextRequest += 1;
        const startedAt = performance.now();

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', Connection: 'keep-alive' },
                cache: 'no-store',
                signal: AbortSignal.timeout(Math.max(2_000, p95BudgetMs * 4)),
            });
            await response.arrayBuffer();
            if (!response.ok) failures += 1;
        } catch {
            failures += 1;
        } finally {
            durations.push(performance.now() - startedAt);
        }
    }
}

await Promise.all(Array.from({ length: Math.min(concurrency, total) }, () => worker()));
durations.sort((left, right) => left - right);
const percentile = value => durations[Math.max(0, Math.ceil(durations.length * value) - 1)];
const result = {
    url,
    requests: total,
    concurrency,
    failures,
    p50_ms: Number(percentile(0.5).toFixed(1)),
    p95_ms: Number(percentile(0.95).toFixed(1)),
    p99_ms: Number(percentile(0.99).toFixed(1)),
    budget_p95_ms: p95BudgetMs,
};

console.log(JSON.stringify(result));

if (failures > 0 || result.p95_ms > p95BudgetMs) {
    process.exitCode = 1;
}
