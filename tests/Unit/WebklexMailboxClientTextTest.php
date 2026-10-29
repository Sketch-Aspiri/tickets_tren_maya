<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\WebklexMailboxClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Webklex\PHPIMAP\ClientManager;

class WebklexMailboxClientTextTest extends TestCase
{
    private function call(string $method, string $arg): string
    {
        $client = new WebklexMailboxClient(new ClientManager([]));
        $reflection = new ReflectionMethod($client, $method);

        return $reflection->invoke($client, $arg);
    }

    public function test_decodes_multiple_rfc2047_words_in_a_subject(): void
    {
        $subject = '=?UTF-8?B?RW4gZWwgaW50ZXJpb3I6IDcuNTAwIHJhem9uZXMgcGFyYQ==?= =?UTF-8?B?IGxhIHByw7N4aW1h?=';

        $this->assertSame('En el interior: 7.500 razones para la próxima', $this->call('decodeHeaderText', $subject));
    }

    public function test_leaves_plain_subjects_untouched(): void
    {
        $this->assertSame('Falla en impresora', $this->call('decodeHeaderText', 'Falla en impresora'));
    }

    public function test_html_to_text_drops_style_blocks_and_keeps_paragraphs(): void
    {
        $html = '<html><head><style>@font-face { font-family: X; }</style></head>'
            .'<body><p>Hola&nbsp;mundo</p><p>Segunda línea</p><script>alert(1)</script></body></html>';

        $text = $this->call('htmlToText', $html);

        $this->assertStringNotContainsString('font-face', $text);
        $this->assertStringNotContainsString('alert', $text);
        $this->assertStringContainsString("Hola mundo\nSegunda línea", $text);
    }
}
