import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import inject from '@rollup/plugin-inject';

const env = loadEnv('development', process.cwd(), '');
const appUrl = new URL(env.APP_URL || 'http://localhost:8000');
const devServerHost = appUrl.hostname;

export default defineConfig({
    base: '', // Keep existing base configuration

    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',

                // Admin pages
                'resources/js/pages/admin/dashboard.js',
                'resources/js/pages/admin/profile.js',
                'resources/js/pages/admin/settings/theme.js',
                'resources/js/pages/admin/settings/patient-classification.js',
                'resources/js/pages/admin/users/secretaries.js',
                'resources/js/pages/admin/users/doctors.js',
                'resources/js/pages/admin/diagnostics/requests.js',
                'resources/js/pages/admin/diagnostics/category.js',
                'resources/js/pages/admin/utilities/chargecat.js',
                'resources/js/pages/admin/utilities/diagcat.js',
                'resources/js/pages/admin/hmo.js',
                'resources/js/pages/admin/consultations/billing.js',
                'resources/js/pages/admin/consultations/settlements.js',
                'resources/js/pages/admin/medicines.js',
                'resources/js/pages/admin/charges/masterlist.js',
                'resources/js/pages/admin/charges/category.js',
                'resources/js/pages/admin/reports.js',
                'resources/js/pages/admin/library/diagnostic.js',
                'resources/js/pages/admin/library/medicines.js',
                'resources/js/pages/admin/philhealth/consultation-linking.js',
                'resources/js/pages/admin/philhealth/enlistment.js',
                'resources/js/pages/admin/philhealth/patient-linking.js',
                'resources/js/pages/admin/philhealth/philhealth-api.js',
                'resources/js/pages/admin/philhealth/profile-validation-codes.js',
                'resources/js/pages/admin/philhealth/reports.js',
                'resources/js/pages/admin/philhealth/soap-edit.js',
                'resources/js/pages/admin/philhealth/soap-form.js',
                'resources/js/pages/admin/philhealth/soap-validation-codes.js',
                'resources/js/pages/admin/philhealth/yakap-management.js',
                'resources/js/pages/admin/stocks/stock_ledger.js',
                'resources/js/pages/admin/stocks/stock_listing.js',
                'resources/js/pages/admin/stocks/stock_management.js',
                'resources/js/pages/admin/stocks/groupings.js',

                // Doctor pages
                'resources/js/pages/doctor/dashboard.js',
                'resources/js/pages/doctor/consultation.js',
                'resources/js/pages/doctor/patients.js',
                'resources/js/pages/doctor/consultation/form.js',

                // Secretary pages
                'resources/js/pages/secretary/queue.js',
                'resources/js/pages/secretary/hmo.js',
            ],
            refresh: true,
        }),

        inject({
            $: 'jquery',
            jQuery: 'jquery',
        }),
    ],

    server: {
        // Allow other devices on the LAN to reach Vite
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,

        // APP_URL provides the reachable host for the dev server on this machine.
        origin: `http://${devServerHost}:5173`,

        hmr: {
            host: devServerHost,
            protocol: 'ws',
            clientPort: 5173,
        },

        watch: {
            ignored: ['**/storage/framework/views/**'],
        },

        cors: {
            origin: [
                appUrl.origin,
                'http://localhost:8000',
                'http://127.0.0.1:8000',
            ],
            methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            allowedHeaders: [
                'Content-Type',
                'Authorization',
                'X-Requested-With',
            ],
        },
    },

    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        return 'vendor';
                    }
                },
            },
        },
    },
});
