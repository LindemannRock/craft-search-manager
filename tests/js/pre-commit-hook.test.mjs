import assert from 'node:assert/strict';
import {execFileSync, spawnSync} from 'node:child_process';
import {chmodSync, cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

const pluginRoot = path.resolve(import.meta.dirname, '../..');
const hookSource = path.join(pluginRoot, '.githooks/pre-commit');

function executable(pathname, source) {
    writeFileSync(pathname, source, {mode: 0o700});
    chmodSync(pathname, 0o700);
}

function fixture({workspace = false, activePhpVersion = '8.3.30', ddevExit = 0, platformExit = 0, qualityExit = 0, ciExit = 0} = {}) {
    const root = mkdtempSync(path.join(os.tmpdir(), 'search-manager-hook-'));
    const packageRoot = workspace
        ? path.join(root, 'plugins/search-manager')
        : path.join(root, 'search-manager');
    const binRoot = path.join(root, 'bin');
    const logPath = path.join(root, 'commands.log');
    mkdirSync(path.join(packageRoot, '.githooks'), {recursive: true});
    mkdirSync(binRoot, {recursive: true});
    cpSync(hookSource, path.join(packageRoot, '.githooks/pre-commit'));
    writeFileSync(path.join(packageRoot, 'sentinel.txt'), 'must remain byte-identical\n');
    if (workspace) {
        mkdirSync(path.join(root, '.ddev'), {recursive: true});
        writeFileSync(path.join(root, '.ddev/config.yaml'), 'php_version: "8.3"\n');
    }

    executable(path.join(binRoot, 'ddev'), `#!/bin/sh
printf 'ddev:%s\\n' "$*" >> "$SEARCH_MANAGER_HOOK_TEST_LOG"
exit ${ddevExit}
`);
    executable(path.join(binRoot, 'php'), `#!/bin/sh
printf 'php:%s\\n' "$*" >> "$SEARCH_MANAGER_HOOK_TEST_LOG"
if [ "$1" = "-r" ]; then printf '%s' '${activePhpVersion}'; exit 0; fi
if [ "$1" = "scripts/check-quality-platform.php" ]; then exit ${qualityExit}; fi
exit 92
`);
    executable(path.join(binRoot, 'composer'), `#!/bin/sh
printf 'composer:%s\\n' "$*" >> "$SEARCH_MANAGER_HOOK_TEST_LOG"
if [ "$1" = "check-platform-reqs" ]; then exit ${platformExit}; fi
if [ "$1" = "ci" ]; then exit ${ciExit}; fi
exit 91
`);

    const environment = {
        ...process.env,
        PATH: `${binRoot}:/usr/bin:/bin`,
        SEARCH_MANAGER_HOOK_TEST_LOG: logPath,
    };
    const hookPath = path.join(packageRoot, '.githooks/pre-commit');
    const snapshot = () => execFileSync('/usr/bin/find', [packageRoot, '-type', 'f', '-exec', '/usr/bin/shasum', '-a', '256', '{}', ';'], {encoding: 'utf8'})
        .trim()
        .split('\n')
        .sort()
        .join('\n');

    return {
        root,
        packageRoot,
        logPath,
        before: snapshot(),
        run() {
            return spawnSync('/bin/bash', [hookPath], {
                cwd: packageRoot,
                encoding: 'utf8',
                env: environment,
            });
        },
        log() {
            try {
                return readFileSync(logPath, 'utf8');
            } catch {
                return '';
            }
        },
        snapshot,
        cleanup() {
            rmSync(root, {recursive: true, force: true});
        },
    };
}

function withoutMutation(current) {
    assert.equal(current.snapshot(), current.before);
    const unexpected = readdirSync(current.packageRoot).filter((name) => name !== '.githooks' && name !== 'sentinel.txt');
    assert.deepEqual(unexpected, []);
}

test('workspace routes composer ci only through the configured DDEV runtime', () => {
    const current = fixture({workspace: true});
    try {
        const result = current.run();
        assert.equal(result.status, 0, result.stderr);
        assert.match(current.log(), /^ddev:exec cd plugins\/search-manager && php -r /m);
        assert.match(current.log(), /pre-commit using DDEV PHP .*PHP_VERSION/);
        assert.match(current.log(), /&& composer ci$/m);
        assert.doesNotMatch(current.log(), /PHP_(?:MAJOR|MINOR)_VERSION|PHP 8\.3/);
        assert.doesNotMatch(current.log(), /^(?:php|composer):/m);
        assert.doesNotMatch(current.log(), /\bphpunit\b|\bnpm\b|\bnode\b|Fixtures\/Project|\bact\b/i);
        withoutMutation(current);
    } finally {
        current.cleanup();
    }
});

test('workspace DDEV failure propagates without host fallback or mutation', () => {
    const current = fixture({workspace: true, ddevExit: 37});
    try {
        const result = current.run();
        assert.equal(result.status, 37);
        assert.match(result.stderr, /failed in DDEV \(exit 37\)/);
        assert.doesNotMatch(current.log(), /^(?:php|composer):/m);
        withoutMutation(current);
    } finally {
        current.cleanup();
    }
});

test('standalone validates platform and installed quality tools before composer ci', () => {
    const current = fixture();
    try {
        const result = current.run();
        assert.equal(result.status, 0, result.stderr);
        assert.match(current.log(), /^composer:check-platform-reqs --no-interaction$/m);
        assert.match(current.log(), /^php:scripts\/check-quality-platform\.php$/m);
        assert.match(current.log(), /^composer:ci$/m);
        assert.doesNotMatch(current.log(), /^ddev:/m);
        assert.doesNotMatch(current.log(), /\bphpunit\b|\bnpm\b|\bnode\b|Fixtures\/Project|\bact\b/i);
        withoutMutation(current);
    } finally {
        current.cleanup();
    }
});

test('standalone rejects an incompatible installed quality tool before composer ci', () => {
    const current = fixture({activePhpVersion: '8.5.9', qualityExit: 79});
    try {
        const result = current.run();
        assert.equal(result.status, 79);
        assert.match(result.stderr, /incompatible with active PHP 8\.5\.9 \(exit 79\)/);
        assert.match(current.log(), /^composer:check-platform-reqs --no-interaction$/m);
        assert.match(current.log(), /^php:scripts\/check-quality-platform\.php$/m);
        assert.doesNotMatch(current.log(), /^composer:ci$/m);
        withoutMutation(current);
    } finally {
        current.cleanup();
    }
});

test('standalone platform failure propagates before composer ci', () => {
    const current = fixture({platformExit: 42});
    try {
        const result = current.run();
        assert.equal(result.status, 42);
        assert.match(result.stderr, /platform validation failed \(exit 42\)/);
        assert.doesNotMatch(current.log(), /^php:scripts\/check-quality-platform\.php$/m);
        assert.doesNotMatch(current.log(), /^composer:ci$/m);
        withoutMutation(current);
    } finally {
        current.cleanup();
    }
});

test('standalone composer ci failure propagates without mutation', () => {
    const current = fixture({ciExit: 43});
    try {
        const result = current.run();
        assert.equal(result.status, 43);
        assert.match(result.stderr, /pre-commit checks failed \(exit 43\)/);
        withoutMutation(current);
    } finally {
        current.cleanup();
    }
});
