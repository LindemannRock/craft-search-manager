#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const UNICODE_VERSION = '17.0.0';
const UNICODE_DATA_URL = `https://www.unicode.org/Public/${UNICODE_VERSION}/ucd/UnicodeData.txt`;
const UNICODE_DATA_SHA256 = '2e1efc1dcb59c575eedf5ccae60f95229f706ee6d031835247d843c11d96470c';
const EXPECTED_DIGIT_COUNT = 770;
const EXPECTED_BLOCK_COUNT = 77;

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const packageDirectory = path.resolve(scriptDirectory, '..');
const modulePath = path.join(
    packageDirectory,
    'src/web/assets/searchwidget/src/modules/UnicodeDecimalDigits.js',
);
const fixturePath = path.join(
    packageDirectory,
    'tests/Fixtures/Highlighting/unicode-decimal-digits-17.0.0.json',
);

const args = process.argv.slice(2);
const checkOnly = args.includes('--check');
const unicodeDataPath = args.find(argument => argument !== '--check');

if (!unicodeDataPath) {
    console.error(`Usage: node scripts/generate-unicode-decimal-digits.mjs <UnicodeData.txt> [--check]`);
    process.exit(1);
}

const unicodeData = fs.readFileSync(unicodeDataPath);
const digest = crypto.createHash('sha256').update(unicodeData).digest('hex');
if (digest !== UNICODE_DATA_SHA256) {
    throw new Error(`Unexpected UnicodeData.txt SHA-256: ${digest}`);
}

const digitRows = unicodeData
    .toString('utf8')
    .trim()
    .split(/\r?\n/)
    .map(line => line.split(';'))
    .filter(fields => fields[2] === 'Nd')
    .map(fields => ({
        codePoint: Number.parseInt(fields[0], 16),
        decimalValue: Number.parseInt(fields[6], 10),
    }));

const zeroCodePoints = [];
for (const row of digitRows) {
    if (row.decimalValue === 0) {
        zeroCodePoints.push(row.codePoint);
        continue;
    }

    const zeroCodePoint = zeroCodePoints.at(-1);
    if (zeroCodePoint === undefined || row.codePoint !== zeroCodePoint + row.decimalValue) {
        throw new Error(`Non-contiguous decimal digit block at U+${row.codePoint.toString(16).toUpperCase()}`);
    }
}

if (digitRows.length !== EXPECTED_DIGIT_COUNT || zeroCodePoints.length !== EXPECTED_BLOCK_COUNT) {
    throw new Error(
        `Expected ${EXPECTED_DIGIT_COUNT} digits in ${EXPECTED_BLOCK_COUNT} blocks; found ${digitRows.length} in ${zeroCodePoints.length}`,
    );
}

const renderedModule = `/**
 * Generated Unicode decimal-digit table for private result normalization.
 *
 * Unicode version: ${UNICODE_VERSION}
 * Source: ${UNICODE_DATA_URL}
 * UnicodeData.txt SHA-256: ${UNICODE_DATA_SHA256}
 * Decimal digits: ${EXPECTED_DIGIT_COUNT} in ${EXPECTED_BLOCK_COUNT} contiguous 0-9 blocks
 * Generator: scripts/generate-unicode-decimal-digits.mjs
 *
 * This module is intentionally not re-exported by the public standalone entry.
 */

const DECIMAL_DIGIT_ZERO_CODE_POINTS = Object.freeze([
${zeroCodePoints.map(codePoint => `    0x${codePoint.toString(16).toUpperCase()},`).join('\n')}
]);

function decimalDigitValue(codePoint) {
    let low = 0;
    let high = DECIMAL_DIGIT_ZERO_CODE_POINTS.length - 1;

    while (low <= high) {
        const middle = Math.floor((low + high) / 2);
        const zeroCodePoint = DECIMAL_DIGIT_ZERO_CODE_POINTS[middle];
        if (codePoint < zeroCodePoint) {
            high = middle - 1;
        } else if (codePoint > zeroCodePoint + 9) {
            low = middle + 1;
        } else {
            return codePoint - zeroCodePoint;
        }
    }

    return null;
}

export function foldUnicodeDecimalDigits(value) {
    return Array.from(String(value), character => {
        const digitValue = decimalDigitValue(character.codePointAt(0));

        return digitValue === null ? character : String(digitValue);
    }).join('');
}
`;

const renderedFixture = `${JSON.stringify({
    unicodeVersion: UNICODE_VERSION,
    source: UNICODE_DATA_URL,
    sha256: UNICODE_DATA_SHA256,
    digitCount: EXPECTED_DIGIT_COUNT,
    blockCount: EXPECTED_BLOCK_COUNT,
    zeroCodePoints: zeroCodePoints.map(codePoint => codePoint.toString(16).toUpperCase().padStart(4, '0')),
}, null, 2)}\n`;

const outputs = [
    [modulePath, renderedModule],
    [fixturePath, renderedFixture],
];

if (checkOnly) {
    let failed = false;
    for (const [outputPath, expected] of outputs) {
        const actual = fs.existsSync(outputPath) ? fs.readFileSync(outputPath, 'utf8') : '';
        if (actual !== expected) {
            console.error(`Generated output is stale: ${path.relative(packageDirectory, outputPath)}`);
            failed = true;
        }
    }
    process.exit(failed ? 1 : 0);
}

for (const [outputPath, contents] of outputs) {
    fs.mkdirSync(path.dirname(outputPath), { recursive: true });
    fs.writeFileSync(outputPath, contents);
    console.log(`Generated ${path.relative(packageDirectory, outputPath)}`);
}
