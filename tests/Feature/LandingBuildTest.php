<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The landing page's build wiring.
 *
 * Astro is its own build tool, not a Vite plugin: it wraps Vite with its own
 * config and its own `astro build`, so it cannot be added to the application's
 * vite.config.js. That is why the landing page is a separate project with a
 * second build command - and why the wiring is worth asserting, because a
 * second command is exactly the kind of thing a deploy forgets.
 *
 * None of this builds anything. It checks that the pieces still point at each
 * other; CI runs the real build.
 */
class LandingBuildTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function packageJson(): array
    {
        return json_decode(file_get_contents(base_path('package.json')), true);
    }

    protected function astroConfig(): string
    {
        $path = base_path('resources/landing/astro.config.mjs');

        $this->assertFileExists($path, 'the landing project has no Astro config');

        return file_get_contents($path);
    }

    public function test_astro_is_a_dev_dependency(): void
    {
        // Dev, not production: the landing page is built ahead of time and
        // shipped as static HTML, so the server never needs Astro itself.
        $package = $this->packageJson();

        $this->assertArrayHasKey('astro', $package['devDependencies']);
        $this->assertArrayNotHasKey('astro', $package['dependencies'] ?? []);
    }

    public function test_the_two_builds_are_separate_commands(): void
    {
        $scripts = $this->packageJson()['scripts'];

        $this->assertArrayHasKey('build', $scripts);
        $this->assertArrayHasKey('build:landing', $scripts);

        $this->assertStringContainsString('astro build', $scripts['build:landing']);
        $this->assertStringContainsString('resources/landing', $scripts['build:landing']);

        // The application build must not try to compile the landing page, and
        // the landing build must not try to compile the application.
        $this->assertStringNotContainsString('astro', $scripts['build']);
    }

    public function test_the_application_vite_config_knows_nothing_about_astro(): void
    {
        $vite = file_get_contents(base_path('vite.config.js'));

        $this->assertStringNotContainsString('astro', strtolower($vite));
    }

    public function test_the_landing_page_source_exists(): void
    {
        $this->assertFileExists(base_path('resources/landing/src/pages/index.astro'));
    }

    /**
     * The HTML is served at `/` while its assets live under public/landing, so
     * without this prefix every asset URL would 404.
     */
    public function test_the_asset_base_matches_where_the_output_is_served_from(): void
    {
        $config = $this->astroConfig();

        $this->assertStringContainsString("base: '/landing'", $config);
        $this->assertStringContainsString("outDir: '../../public/landing'", $config);
        $this->assertStringContainsString(
            'landing',
            (string) config('mealbells.landing_page'),
        );
    }

    /**
     * Styling has to come from the same Tailwind the application uses, or the
     * landing page and the signed-in screens drift apart.
     */
    public function test_it_uses_the_same_tailwind_as_the_application(): void
    {
        $this->assertStringContainsString('@tailwindcss/vite', $this->astroConfig());

        $this->assertArrayHasKey('@tailwindcss/vite', $this->packageJson()['devDependencies']);
    }

    /**
     * Build output, like public/build, plus the two directories Astro
     * regenerates inside its own project. Committing any of them would put
     * generated files in every diff - and the first commit of this work did
     * exactly that, with 29 files of Vite dependency cache, before these rules
     * existed.
     *
     * @return array<string, array{string}>
     */
    public static function generatedPathProvider(): array
    {
        return [
            'the built page' => ['public/landing/index.html'],
            'the types Astro regenerates' => ['resources/landing/.astro/types.d.ts'],
            // The root /node_modules rule is anchored and does not reach a
            // nested one, which is how the cache got committed.
            'the nested dependency cache' => ['resources/landing/node_modules/.vite/deps/package.json'],
        ];
    }

    #[DataProvider('generatedPathProvider')]
    public function test_generated_files_are_not_committed(string $path): void
    {
        // Asked of git rather than grepped out of .gitignore: a commented-out
        // rule still contains the path, so the string check passed with the
        // rule disabled. Proven by mutation.
        exec(
            'git -C '.escapeshellarg(base_path()).' check-ignore -q '.escapeshellarg($path),
            $output,
            $status,
        );

        $this->assertSame(0, $status, "git does not ignore {$path}, so a generated file would be committed");
    }

    /**
     * The root route looks the file up through config, which is what lets both
     * of its behaviours be tested without depending on whether somebody has
     * run the build.
     */
    public function test_the_route_resolves_the_page_through_config(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("config('mealbells.landing_page')", $routes);
        $this->assertStringContainsString('is_file', $routes);
    }

    /**
     * The one thing a deploy is most likely to miss, so it is written down in
     * all three runbooks and run in CI.
     */
    public function test_the_second_build_is_in_the_deploy_steps_and_in_ci(): void
    {
        foreach (['docs/go-live.md', 'docs/deploy.md', 'docs/demo-day.md'] as $doc) {
            $this->assertStringContainsString(
                'build:landing',
                file_get_contents(base_path($doc)),
                "{$doc} does not mention the landing build",
            );
        }

        $workflow = file_get_contents(base_path('.github/workflows/tests.yml'));

        // Both jobs, so a change that breaks the landing build fails CI on
        // either driver rather than surviving to a deploy.
        $this->assertSame(2, substr_count($workflow, 'npm run build:landing'));
    }

    /**
     * The page is served through Laravel, so the application's
     * Content-Security-Policy applies to it - script-src is 'self' and
     * 'unsafe-inline' only. Anything third-party has to be bundled, and the
     * source says so rather than leaving somebody to discover it from a blank
     * console.
     */
    public function test_the_source_warns_that_cdn_scripts_are_blocked(): void
    {
        $page = file_get_contents(base_path('resources/landing/src/pages/index.astro'));

        $this->assertStringContainsString('Content-Security-Policy', $page);
        $this->assertStringContainsString('blocked', $page);
    }
}
