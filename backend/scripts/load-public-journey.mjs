#!/usr/bin/env node

/**
 * Dependency-free load probe for the public mobile discovery journey.
 *
 * Dry-run is the default. The target profile intentionally exercises only
 * idempotent GET endpoints and derives the course-details id from the same
 * catalogue response a guest receives.
 */

import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import process from 'node:process';
import { performance } from 'node:perf_hooks';

const args = parseArgs(process.argv.slice(2));
const executionRequested = args.execute === true;
const profileName = String(args.profile ?? process.env.LOAD_TEST_PROFILE ?? 'target-1000');
const baseUrl = normalizeBaseUrl(
    String(args['base-url'] ?? process.env.LOAD_TEST_BASE_URL ?? 'http://127.0.0.1:8000/api/v1'),
);
const reportPath = resolve(
    String(args.report ?? process.env.LOAD_TEST_REPORT ?? 'storage/app/load-tests/public-journey.json'),
);
const requestTimeoutMs = positiveInteger(
    args['request-timeout-ms'] ?? process.env.LOAD_TEST_REQUEST_TIMEOUT_MS ?? 8000,
    'request-timeout-ms',
);
const selectedProfile = profile(profileName);
const thresholds = Object.freeze({
    p95Ms: positiveNumber(process.env.LOAD_TEST_P95_MS ?? 800, 'LOAD_TEST_P95_MS'),
    p99Ms: positiveNumber(process.env.LOAD_TEST_P99_MS ?? 1800, 'LOAD_TEST_P99_MS'),
    errorRate: rate(process.env.LOAD_TEST_MAX_ERROR_RATE ?? 0.01, 'LOAD_TEST_MAX_ERROR_RATE'),
});

const plan = {
    mode: executionRequested ? 'execute' : 'dry-run',
    baseUrl: baseUrl.toString().replace(/\/$/, ''),
    profile: profileName,
    requestTimeoutMs,
    thinkTimeMs: selectedProfile.thinkTimeMs,
    phases: selectedProfile.phases,
    thresholds,
    journey: [
        'GET settings',
        'GET auth-methods',
        'GET classifications',
        'GET paths',
        'GET courses/list?page=1&per_page=20',
        'GET courses/{dynamicCourseId}/details',
        'GET packages',
    ],
};

if (!executionRequested) {
    process.stdout.write(`${JSON.stringify(plan, null, 2)}\n`);
    process.exit(0);
}

authorizeTarget(baseUrl);

const metrics = createMetrics();
const startedAt = new Date();
const workers = new Map();
let nextWorkerId = 1;

process.stdout.write(
    `Running ${profileName} against ${baseUrl.origin}${baseUrl.pathname.replace(/\/$/, '')}\n`,
);

for (const phase of selectedProfile.phases) {
    await runPhase(phase);
}

resizeWorkers(0);
await Promise.allSettled([...workers.values()].map((worker) => worker.promise));

const endedAt = new Date();
const report = buildReport(startedAt, endedAt);
await mkdir(dirname(reportPath), { recursive: true });
await writeFile(reportPath, `${JSON.stringify(report, null, 2)}\n`, 'utf8');

process.stdout.write(`${JSON.stringify(report.summary, null, 2)}\n`);
process.stdout.write(`Report: ${reportPath}\n`);
process.exitCode = report.summary.thresholdsPassed ? 0 : 2;

async function runPhase(phase) {
    const phaseStartedAt = performance.now();
    const phaseEndsAt = phaseStartedAt + phase.durationMs;
    process.stdout.write(
        `${phase.name}: ${phase.fromUsers} -> ${phase.toUsers} users in ${Math.round(phase.durationMs / 1000)}s\n`,
    );

    while (performance.now() < phaseEndsAt) {
        const elapsedRatio = Math.min(
            1,
            Math.max(0, (performance.now() - phaseStartedAt) / phase.durationMs),
        );
        const desiredUsers = Math.round(
            phase.fromUsers + ((phase.toUsers - phase.fromUsers) * elapsedRatio),
        );
        resizeWorkers(desiredUsers);
        await sleep(Math.min(1000, Math.max(1, phaseEndsAt - performance.now())));
    }

    resizeWorkers(phase.toUsers);
}

function resizeWorkers(desiredCount) {
    const liveWorkers = [...workers.values()].filter((worker) => !worker.stopped);

    if (liveWorkers.length < desiredCount) {
        for (let index = liveWorkers.length; index < desiredCount; index += 1) {
            startWorker();
        }
        metrics.peakConcurrentUsers = Math.max(metrics.peakConcurrentUsers, desiredCount);
        return;
    }

    for (const worker of liveWorkers.slice(desiredCount)) {
        worker.stopped = true;
        worker.controller.abort();
    }
}

function startWorker() {
    const id = nextWorkerId;
    nextWorkerId += 1;
    const worker = {
        id,
        stopped: false,
        controller: new AbortController(),
        promise: null,
    };
    workers.set(id, worker);
    worker.promise = runWorker(worker).finally(() => workers.delete(id));
}

async function runWorker(worker) {
    let completedJourneys = 0;

    while (!worker.stopped) {
        const completed = await runGuestJourney(worker);
        if (!completed) continue;
        completedJourneys += 1;
        metrics.journeys += 1;

        if (
            selectedProfile.maxJourneysPerUser !== null
            && completedJourneys >= selectedProfile.maxJourneysPerUser
        ) {
            worker.stopped = true;
            break;
        }
    }
}

async function runGuestJourney(worker) {
    await requestJson('settings', 'settings');
    if (!(await think(worker))) return false;

    await requestJson('auth-methods', 'auth-methods');
    if (!(await think(worker))) return false;

    await requestJson('classifications', 'classifications');
    if (!(await think(worker))) return false;

    await requestJson('paths', 'paths');
    if (!(await think(worker))) return false;

    const catalogue = await requestJson('courses', 'courses/list?page=1&per_page=20');
    const courseIds = extractCourseIds(catalogue);
    if (courseIds.length === 0) {
        recordContractFailure('course-details', 'catalogue_without_course_id');
    } else {
        if (!(await think(worker))) return false;
        const courseId = courseIds[Math.floor(Math.random() * courseIds.length)];
        await requestJson('course-details', `courses/${encodeURIComponent(courseId)}/details`);
    }

    if (!(await think(worker))) return false;
    await requestJson('packages', 'packages');
    return think(worker);
}

async function requestJson(label, relativePath) {
    const url = new URL(relativePath, ensureTrailingSlash(baseUrl));
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), requestTimeoutMs);
    const started = performance.now();

    try {
        const response = await fetch(url, {
            method: 'GET',
            redirect: 'follow',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'User-Agent': 'Rokn-Public-Journey-Load/1.0',
                'X-Rokn-Load-Test': profileName,
            },
        });
        const text = await response.text();
        const latencyMs = performance.now() - started;
        let body = null;
        let failure = null;

        if (!response.ok) {
            failure = `http_${response.status}`;
        }

        try {
            body = text === '' ? null : JSON.parse(text);
        } catch {
            failure ??= 'invalid_json';
        }
        if (failure === null && body?.success === false) {
            failure = 'api_failure';
        }

        recordRequest(label, latencyMs, failure, response.status, Buffer.byteLength(text));
        return failure === null ? body : null;
    } catch (error) {
        const latencyMs = performance.now() - started;
        const failure = error?.name === 'AbortError' ? 'timeout' : 'network_error';
        recordRequest(label, latencyMs, failure, null, 0);
        return null;
    } finally {
        clearTimeout(timeout);
    }
}

async function think(worker) {
    if (worker.stopped) return false;
    const [minimum, maximum] = selectedProfile.thinkTimeMs;
    const delay = Math.round(minimum + (Math.random() * (maximum - minimum)));

    try {
        await abortableSleep(delay, worker.controller.signal);
        return !worker.stopped;
    } catch {
        return false;
    }
}

function extractCourseIds(payload) {
    const candidates = [
        payload?.data?.courses,
        payload?.data?.data,
        payload?.data,
        payload?.courses,
    ];
    const courses = candidates.find((candidate) => Array.isArray(candidate)) ?? [];

    return [...new Set(courses
        .map((course) => course?.id)
        .filter((id) => Number.isInteger(id) || (typeof id === 'string' && /^\d+$/.test(id))))];
}

function createMetrics() {
    return {
        journeys: 0,
        peakConcurrentUsers: 0,
        requests: 0,
        failures: 0,
        bytes: 0,
        latencies: [],
        failureReasons: {},
        endpoints: {},
    };
}

function recordRequest(label, latencyMs, failure, status, bytes) {
    const endpoint = metrics.endpoints[label] ??= {
        requests: 0,
        failures: 0,
        bytes: 0,
        latencies: [],
        statuses: {},
        failureReasons: {},
    };
    metrics.requests += 1;
    metrics.bytes += bytes;
    metrics.latencies.push(latencyMs);
    endpoint.requests += 1;
    endpoint.bytes += bytes;
    endpoint.latencies.push(latencyMs);

    const statusKey = status === null ? 'none' : String(status);
    endpoint.statuses[statusKey] = (endpoint.statuses[statusKey] ?? 0) + 1;

    if (failure !== null) {
        metrics.failures += 1;
        endpoint.failures += 1;
        metrics.failureReasons[failure] = (metrics.failureReasons[failure] ?? 0) + 1;
        endpoint.failureReasons[failure] = (endpoint.failureReasons[failure] ?? 0) + 1;
    }
}

function recordContractFailure(label, reason) {
    recordRequest(label, 0, reason, null, 0);
}

function buildReport(startedAt, endedAt) {
    const durationSeconds = Math.max(0.001, (endedAt.getTime() - startedAt.getTime()) / 1000);
    const endpoints = Object.fromEntries(
        Object.entries(metrics.endpoints).map(([name, endpoint]) => [name, summarize(endpoint)]),
    );
    const overall = summarize(metrics);
    const checks = {
        p95: {
            actualMs: overall.p95Ms,
            maximumMs: thresholds.p95Ms,
            passed: overall.p95Ms <= thresholds.p95Ms,
        },
        p99: {
            actualMs: overall.p99Ms,
            maximumMs: thresholds.p99Ms,
            passed: overall.p99Ms <= thresholds.p99Ms,
        },
        errorRate: {
            actual: overall.errorRate,
            maximum: thresholds.errorRate,
            passed: overall.errorRate <= thresholds.errorRate,
        },
    };
    const endpointChecks = Object.fromEntries(
        Object.entries(endpoints).map(([name, endpoint]) => [name, {
            p95Passed: endpoint.p95Ms <= thresholds.p95Ms,
            p99Passed: endpoint.p99Ms <= thresholds.p99Ms,
            errorRatePassed: endpoint.errorRate <= thresholds.errorRate,
        }]),
    );
    const thresholdsPassed = Object.values(checks).every((check) => check.passed)
        && Object.values(endpointChecks).every((check) => Object.values(check).every(Boolean));

    return {
        schemaVersion: 1,
        startedAt: startedAt.toISOString(),
        endedAt: endedAt.toISOString(),
        durationSeconds,
        plan,
        summary: {
            virtualUsersCreated: nextWorkerId - 1,
            peakConcurrentUsers: metrics.peakConcurrentUsers,
            journeys: metrics.journeys,
            requestsPerSecond: round(metrics.requests / durationSeconds),
            ...overall,
            checks,
            thresholdsPassed,
        },
        endpoints,
        endpointChecks,
    };
}

function summarize(metric) {
    const sorted = [...metric.latencies].sort((left, right) => left - right);
    const requests = metric.requests;
    const failures = metric.failures;

    return {
        requests,
        failures,
        errorRate: requests === 0 ? 1 : round(failures / requests, 6),
        bytes: metric.bytes,
        averageMs: round(average(sorted)),
        p50Ms: round(percentile(sorted, 0.50)),
        p95Ms: round(percentile(sorted, 0.95)),
        p99Ms: round(percentile(sorted, 0.99)),
        maxMs: round(sorted.at(-1) ?? 0),
        failureReasons: metric.failureReasons,
        ...(metric.statuses ? { statuses: metric.statuses } : {}),
    };
}

function profile(name) {
    if (name === 'smoke') {
        return {
            thinkTimeMs: [100, 250],
            maxJourneysPerUser: 1,
            phases: [
                { name: 'smoke', fromUsers: 1, toUsers: 1, durationMs: 1000 },
            ],
        };
    }

    if (name !== 'target-1000') {
        throw new Error(`Unknown profile: ${name}`);
    }

    return {
        thinkTimeMs: [3000, 8000],
        maxJourneysPerUser: null,
        phases: [
            { name: 'warm-up', fromUsers: 0, toUsers: 100, durationMs: 60_000 },
            { name: 'ramp-300', fromUsers: 100, toUsers: 300, durationMs: 120_000 },
            { name: 'ramp-600', fromUsers: 300, toUsers: 600, durationMs: 180_000 },
            { name: 'ramp-1000', fromUsers: 600, toUsers: 1000, durationMs: 240_000 },
            { name: 'steady-1000', fromUsers: 1000, toUsers: 1000, durationMs: 300_000 },
            { name: 'cool-down', fromUsers: 1000, toUsers: 100, durationMs: 120_000 },
        ],
    };
}

function authorizeTarget(url) {
    const localHosts = new Set(['localhost', '127.0.0.1', '::1']);
    if (localHosts.has(url.hostname)) return;

    const allowedOrigin = String(process.env.LOAD_TEST_ALLOWED_ORIGIN ?? '').replace(/\/$/, '');
    if (
        process.env.LOAD_TEST_ALLOW_REMOTE !== 'I_UNDERSTAND'
        || allowedOrigin !== url.origin
    ) {
        throw new Error(
            'Remote load refused. Set LOAD_TEST_ALLOW_REMOTE=I_UNDERSTAND and '
            + `LOAD_TEST_ALLOWED_ORIGIN=${url.origin} explicitly.`,
        );
    }

    if (
        /(^|\.)rokn\.app$/i.test(url.hostname)
        && process.env.LOAD_TEST_ALLOW_PRODUCTION !== 'ROKN_PRODUCTION_LOAD_APPROVED'
    ) {
        throw new Error(
            'Production load refused. Also set '
            + 'LOAD_TEST_ALLOW_PRODUCTION=ROKN_PRODUCTION_LOAD_APPROVED after the operator window is approved.',
        );
    }
}

function normalizeBaseUrl(value) {
    const url = new URL(value);
    if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('base-url must use http or https');
    }
    url.hash = '';
    url.search = '';
    url.pathname = url.pathname.replace(/\/$/, '');
    return url;
}

function ensureTrailingSlash(url) {
    const copy = new URL(url.toString());
    copy.pathname = `${copy.pathname.replace(/\/$/, '')}/`;
    return copy;
}

function parseArgs(values) {
    const parsed = {};
    for (const value of values) {
        if (value === '--execute') {
            parsed.execute = true;
            continue;
        }
        if (!value.startsWith('--') || !value.includes('=')) {
            throw new Error(`Unsupported argument: ${value}`);
        }
        const separator = value.indexOf('=');
        parsed[value.slice(2, separator)] = value.slice(separator + 1);
    }
    return parsed;
}

function positiveInteger(value, name) {
    const number = Number(value);
    if (!Number.isInteger(number) || number <= 0) {
        throw new Error(`${name} must be a positive integer`);
    }
    return number;
}

function positiveNumber(value, name) {
    const number = Number(value);
    if (!Number.isFinite(number) || number <= 0) {
        throw new Error(`${name} must be a positive number`);
    }
    return number;
}

function rate(value, name) {
    const number = Number(value);
    if (!Number.isFinite(number) || number < 0 || number > 1) {
        throw new Error(`${name} must be between 0 and 1`);
    }
    return number;
}

function percentile(sorted, quantile) {
    if (sorted.length === 0) return 0;
    const index = Math.max(0, Math.ceil(sorted.length * quantile) - 1);
    return sorted[index];
}

function average(values) {
    if (values.length === 0) return 0;
    return values.reduce((sum, value) => sum + value, 0) / values.length;
}

function round(value, places = 2) {
    const power = 10 ** places;
    return Math.round(value * power) / power;
}

function sleep(milliseconds) {
    return new Promise((resolveSleep) => setTimeout(resolveSleep, milliseconds));
}

function abortableSleep(milliseconds, signal) {
    return new Promise((resolveSleep, rejectSleep) => {
        if (signal.aborted) {
            rejectSleep(new Error('aborted'));
            return;
        }
        const timer = setTimeout(() => {
            signal.removeEventListener('abort', onAbort);
            resolveSleep();
        }, milliseconds);
        const onAbort = () => {
            clearTimeout(timer);
            rejectSleep(new Error('aborted'));
        };
        signal.addEventListener('abort', onAbort, { once: true });
    });
}
