/**
 * Translation helpers for strings injected by the Craft template.
 */

export function t(translations, key, params = {}) {
    const source = translations && Object.prototype.hasOwnProperty.call(translations, key)
        ? translations[key]
        : key;

    return String(source).replace(/\{([a-zA-Z0-9_]+)\}/g, (match, name) => {
        if (Object.prototype.hasOwnProperty.call(params, name)) {
            return String(params[name]);
        }

        return match;
    });
}
