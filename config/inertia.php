<?php

return [
    /*
     * Where the page components live.
     *
     * Inertia v3's own default is resource_path('js/pages'), lowercase. This
     * project capitalises it - resources/js/Pages, alongside resources/js/Layouts
     * - and app.js resolves pages through import.meta.glob('./Pages/ ** / *.vue'),
     * so the capital is what actually exists on disk.
     *
     * The two disagreed silently for as long as development happened on macOS,
     * because a case-insensitive filesystem resolves 'js/pages' to 'js/Pages'
     * and is_dir() returns true. On Linux it does not, the view finder is left
     * with no usable path, and every assertInertia(...)->component(...) fails
     * with "Inertia page component file [...] does not exist" - which is how
     * twelve tests failed on the first CI run and none had ever failed locally.
     *
     * Only the rendered page is affected in tests: 'pages.ensure_pages_exist'
     * below stays false, so Inertia::render never consults this, and the
     * browser resolves pages through Vite's glob rather than through PHP. The
     * production build was never at risk.
     */
    'pages' => [
        /*
         * Left false, which is the package default. Turning it on would check
         * the component exists on every render, in production, on a hot path -
         * and the test-time check plus InertiaPageComponentTest already catch a
         * missing or misnamed component before it ships.
         */
        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/Pages'),
        ],

        /*
         * The package's full list, kept rather than narrowed to 'vue'. This is
         * a Vue project, but trimming it would mean a page added in any other
         * extension silently fails to resolve - and the failure would surface
         * as "component does not exist", which points nowhere near the cause.
         */
        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],

    /*
     * On, so a test that asserts a component by name fails when the file is
     * missing or misnamed rather than passing on a string comparison.
     */
    'testing' => [
        'ensure_pages_exist' => true,
    ],
];
