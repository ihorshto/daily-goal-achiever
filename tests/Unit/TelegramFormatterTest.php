<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Telegram\TelegramFormatter;
use PHPUnit\Framework\TestCase;

final class TelegramFormatterTest extends TestCase
{
    public function test_quotes_and_apostrophes_are_not_turned_into_entities_telegram_cannot_parse(): void
    {
        $html = TelegramFormatter::toHtml("I'm your \"companion\" — what's next?");

        $this->assertSame("I'm your \"companion\" — what's next?", $html);
        $this->assertStringNotContainsString('&apos;', $html);
        $this->assertStringNotContainsString('&#039;', $html);
    }

    public function test_markup_characters_are_escaped_and_allowed_subset_is_restored(): void
    {
        $this->assertSame(
            '<b>Ship</b> a &lt; b &amp;&amp; c &gt; d <code>x&lt;y</code>',
            TelegramFormatter::toHtml('**Ship** a < b && c > d `x<y`'),
        );
    }

    public function test_literal_entities_in_model_text_are_shown_as_typed(): void
    {
        $this->assertSame('&amp;apos; &amp;lt;', TelegramFormatter::toHtml('&apos; &lt;'));
    }

    public function test_user_supplied_tags_are_neutralized(): void
    {
        $this->assertSame('&lt;a href="x"&gt;hi&lt;/a&gt;', TelegramFormatter::toHtml('<a href="x">hi</a>'));
    }
}
