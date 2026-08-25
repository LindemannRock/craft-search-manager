/**
 * Generated Unicode decimal-digit table for private result normalization.
 *
 * Unicode version: 17.0.0
 * Source: https://www.unicode.org/Public/17.0.0/ucd/UnicodeData.txt
 * UnicodeData.txt SHA-256: 2e1efc1dcb59c575eedf5ccae60f95229f706ee6d031835247d843c11d96470c
 * Decimal digits: 770 in 77 contiguous 0-9 blocks
 * Generator: scripts/generate-unicode-decimal-digits.mjs
 *
 * This module is intentionally not re-exported by the public standalone entry.
 */

const DECIMAL_DIGIT_ZERO_CODE_POINTS = Object.freeze([
    0x30,
    0x660,
    0x6F0,
    0x7C0,
    0x966,
    0x9E6,
    0xA66,
    0xAE6,
    0xB66,
    0xBE6,
    0xC66,
    0xCE6,
    0xD66,
    0xDE6,
    0xE50,
    0xED0,
    0xF20,
    0x1040,
    0x1090,
    0x17E0,
    0x1810,
    0x1946,
    0x19D0,
    0x1A80,
    0x1A90,
    0x1B50,
    0x1BB0,
    0x1C40,
    0x1C50,
    0xA620,
    0xA8D0,
    0xA900,
    0xA9D0,
    0xA9F0,
    0xAA50,
    0xABF0,
    0xFF10,
    0x104A0,
    0x10D30,
    0x10D40,
    0x11066,
    0x110F0,
    0x11136,
    0x111D0,
    0x112F0,
    0x11450,
    0x114D0,
    0x11650,
    0x116C0,
    0x116D0,
    0x116DA,
    0x11730,
    0x118E0,
    0x11950,
    0x11BF0,
    0x11C50,
    0x11D50,
    0x11DA0,
    0x11DE0,
    0x11F50,
    0x16130,
    0x16A60,
    0x16AC0,
    0x16B50,
    0x16D70,
    0x1CCF0,
    0x1D7CE,
    0x1D7D8,
    0x1D7E2,
    0x1D7EC,
    0x1D7F6,
    0x1E140,
    0x1E2F0,
    0x1E4F0,
    0x1E5F1,
    0x1E950,
    0x1FBF0,
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
