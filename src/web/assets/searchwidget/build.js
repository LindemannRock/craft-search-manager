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

async function build() {
    try {
        if (isWatch) {
            const ctx = await esbuild.context({
                ...modalOptions,
                outfile: 'dist/SearchModalWidget.js',
                minify: false,
                sourcemap: true,
            });
            await ctx.watch();
            console.log('Watching for changes...');
        } else if (isDev) {
            await esbuild.build({
                ...modalOptions,
                outfile: 'dist/SearchModalWidget.js',
                minify: false,
                sourcemap: true,
            });
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
            ]);
            console.log('Production build complete!');
            console.log('  - SearchModalWidget.js + SearchModalWidget.min.js');
        }
    } catch (error) {
        console.error('Build failed:', error);
        process.exit(1);
    }
}

build();
