import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/request_documents.css',
                'resources/js/request_documents.js',
                'resources/css/request_document.css',
                'resources/js/request_document.js',
                'resources/css/front_desk.css',
                'resources/js/front_desk.js',
                'resources/css/hr_manager.css',
                'resources/js/hr_manager.js',
                'resources/css/records_manager.css',
                'resources/js/records_manager.js',
                'resources/css/aboutus.css',
                'resources/css/hris-table.css',
                'resources/js/hris-table.js',
                'resources/js/export-job.js',
                'resources/js/uniform_inspection.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                /**
                 * JS entry chunks: place real JS in assets/js/,
                 * CSS-entry loaders (tiny wrappers Vite creates for
                 * each CSS entry-point) go into a separate folder so
                 * they never collide with JS entries of the same name.
                 * [hash] is appended for content-based cache-busting -
                 * the @vite() manifest resolves the hashed names, so
                 * browsers/CDN always fetch fresh assets after a deploy.
                 */
                entryFileNames(chunkInfo) {
                    const id = (chunkInfo.facadeModuleId || '').replace(/\\/g, '/');
                    if (id.includes('/resources/css/')) {
                        const name = id.split('/').pop().replace('.css', '');
                        return `assets/css-loaders/${name}-[hash].js`;
                    }
                    const name = id.split('/').pop().replace('.js', '');
                    return `assets/js/${name}-[hash].js`;
                },
                chunkFileNames: 'assets/js/[name]-[hash].js',
                /**
                 * Non-JS assets (CSS, fonts, images…): CSS that comes
                 * straight from resources/css/ keeps its folder path;
                 * CSS extracted from JS bundles (vendor libs like
                 * flatpickr) falls back to a generic name. Both carry a
                 * content [hash] for cache-busting.
                 */
                assetFileNames(assetInfo) {
                    const originals = (assetInfo.originalFileNames || [])
                        .map(f => f.replace(/\\/g, '/'));
                    const fromResources = originals.find(f =>
                        f.includes('/resources/css/')
                    );
                    if (fromResources) {
                        const relPath = fromResources.split('/resources/css/').pop();
                        const noExt = relPath.replace(/\.[^.]+$/, '');
                        return `assets/css/${noExt}-[hash].[ext]`;
                    }
                    return `assets/css/[name]-[hash].[ext]`;
                },
            },
        },
    },
    server: {
        host: '0.0.0.0',
        port: Number(process.env.VITE_PORT) || 5173,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        hmr: {
            host: process.env.VITE_HMR_HOST || 'localhost',
            protocol: process.env.VITE_HMR_PROTOCOL || 'ws',
        },
    },
});
