const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');

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

const productionOptions = {
    minify: true,
    sourcemap: false,
    tsconfigRaw: {
        compilerOptions: {
            alwaysStrict: false,
        },
    },
};

function getModalOptions(sourceRoot) {
    return {
        entryPoints: [path.join(sourceRoot, 'widgets/SearchModalWidget.js')],
        bundle: true,
        format: 'iife',
        globalName: 'SearchModalWidget',
        plugins: [cssTextPlugin],
        footer: {
            // Auto-register the custom element
            js: `if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}`,
        },
    };
}

function getHighlighterOptions(sourceRoot) {
    return {
        entryPoints: [path.join(sourceRoot, 'entries/SearchManagerHighlighter.js')],
        bundle: true,
        format: 'iife',
        globalName: 'SearchManagerHighlighter',
        footer: {
            // Expose named exports on the documented global surface.
            js: [
                'if(typeof window!=="undefined"){',
                '  var _h=SearchManagerHighlighter;',
                '  window.SearchManagerHighlighter={',
                '    highlight:_h.highlightMatches,',
                '    escapeHtml:_h.escapeHtml,',
                '    escapeRegex:_h.escapeRegex,',
                '    create:_h.createHighlighter,',
                '    parseQuery:_h.parseQueryTerms,',
                '    getHitTerms:_h.getHitHighlightTerms,',
                '    highlightFromUrl:_h.highlightFromUrl',
                '  };',
                '}',
            ].join(''),
        },
    };
}

function getOutputPaths(outputRoot = path.resolve(__dirname, '..')) {
    return {
        modal: path.join(outputRoot, 'searchwidget/dist/SearchModalWidget.js'),
        highlighter: path.join(outputRoot, 'highlighter/dist/SearchManagerHighlighter.js'),
    };
}

async function build({
    mode = process.argv.includes('--watch') ? 'watch' : (process.argv.includes('--dev') ? 'dev' : 'production'),
    sourceRoot = path.join(__dirname, 'src'),
    outputRoot = path.resolve(__dirname, '..'),
    quiet = false,
} = {}) {
    const outputPaths = getOutputPaths(outputRoot);
    const modalOptions = getModalOptions(sourceRoot);
    const highlighterOptions = getHighlighterOptions(sourceRoot);
    fs.mkdirSync(path.dirname(outputPaths.modal), { recursive: true });
    fs.mkdirSync(path.dirname(outputPaths.highlighter), { recursive: true });

    if (mode === 'watch') {
        const [modalCtx, hlCtx] = await Promise.all([
            esbuild.context({
                ...modalOptions,
                outfile: outputPaths.modal,
                minify: false,
                sourcemap: true,
            }),
            esbuild.context({
                ...highlighterOptions,
                outfile: outputPaths.highlighter,
                minify: false,
                sourcemap: true,
            }),
        ]);
        await Promise.all([modalCtx.watch(), hlCtx.watch()]);
        if (!quiet) console.log('Watching for changes...');
    } else if (mode === 'dev') {
        await Promise.all([
            esbuild.build({
                ...modalOptions,
                outfile: outputPaths.modal,
                minify: false,
                sourcemap: true,
            }),
            esbuild.build({
                ...highlighterOptions,
                outfile: outputPaths.highlighter,
                minify: false,
                sourcemap: true,
            }),
        ]);
        if (!quiet) console.log('Dev build complete!');
    } else {
        // Production build - single shipped artifact per output
        await Promise.all([
            esbuild.build({
                ...modalOptions,
                outfile: outputPaths.modal,
                ...productionOptions,
            }),
            esbuild.build({
                ...highlighterOptions,
                outfile: outputPaths.highlighter,
                ...productionOptions,
            }),
        ]);
        if (!quiet) {
            console.log('Production build complete!');
            console.log('  - SearchModalWidget.js');
            console.log('  - SearchManagerHighlighter.js');
        }
    }

    return outputPaths;
}

if (require.main === module) {
    build().catch((error) => {
        console.error('Build failed:', error);
        process.exitCode = 1;
    });
}

module.exports = { build, getOutputPaths };
