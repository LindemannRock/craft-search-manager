import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';

const pluginRoot = path.resolve(import.meta.dirname, '../..');
const handlerPath = path.join(
    pluginRoot,
    'src/templates/_components/_bulk-response-handler.twig',
);

async function loadHandler(responseFactory) {
    const source = (await readFile(handlerPath, 'utf8')).replace(/\{#[\s\S]*?#\}/g, '');
    const observed = {
        notices: [],
        errors: [],
        reloads: 0,
        requests: [],
    };
    const context = {
        Craft: {
            cp: {
                displayNotice(message) {
                    observed.notices.push(message);
                },
                displayError(message) {
                    observed.errors.push(message);
                },
            },
            sendActionRequest(method, endpoint, options) {
                observed.requests.push({method, endpoint, options});
                return responseFactory();
            },
        },
        Promise,
        setTimeout(callback) {
            callback();
        },
        window: {
            location: {
                reload() {
                    observed.reloads++;
                },
            },
        },
    };

    vm.runInNewContext(source, context);
    return {run: context.runSearchManagerBulkAction, observed};
}

const options = {
    endpoint: 'search-manager/example/bulk-enable',
    identifierParam: 'ids',
    ids: [1, 2],
    successTemplate: '{count} resources enabled',
    errorFallback: 'Could not enable resources',
};

test('partial results show successful work and every error before reloading', async () => {
    const {run, observed} = await loadHandler(() => Promise.resolve({
        data: {
            status: 'partial',
            success: false,
            count: 1,
            skipped: 0,
            errors: ['First failed', 'Second failed'],
        },
    }));

    await run(options);

    assert.deepEqual(observed.notices, ['1 resources enabled']);
    assert.deepEqual(observed.errors, ['First failed', 'Second failed']);
    assert.equal(observed.reloads, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(observed.requests)), [{
        method: 'POST',
        endpoint: options.endpoint,
        options: {data: {ids: [1, 2]}},
    }]);
});

test('irreversible partial work reloads even when no item completed', async () => {
    const {run, observed} = await loadHandler(() => Promise.resolve({
        data: {
            status: 'partial',
            success: false,
            count: 0,
            changed: true,
            skipped: 0,
            errors: ['Storage cleared but metadata deletion failed'],
        },
    }));

    await run(options);

    assert.deepEqual(observed.notices, []);
    assert.deepEqual(observed.errors, ['Storage cleared but metadata deletion failed']);
    assert.equal(observed.reloads, 1);
});

test('zero-success failure remains on the page and shows returned errors', async () => {
    const {run, observed} = await loadHandler(() => Promise.resolve({
        data: {
            status: 'failure',
            success: false,
            count: 0,
            skipped: 0,
            errors: ['Persistence failed'],
        },
    }));

    await run(options);

    assert.deepEqual(observed.notices, []);
    assert.deepEqual(observed.errors, ['Persistence failed']);
    assert.equal(observed.reloads, 0);
});

test('stale-only selection remains on the page and uses the resource fallback', async () => {
    const {run, observed} = await loadHandler(() => Promise.resolve({
        data: {
            status: 'failure',
            success: false,
            count: 0,
            skipped: 2,
            errors: [],
        },
    }));

    await run(options);

    assert.deepEqual(observed.errors, [options.errorFallback]);
    assert.equal(observed.reloads, 0);
});

test('server-provided successful wording is preserved', async () => {
    const {run, observed} = await loadHandler(() => Promise.resolve({
        data: {
            status: 'success',
            success: true,
            count: 2,
            skipped: 0,
            errors: [],
            message: '2 API keys enabled',
        },
    }));

    await run(options);

    assert.deepEqual(observed.notices, ['2 API keys enabled']);
    assert.deepEqual(observed.errors, []);
    assert.equal(observed.reloads, 1);
});

test('all affected tables include the one shared handler', async () => {
    const templates = [
        'src/templates/backends/index.twig',
        'src/templates/indices/index.twig',
        'src/templates/promotions/index.twig',
        'src/templates/query-rules/index.twig',
        'src/templates/widgets/index.twig',
        'src/templates/widgets/styles/index.twig',
        'src/templates/api-keys/index.twig',
    ];

    for (const template of templates) {
        const source = await readFile(path.join(pluginRoot, template), 'utf8');
        assert.match(source, /_components\/_bulk-response-handler/);
        assert.doesNotMatch(source, /function runBulk(?:Status)?\(/);
    }
});

test('index deletion uses the shared truthful result path', async () => {
    const source = await readFile(
        path.join(pluginRoot, 'src/templates/indices/index.twig'),
        'utf8',
    );

    assert.match(source, /_components\/_bulk-response-handler/);
    assert.match(source, /endpoint:\s*'search-manager\/indices\/bulk-delete'/);
    assert.match(source, /runSearchManagerBulkAction\(\{/);
    assert.doesNotMatch(source, /function runIndexBulkDelete\(/);
});
