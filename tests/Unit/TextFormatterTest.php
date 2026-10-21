<?php

namespace Tests\Unit;

use App\Support\TextFormatter;
use Tests\TestCase;

class TextFormatterTest extends TestCase
{
    public function test_plain_text_is_escaped_and_mentions_stay_highlighted(): void
    {
        $html = TextFormatter::render('Hello <script>alert(1)</script> @nora');

        $this->assertStringContainsString('Hello &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<span class="font-medium text-blue-600">@nora</span>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href=', $html);
    }

    public function test_urls_become_links_and_trailing_punctuation_stays_outside(): void
    {
        $html = TextFormatter::render('See https://example.com/a?x=1&y=2. And (https://example.com/b) plus www.example.com/docs.');

        $this->assertStringContainsString('href="https://example.com/a?x=1&amp;y=2"', $html);
        $this->assertStringContainsString('https://example.com/a?x=1&amp;y=2</a>.', $html);
        $this->assertStringContainsString('href="https://example.com/b"', $html);
        $this->assertStringContainsString('</a>)', $html);
        $this->assertStringContainsString('href="https://www.example.com/docs"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_markdown_label_can_be_the_link_text(): void
    {
        $html = TextFormatter::render('Read [the spec](https://example.com/spec) and [site](www.example.com).');

        $this->assertStringContainsString('>the spec</a>', $html);
        $this->assertStringContainsString('href="https://example.com/spec"', $html);
        $this->assertStringContainsString('>site</a>', $html);
        $this->assertStringContainsString('href="https://www.example.com"', $html);
        $this->assertStringContainsString('title="https://example.com/spec"', $html);
    }

    public function test_unsafe_schemes_and_attribute_breakout_are_not_linked(): void
    {
        $html = TextFormatter::render('[x](javascript:alert(1)) https://example.com/"onclick="alert(1) data:text/html,hi');

        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&quot;onclick=&quot;alert(1)', $html);
        $this->assertStringContainsString('javascript:alert(1)', $html);
    }

    public function test_balanced_parentheses_in_a_url_are_kept(): void
    {
        $html = TextFormatter::render('https://en.wikipedia.org/wiki/URL_(disambiguation)');

        $this->assertStringContainsString('href="https://en.wikipedia.org/wiki/URL_(disambiguation)"', $html);
    }

    public function test_a_cut_off_url_is_left_as_text(): void
    {
        $html = TextFormatter::render('https://example.com/some/lo...');

        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString('https://example.com/some/lo...', $html);
    }

    public function test_mention_inside_a_url_stays_part_of_the_link(): void
    {
        $html = TextFormatter::render('Profile https://example.com/@nora today');

        $this->assertStringContainsString('href="https://example.com/@nora"', $html);
        $this->assertStringNotContainsString('<span class="font-medium text-blue-600">@nora</span>', $html);
    }

    public function test_linking_a_long_description_stays_cheap(): void
    {
        $text = str_repeat("See https://example.com/doc and @nora. \n", 200);
        $nasty = str_repeat('[label](https://', 400);

        $start = microtime(true);
        $html = '';
        for ($i = 0; $i < 30; $i++) {
            $html = TextFormatter::render($text);
            TextFormatter::render($nasty);
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed);
        $this->assertStringContainsString('href="https://example.com/doc"', $html);
        $this->assertStringContainsString('@nora', $html);
    }
}
