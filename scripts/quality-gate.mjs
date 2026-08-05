#!/usr/bin/env node

import {existsSync} from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const packageRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const workspaceRoot = path.resolve(packageRoot, '../..');
const workspaceMode = packageRoot === path.join(workspaceRoot, 'plugins/search-manager')
    && existsSync(path.join(workspaceRoot, '.ddev/config.yaml'));

const phpConstituents = [
    {
        id: 'platform-compatibility',
        family: 'platform',
        standalone: ['composer', ['check-platform-reqs', '--no-interaction']],
        workspace: ['ddev', ['exec', 'composer check-platform-reqs --no-interaction']],
    },
    {
        id: 'composer-audit',
        family: 'security-audit',
        standalone: ['bash', ['scripts/composer-audit']],
        workspace: ['ddev', ['exec', 'cd plugins/search-manager && bash scripts/composer-audit']],
    },
    {
        id: 'php-quality',
        family: 'php-static-style',
        standalone: ['composer', ['ci']],
        workspace: ['ddev', ['exec', 'cd plugins/search-manager && composer ci']],
    },
    {
        id: 'test-conventions',
        family: 'test-conventions',
        standalone: ['php', ['scripts/check-test-conventions.php']],
        workspace: ['ddev', ['exec', 'cd plugins/search-manager && php scripts/check-test-conventions.php']],
    },
    {
        id: 'disposable-phpunit',
        family: 'php-runtime',
        standalone: ['php', ['tests/Fixtures/Project/run.php', '--no-progress']],
        workspace: ['ddev', ['exec', 'cd plugins/search-manager && SEARCH_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT=/var/www/html/vendor php tests/Fixtures/Project/run.php --no-progress']],
        standaloneEnvironment: {
            SEARCH_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT: path.join(packageRoot, 'vendor'),
        },
    },
];

const localConstituents = [
    {
        id: 'pre-commit-hook-regressions',
        family: 'hook-routing',
        standalone: ['node', ['--test', 'tests/js/pre-commit-hook.test.mjs']],
    },
    {
        id: 'analytics-javascript',
        family: 'analytics-javascript',
        standalone: ['npm', ['run', 'test:analytics']],
    },
    {
        id: 'widget-javascript',
        family: 'widget-javascript',
        standalone: ['npm', ['test']],
    },
    {
        id: 'static-accessibility',
        family: 'static-accessibility',
        standalone: ['npm', ['run', 'test:a11y']],
    },
    {
        id: 'bulk-response-javascript',
        family: 'bulk-response-javascript',
        standalone: ['npm', ['run', 'test:bulk-response']],
    },
    {
        id: 'orchestration-regressions',
        family: 'aggregate-orchestration',
        standalone: ['node', ['--test', 'tests/js/quality-gate-orchestration.test.mjs']],
    },
];

const constituents = [...phpConstituents, ...localConstituents];
const argumentsList = process.argv.slice(2);
const listOnly = argumentsList.includes('--list');
const probeIndex = argumentsList.indexOf('--probe');
const probeExecutable = probeIndex === -1 ? null : argumentsList[probeIndex + 1];

if (probeIndex !== -1 && (!probeExecutable || !path.isAbsolute(probeExecutable))) {
    console.error('--probe requires an absolute executable path.');
    process.exit(2);
}

if (listOnly) {
    const formatCommand = ([command, commandArguments]) => [command, ...commandArguments].join(' ');
    console.log(JSON.stringify(constituents.map(({id, family, standalone, workspace}) => ({
        id,
        family,
        standalone: formatCommand(standalone),
        workspace: formatCommand(workspace ?? standalone),
    })), null, 2));
    process.exit(0);
}

function commandFor(constituent) {
    if (probeExecutable !== null) {
        return [probeExecutable, [constituent.id, constituent.family], packageRoot, process.env];
    }

    if (workspaceMode && constituent.workspace) {
        return [constituent.workspace[0], constituent.workspace[1], workspaceRoot, process.env];
    }

    const environment = {
        ...process.env,
        ...(constituent.standaloneEnvironment ?? {}),
    };
    return [constituent.standalone[0], constituent.standalone[1], packageRoot, environment];
}

for (const constituent of constituents) {
    const [command, commandArguments, cwd, environment] = commandFor(constituent);
    console.log(`\n==> ${constituent.id}`);
    const result = spawnSync(command, commandArguments, {
        cwd,
        env: environment,
        stdio: 'inherit',
    });
    if (result.error) {
        console.error(`${constituent.id} could not start: ${result.error.message}`);
        process.exit(1);
    }
    if (result.status !== 0) {
        const status = result.status ?? 1;
        console.error(`${constituent.id} failed with exit ${status}.`);
        process.exit(status);
    }
}

console.log('\nComplete Search Manager quality gate passed.');
