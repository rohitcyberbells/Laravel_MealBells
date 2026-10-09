<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every component a controller renders exists, spelled exactly that way.
 *
 * This is a case-sensitivity guard, and it has to work on the machine where the
 * mistake is made. Development happens on macOS, whose filesystem is
 * case-insensitive, so file_exists() and is_dir() answer yes to the wrong case
 * and every ordinary check passes. Linux answers no.
 *
 * That is not hypothetical: Inertia v3 defaults its page path to
 * resource_path('js/pages') while this project uses js/Pages, the two agreed on
 * macOS for months, and twelve tests failed the moment CI ran on Linux for the
 * first time.
 *
 * So nothing here asks the filesystem whether a path exists. The tracked paths
 * come from git, which stores the exact case and compares case-sensitively on
 * every platform, and directory listings are compared literally.
 */
class InertiaPageComponentTest extends TestCase
{
    /**
     * Component names as the controllers actually write them.
     *
     * Read out of the source rather than listed by hand, so a new page is
     * covered the moment it is rendered and cannot be forgotten here.
     *
     * @return array<int, string>
     */
    protected function renderedComponents(): array
    {
        $names = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/Inertia::render\(\s*'([^']+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            $names = array_merge($names, $matches[1]);
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Paths git has tracked, which is the only case-exact source available on a
     * case-insensitive filesystem.
     *
     * @return array<int, string>
     */
    protected function trackedFiles(): array
    {
        exec('git -C '.escapeshellarg(base_path()).' ls-files resources/js 2>/dev/null', $output, $status);

        $this->assertSame(0, $status, 'git ls-files failed, so the tracked case cannot be read');
        $this->assertNotEmpty($output, 'git tracks no files under resources/js');

        return $output;
    }

    public function test_some_components_are_rendered_at_all(): void
    {
        // The control. Without it, a regex that stopped matching would make
        // every assertion below pass over an empty list.
        $this->assertGreaterThan(10, count($this->renderedComponents()));
    }

    /**
     * The one that would have caught the CI failure.
     */
    public function test_every_rendered_component_is_tracked_at_exactly_that_case(): void
    {
        $tracked = $this->trackedFiles();
        $missing = [];

        foreach ($this->renderedComponents() as $component) {
            $expected = 'resources/js/Pages/'.$component.'.vue';

            if (! in_array($expected, $tracked, true)) {
                $missing[] = $component.' (expected '.$expected.')';
            }
        }

        $this->assertSame(
            [],
            $missing,
            "rendered but not tracked at that exact case:\n  ".implode("\n  ", $missing),
        );
    }

    /**
     * The directory Inertia is configured to search is the directory that is
     * really there, compared against a literal listing rather than is_dir().
     */
    public function test_the_configured_page_path_matches_the_real_directory(): void
    {
        $paths = config('inertia.pages.paths');

        $this->assertIsArray($paths);
        $this->assertNotEmpty($paths, 'inertia.pages.paths is empty, so nothing can be found');

        foreach ($paths as $path) {
            $relative = ltrim(str_replace(base_path(), '', $path), '/');
            $segments = explode('/', $relative);
            $cursor = base_path();

            foreach ($segments as $segment) {
                $entries = scandir($cursor) ?: [];

                $this->assertContains(
                    $segment,
                    $entries,
                    "inertia.pages.paths names [{$relative}], but [{$cursor}] contains no entry spelled exactly [{$segment}]. ".
                    'A case-insensitive filesystem will resolve this anyway; Linux will not.',
                );

                $cursor .= '/'.$segment;
            }
        }
    }

    /**
     * And the view finder, which is what the testing assertion actually calls,
     * resolves every one of them.
     */
    public function test_the_view_finder_resolves_every_rendered_component(): void
    {
        $unresolved = [];

        foreach ($this->renderedComponents() as $component) {
            try {
                app('inertia.view-finder')->find($component);
            } catch (\InvalidArgumentException) {
                $unresolved[] = $component;
            }
        }

        $this->assertSame([], $unresolved, 'the view finder cannot resolve: '.implode(', ', $unresolved));
    }

    /**
     * The JS side resolves pages through its own glob, independently of the PHP
     * config - which is why the production build was never affected by the
     * mismatch. The two still have to agree, or a page renders in tests and
     * 404s in the browser.
     */
    public function test_the_javascript_resolver_uses_the_same_directory(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("import.meta.glob('./Pages/**/*.vue')", $app);
        $this->assertStringContainsString('./Pages/${name}.vue', $app);

        // Both literals say Pages, and so does the PHP config.
        $this->assertStringEndsWith('resources/js/Pages', config('inertia.pages.paths')[0]);
    }

    /**
     * Nothing under resources/js is tracked twice at differing case, which git
     * permits and a case-insensitive checkout then collapses into one file -
     * leaving the repository and the working tree disagreeing about which one
     * is there.
     */
    public function test_no_two_tracked_paths_differ_only_by_case(): void
    {
        $tracked = $this->trackedFiles();
        $byLowercase = [];

        foreach ($tracked as $path) {
            $byLowercase[strtolower($path)][] = $path;
        }

        $collisions = array_filter($byLowercase, fn (array $paths) => count($paths) > 1);

        $this->assertSame([], array_values($collisions));
    }
}
