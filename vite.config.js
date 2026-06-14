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
                 */
                entryFileNames(chunkInfo) {
                    const id = (chunkInfo.facadeModuleId || '').replace(/\\/g, '/');
                    if (id.includes('/resources/css/')) {
                        const name = id.split('/').pop().replace('.css', '');
                        return `assets/css-loaders/${name}.js`;
                    }
                    const name = id.split('/').pop().replace('.js', '');
                    return `assets/js/${name}.js`;
                },
                chunkFileNames: 'assets/js/[name].js',
                /**
                 * Non-JS assets (CSS, fonts, images…): CSS that comes
                 * straight from resources/css/ keeps its original name;
                 * CSS extracted from JS bundles (vendor libs like
                 * flatpickr) falls back to a generic name.
                 */
                assetFileNames(assetInfo) {
                    const originals = (assetInfo.originalFileNames || [])
                        .map(f => f.replace(/\\/g, '/'));
                    const fromResources = originals.find(f =>
                        f.includes('/resources/css/')
                    );
                    if (fromResources) {
                        const relPath = fromResources.split('/resources/css/').pop();
                        return `assets/css/${relPath}`;
                    }
                    return `assets/css/[name].[ext]`;
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
