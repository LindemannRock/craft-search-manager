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

// Base options
const baseOptions = {
    entryPoints: ['src/SearchWidget.js'],
    bundle: true,
    format: 'iife',
    plugins: [cssTextPlugin],
};

async function build() {
    try {
        if (isWatch) {
            // Watch mode - only build dev version
            const ctx = await esbuild.context({
                ...baseOptions,
                outfile: 'dist/SearchWidget.js',
                minify: false,
                sourcemap: true,
            });
            await ctx.watch();
            console.log('Watching for changes...');
        } else if (isDev) {
            // Dev build - unminified with sourcemap
            await esbuild.build({
                ...baseOptions,
                outfile: 'dist/SearchWidget.js',
                minify: false,
                sourcemap: true,
            });
            console.log('Dev build complete!');
        } else {
            // Production build - both versions
            await Promise.all([
                // Unminified (for devMode)
                esbuild.build({
                    ...baseOptions,
                    outfile: 'dist/SearchWidget.js',
                    minify: false,
                    sourcemap: false,
                }),
                // Minified (for production)
                esbuild.build({
                    ...baseOptions,
                    outfile: 'dist/SearchWidget.min.js',
                    minify: true,
                    sourcemap: false,
                }),
            ]);
            console.log('Production build complete! (SearchWidget.js + SearchWidget.min.js)');
        }
    } catch (error) {
        console.error('Build failed:', error);
        process.exit(1);
    }
}

build();
