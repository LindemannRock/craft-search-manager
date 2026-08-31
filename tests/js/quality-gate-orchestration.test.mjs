import assert from 'node:assert/strict';
import {execFileSync, spawnSync} from 'node:child_process';
import {chmodSync, cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

const pluginRoot = path.resolve(import.meta.dirname, '../..');
const gatePath = path.join(pluginRoot, 'scripts/quality-gate.mjs');
const expectedIds = [
    'platform-compatibility',
    'composer-audit',
    'php-quality',
    'test-conventions',
    'disposable-phpunit',
    'pre-commit-hook-regressions',
    'analytics-javascript',
    'widget-javascript',
    'static-accessibility',
    'bulk-response-javascript',
    'orchestration-regressions',
];

function definitions() {
    return JSON.parse(execFileSync('node', [gatePath, '--list'], {
        cwd: pluginRoot,
        encoding: 'utf8',
    }));
}

function probeFixture() {
    const root = mkdtempSync(path.join(os.tmpdir(), 'search-manager-gate-'));
    const probePath = path.join(root, 'probe.sh');
    const logPath = path.join(root, 'constituents.log');
    writeFileSync(probePath, `#!/bin/sh
printf '%s:%s\\n' "$1" "$2" >> "$SEARCH_MANAGER_GATE_PROBE_LOG"
if [ "$1" = "$SEARCH_MANAGER_GATE_FAIL_ID" ]; then exit 71; fi
exit 0
`, {mode: 0o700});
    chmodSync(probePath, 0o700);

    return {
        root,
        probePath,
        logPath,
        run(failId = '') {
            return spawnSync('node', [gatePath, '--probe', probePath], {
                cwd: pluginRoot,
                encoding: 'utf8',
                env: {
                    ...process.env,
                    SEARCH_MANAGER_GATE_PROBE_LOG: logPath,
                    SEARCH_MANAGER_GATE_FAIL_ID: failId,
                },
            });
        },
        ids() {
            try {
                return readFileSync(logPath, 'utf8').trim().split('\n').filter(Boolean).map((line) => line.split(':')[0]);
            } catch {
                return [];
            }
        },
        reset() {
            writeFileSync(logPath, '');
        },
        cleanup() {
            rmSync(root, {recursive: true, force: true});
        },
    };
}

function actWorkflow(steps) {
    return `jobs:
  quality-gates:
    container: node:24-bookworm
    steps:
${steps.join('\n')}
`;
}

const checkoutStep = '      - uses: actions/checkout@v6';
const trustStep = `      - name: Trust checked-out repository
        run: git config --global --add safe.directory "$GITHUB_WORKSPACE"`;
const qualityGateStep = `      - name: Complete package quality gate
        run: composer quality-gate`;

function actFailureFixture(workflow = actWorkflow([checkoutStep, trustStep, qualityGateStep])) {
    const root = mkdtempSync(path.join(os.tmpdir(), 'search-manager-act-'));
    const binRoot = path.join(root, 'bin');
    const resourceRoot = path.join(root, 'run-owned-act-resources');
    const logPath = path.join(root, 'act-arguments.log');
    mkdirSync(path.join(root, '.github/workflows'), {recursive: true});
    mkdirSync(path.join(root, 'scripts'), {recursive: true});
    mkdirSync(binRoot, {recursive: true});
    mkdirSync(resourceRoot, {recursive: true});
    cpSync(path.join(pluginRoot, 'scripts/act-quality-gates'), path.join(root, 'scripts/act-quality-gates'));
    writeFileSync(path.join(root, '.github/workflows/ci.yml'), workflow);
    const fakeAct = path.join(binRoot, 'act');
    writeFileSync(fakeAct, `#!/bin/sh
printf '%s\n' "$*" > "$SEARCH_MANAGER_ACT_ARGUMENT_LOG"
touch "$SEARCH_MANAGER_ACT_RESOURCE_ROOT/job-container"
touch "$SEARCH_MANAGER_ACT_RESOURCE_ROOT/service-container"
touch "$SEARCH_MANAGER_ACT_RESOURCE_ROOT/network"
touch "$SEARCH_MANAGER_ACT_RESOURCE_ROOT/volume"
case " $* " in
  *" --rm "*) rm -f "$SEARCH_MANAGER_ACT_RESOURCE_ROOT"/* ;;
esac
exit 73
`, {mode: 0o700});
    chmodSync(fakeAct, 0o700);

    return {
        root,
        resourceRoot,
        logPath,
        run() {
            return spawnSync('/bin/bash', ['scripts/act-quality-gates'], {
                cwd: root,
                encoding: 'utf8',
                env: {
                    ...process.env,
                    PATH: `${binRoot}:/usr/bin:/bin`,
                    SEARCH_MANAGER_ACT_ARGUMENT_LOG: logPath,
                    SEARCH_MANAGER_ACT_RESOURCE_ROOT: resourceRoot,
                },
            });
        },
        cleanup() {
            rmSync(root, {recursive: true, force: true});
        },
    };
}

test('aggregate declares every approved constituent exactly once', () => {
    const declared = definitions();
    assert.deepEqual(declared.map(({id}) => id), expectedIds);
    assert.equal(new Set(declared.map(({family}) => family)).size, declared.length);
    assert.equal(declared.find(({id}) => id === 'composer-audit').standalone, 'bash scripts/composer-audit');
    assert.equal(declared.find(({id}) => id === 'composer-audit').workspace, 'ddev exec cd plugins/search-manager && bash scripts/composer-audit');
    assert.equal(declared.find(({id}) => id === 'platform-compatibility').workspace, 'ddev exec composer check-platform-reqs --no-interaction');
    assert.match(declared.find(({id}) => id === 'php-quality').workspace, /ddev exec .*composer ci/);
    assert.match(declared.find(({id}) => id === 'disposable-phpunit').workspace, /Fixtures\/Project\/run\.php/);
    assert.match(declared.find(({id}) => id === 'bulk-response-javascript').standalone, /npm run test:bulk-response/);
    assert.doesNotMatch(JSON.stringify(declared), /postgres|test:a11y:live|provider/i);
    const audit = readFileSync(path.join(pluginRoot, 'scripts/composer-audit'), 'utf8');
    assert.match(audit, /composer audit --abandoned=report/);
    assert.match(audit, /composer update --no-install --no-scripts/);
});

test('Composer declares the release dependency and directly invoked PHPStan floors', () => {
    const composer = JSON.parse(readFileSync(path.join(pluginRoot, 'composer.json'), 'utf8'));
    assert.equal(composer.require['lindemannrock/craft-plugin-base'], '^5.38.2');
    assert.equal(composer.require['lindemannrock/craft-logging-library'], '^5.18.2');
    assert.equal(composer['require-dev']['craftcms/phpstan'], 'dev-main');
    assert.equal(composer['require-dev']['phpstan/phpstan'], '^1.12.33');
});

test('canonical Composer quality gate disables only its process timeout', () => {
    const composer = JSON.parse(readFileSync(path.join(pluginRoot, 'composer.json'), 'utf8'));
    assert.deepEqual(composer.scripts['quality-gate'], [
        'Composer\\Config::disableProcessTimeout',
        'node scripts/quality-gate.mjs',
    ]);
    for (const [name, script] of Object.entries(composer.scripts)) {
        if (name !== 'quality-gate') {
            assert.doesNotMatch(JSON.stringify(script), /disableProcessTimeout/);
        }
    }
});

test('successful aggregate invokes every constituent in canonical order', () => {
    const current = probeFixture();
    try {
        const result = current.run();
        assert.equal(result.status, 0, result.stderr);
        assert.deepEqual(current.ids(), expectedIds);
    } finally {
        current.cleanup();
    }
});

test('every constituent failure makes the aggregate nonzero', async (context) => {
    const current = probeFixture();
    try {
        for (const id of expectedIds) {
            await context.test(id, () => {
                current.reset();
                const result = current.run(id);
                assert.equal(result.status, 71, `${id}\n${result.stdout}\n${result.stderr}`);
                assert.equal(current.ids().at(-1), id);
                assert.match(result.stderr, new RegExp(`${id} failed with exit 71`));
            });
        }
    } finally {
        current.cleanup();
    }
});

test('CI and Act select the same aggregate authority', () => {
    const workflow = readFileSync(path.join(pluginRoot, '.github/workflows/ci.yml'), 'utf8');
    const act = readFileSync(path.join(pluginRoot, 'scripts/act-quality-gates'), 'utf8');
    const exactTrust = /^\s*run:\s+git config --global --add safe\.directory "\$GITHUB_WORKSPACE"\s*$/gm;
    assert.equal((workflow.match(/run:\s+composer quality-gate/g) ?? []).length, 1);
    assert.equal((workflow.match(exactTrust) ?? []).length, 1);
    assert.equal((workflow.match(/safe\.directory/g) ?? []).length, 1);
    assert.doesNotMatch(workflow, /safe\.directory[^\n]*\*/);
    const checkoutPosition = workflow.indexOf('uses: actions/checkout@v6');
    const trustPosition = workflow.indexOf('run: git config --global --add safe.directory "$GITHUB_WORKSPACE"');
    const qualityGatePosition = workflow.indexOf('run: composer quality-gate');
    assert.ok(checkoutPosition !== -1 && checkoutPosition < trustPosition);
    assert.ok(trustPosition < qualityGatePosition);
    assert.doesNotMatch(workflow, /run:\s+composer (?:phpstan|check-cs|test|ci:full)/);
    assert.match(workflow, /container:\s+node:24-bookworm/);
    assert.match(workflow, /ignore-cache:\s+\$\{\{ env\.ACT \}\}/);
    for (const service of ['db', 'redis', 'redis2']) {
        assert.match(workflow, new RegExp(`^\\s{6}${service}:`, 'm'));
    }
    assert.match(act, /-W \.github\/workflows\/ci\.yml/);
    assert.match(act, /-j quality-gates/);
    assert.match(act, /composer quality-gate/);
    assert.match(act, /^\s*--rm\s*$/m);
});

test('Act preflight rejects invalid workspace trust contracts before launching Act', async (context) => {
    const invalidContracts = [
        {
            name: 'missing trust',
            workflow: actWorkflow([checkoutStep, qualityGateStep]),
            error: /trust exactly/,
        },
        {
            name: 'duplicate trust',
            workflow: actWorkflow([checkoutStep, trustStep, trustStep, qualityGateStep]),
            error: /trust exactly/,
        },
        {
            name: 'wrong directory',
            workflow: actWorkflow([
                checkoutStep,
                '      - run: git config --global --add safe.directory "/workspace"',
                qualityGateStep,
            ]),
            error: /trust exactly/,
        },
        {
            name: 'trust before checkout',
            workflow: actWorkflow([trustStep, checkoutStep, qualityGateStep]),
            error: /after checkout and before composer quality-gate/,
        },
        {
            name: 'trust after quality gate',
            workflow: actWorkflow([checkoutStep, qualityGateStep, trustStep]),
            error: /after checkout and before composer quality-gate/,
        },
        {
            name: 'wildcard trust',
            workflow: actWorkflow([
                checkoutStep,
                trustStep,
                '      - run: git config --global --add safe.directory "*"',
                qualityGateStep,
            ]),
            error: /never use wildcard safe\.directory trust/,
        },
    ];

    for (const invalid of invalidContracts) {
        await context.test(invalid.name, () => {
            const current = actFailureFixture(invalid.workflow);
            try {
                const result = current.run();
                assert.notEqual(result.status, 0);
                assert.match(result.stderr, invalid.error);
                assert.equal(existsSync(current.logPath), false);
                assert.deepEqual(readdirSync(current.resourceRoot), []);
            } finally {
                current.cleanup();
            }
        });
    }
});

test('controlled Act failure stays nonzero and removes every run-owned resource', () => {
    const current = actFailureFixture();
    try {
        const result = current.run();
        assert.equal(result.status, 73, result.stderr);
        assert.match(readFileSync(current.logPath, 'utf8'), /(?:^|\s)--rm(?:\s|$)/);
        assert.deepEqual(readdirSync(current.resourceRoot), []);
    } finally {
        current.cleanup();
    }
});
