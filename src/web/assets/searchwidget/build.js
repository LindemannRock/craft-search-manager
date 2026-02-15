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

// Build options for SearchModalWidget
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

// Build options for standalone Highlighter utility
const highlighterDir = '../highlighter/dist';
const highlighterOptions = {
    entryPoints: ['src/modules/Highlighter.js'],
    bundle: true,
    format: 'iife',
    globalName: 'SearchManagerHighlighter',
    footer: {
        // Expose named exports on the global: highlight, escapeHtml, escapeRegex, create, parseQuery
        js: [
            'if(typeof window!=="undefined"){',
            '  var _h=SearchManagerHighlighter;',
            '  window.SearchManagerHighlighter={',
            '    highlight:_h.highlightMatches,',
            '    escapeHtml:_h.escapeHtml,',
            '    escapeRegex:_h.escapeRegex,',
            '    create:_h.createHighlighter,',
            '    parseQuery:_h.parseQueryTerms',
            '  };',
            '}',
        ].join(''),
    },
};

async function build() {
    // Ensure highlighter dist directory exists
    if (!fs.existsSync(highlighterDir)) {
        fs.mkdirSync(highlighterDir, { recursive: true });
    }

    try {
        if (isWatch) {
            const [modalCtx, hlCtx] = await Promise.all([
                esbuild.context({
                    ...modalOptions,
                    outfile: 'dist/SearchModalWidget.js',
                    minify: false,
                    sourcemap: true,
                }),
                esbuild.context({
                    ...highlighterOptions,
                    outfile: `${highlighterDir}/SearchManagerHighlighter.js`,
                    minify: false,
                    sourcemap: true,
                }),
            ]);
            await Promise.all([modalCtx.watch(), hlCtx.watch()]);
            console.log('Watching for changes...');
        } else if (isDev) {
            await Promise.all([
                esbuild.build({
                    ...modalOptions,
                    outfile: 'dist/SearchModalWidget.js',
                    minify: false,
                    sourcemap: true,
                }),
                esbuild.build({
                    ...highlighterOptions,
                    outfile: `${highlighterDir}/SearchManagerHighlighter.js`,
                    minify: false,
                    sourcemap: true,
                }),
            ]);
            console.log('Dev build complete!');
        } else {
            // Production build - unminified + minified
            await Promise.all([
                esbuild.build({
                    ...modalOptions,
                    outfile: 'dist/SearchModalWidget.js',
                    minify: false,
                    sourcemap: false,
                }),
                esbuild.build({
                    ...modalOptions,
                    outfile: 'dist/SearchModalWidget.min.js',
                    minify: true,
                    sourcemap: false,
                }),
                esbuild.build({
                    ...highlighterOptions,
                    outfile: `${highlighterDir}/SearchManagerHighlighter.js`,
                    minify: false,
                    sourcemap: false,
                }),
                esbuild.build({
                    ...highlighterOptions,
                    outfile: `${highlighterDir}/SearchManagerHighlighter.min.js`,
                    minify: true,
                    sourcemap: false,
                }),
            ]);
            console.log('Production build complete!');
            console.log('  - SearchModalWidget.js + SearchModalWidget.min.js');
            console.log('  - SearchManagerHighlighter.js + SearchManagerHighlighter.min.js');
        }
    } catch (error) {
        console.error('Build failed:', error);
        process.exit(1);
    }
}

build();
