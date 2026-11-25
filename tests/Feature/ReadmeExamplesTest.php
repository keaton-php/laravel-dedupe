<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Feature;

use Keaton\Dedupe\Tests\Support\DocsRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shape of README.md.
 *
 * The README opens with a before and an after: the list cleaned with a collection's unique(),
 * then the same list cleaned with the package, each with the output it prints. One more example
 * follows, and the docs have the rest. The examples are run by DocsExamplesTest, which treats the
 * README as the first source; this class checks the README's shape.
 */
class ReadmeExamplesTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    #[Test]
    public function the_readme_opens_with_a_before_and_an_after_and_the_output_of_each(): void
    {
        $blocks = DocsRunner::blocks($this->readme());

        $this->assertSame(['script', 'script', 'script'], array_column($blocks, 'kind'));
        $this->assertStringNotContainsString('Keaton\\Dedupe', $blocks[0]['code'], 'The before does not use the package');
        $this->assertStringContainsString('Deduper::make()', $blocks[1]['code']);

        foreach ($blocks as $block) {
            $this->assertNotNull($block['output'], 'Every README example must show the output it prints');
        }
    }

    #[Test]
    public function the_before_and_the_after_clean_the_same_list_and_keep_the_same_records(): void
    {
        $blocks = DocsRunner::blocks($this->readme());

        $before = DocsRunner::run($blocks[0]['code'])['vars'];
        $after = DocsRunner::run($blocks[1]['code'])['vars'];

        $this->assertSame($before['signups'], $after['signups']);
        $this->assertSame($before['survivors']->pluck('id')->all(), array_column($after['result']->survivors, 'id'));
        $this->assertSame(2, $after['result']->droppedCount());
    }

    #[Test]
    public function the_readme_sends_readers_to_the_docs_for_the_rest(): void
    {
        $this->assertStringContainsString('https://kevineaton.dev/docs/laravel-dedupe/', $this->readme());
    }

    #[Test]
    public function the_readme_does_not_point_at_example_files_that_no_longer_exist(): void
    {
        $this->assertStringNotContainsString('docs/examples', $this->readme());
    }

    #[Test]
    public function the_readme_stays_short(): void
    {
        $lines = substr_count($this->readme(), "\n");

        $this->assertGreaterThanOrEqual(90, $lines);
        $this->assertLessThanOrEqual(200, $lines);
    }

    #[Test]
    public function the_tests_badge_names_the_workflow_that_runs_the_tests(): void
    {
        $this->assertStringContainsString('/laravel-dedupe/run-tests.yml?branch=1.x', $this->readme());
        $this->assertFileExists(self::ROOT.'/.github/workflows/run-tests.yml');
    }

    #[Test]
    public function every_relative_link_in_the_readme_points_at_a_file_in_the_repo(): void
    {
        preg_match_all('/\]\((?!https?:)([^)#]+)\)/', $this->readme(), $links);

        foreach (['CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'LICENSE.md'] as $footer) {
            $this->assertContains($footer, $links[1], "The README does not link to {$footer}");
        }

        foreach ($links[1] as $link) {
            $this->assertFileExists(self::ROOT.'/'.$link, "The README links to {$link}, which is not in the repo");
        }
    }

    private function readme(): string
    {
        return (string) file_get_contents(self::ROOT.'/README.md');
    }
}
