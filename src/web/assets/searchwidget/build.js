const esbuild = require('esbuild');
const fs = require('fs');

// Plugin to load CSS as text string
const cssTextPlugin = {
    name: 'css-text',
    setup(build) {
        build.onLoad({ filter: /\.css$/ }, async (args) => {
            const css = await fs.promises.readFile(args.path, 'utf8');
            return {
                contents: `export default ${JSON.stringify(css)};`,
                loader: 'js',
            };
        });
    },
};

const isWatch = process.argv.includes('--watch');
const isDev = process.argv.includes('--dev');

// Base options for legacy widget
const baseOptions = {
    entryPoints: ['src/SearchWidget.js'],
    bundle: true,
    format: 'iife',
    plugins: [cssTextPlugin],
};

// Options for new modal widget (refactored architecture)
const modalOptions = {
    entryPoints: ['src/widgets/SearchModalWidget.js'],
    bundle: true,
    format: 'iife',
    globalName: 'SearchModalWidget',
    plugins: [cssTextPlugin],
    footer: {
        // Auto-register the custom element
        js: `if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}`,
    },
};

async function build() {
    try {
        if (isWatch) {
            // Watch mode - build both widgets in dev mode
            const [legacyCtx, modalCtx] = await Promise.all([
                esbuild.context({
                    ...baseOptions,
                    outfile: 'dist/SearchWidget.js',
                    minify: false,
                    sourcemap: true,
                }),
                esbuild.context({
                    ...modalOptions,
                    outfile: 'dist/SearchModalWidget.js',
                    minify: false,
                    sourcemap: true,
                }),
            ]);
            await Promise.all([legacyCtx.watch(), modalCtx.watch()]);
            console.log('Watching for changes (SearchWidget + SearchModalWidget)...');
        } else if (isDev) {
            // Dev build - unminified with sourcemap
            await Promise.all([
                esbuild.build({
                    ...baseOptions,
                    outfile: 'dist/SearchWidget.js',
                    minify: false,
                    sourcemap: true,
                }),
                esbuild.build({
                    ...modalOptions,
                    outfile: 'dist/SearchModalWidget.js',
                    minify: false,
                    sourcemap: true,
                }),
            ]);
            console.log('Dev build complete! (SearchWidget + SearchModalWidget)');
        } else {
            // Production build - both widgets, both versions
            await Promise.all([
                // Legacy widget - unminified
                esbuild.build({
                    ...baseOptions,
                    outfile: 'dist/SearchWidget.js',
                    minify: false,
                    sourcemap: false,
                }),
                // Legacy widget - minified
                esbuild.build({
                    ...baseOptions,
                    outfile: 'dist/SearchWidget.min.js',
                    minify: true,
                    sourcemap: false,
                }),
                // New modal widget - unminified
                esbuild.build({
                    ...modalOptions,
                    outfile: 'dist/SearchModalWidget.js',
                    minify: false,
                    sourcemap: false,
                }),
                // New modal widget - minified
                esbuild.build({
                    ...modalOptions,
                    outfile: 'dist/SearchModalWidget.min.js',
                    minify: true,
                    sourcemap: false,
                }),
            ]);
            console.log('Production build complete!');
            console.log('  - SearchWidget.js + SearchWidget.min.js (legacy)');
            console.log('  - SearchModalWidget.js + SearchModalWidget.min.js (new)');
        }
    } catch (error) {
        console.error('Build failed:', error);
        process.exit(1);
    }
}

build();
