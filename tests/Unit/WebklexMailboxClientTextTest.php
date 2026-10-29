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

    public function test_decodes_an_rfc2047_attachment_name_split_in_two_words(): void
    {
        $name = '=?iso-8859-1?Q?29_Bac=5FEncargado_Accidental_de_la_Estaci=F3n_2_al_6_Nov.?= =?iso-8859-1?Q?_26.pdf?=';

        $this->assertSame('29 Bac_Encargado Accidental de la Estación 2 al 6 Nov. 26.pdf', $this->call('decodeHeaderText', $name));
    }

    public function test_message_id_is_read_from_raw_headers_without_angle_brackets(): void
    {
        $header = "From: a@example.com\r\nMessage-ID: <CAAd9rH=abc+1@mail.gmail.com>\r\nSubject: Hola\r\n";

        $this->assertSame('CAAd9rH=abc+1@mail.gmail.com', $this->messageId($header));
    }

    public function test_message_id_accepts_a_folded_header_and_any_case(): void
    {
        $this->assertSame('x1@outlook.com', $this->messageId("message-id:\r\n <x1@outlook.com>\r\n"));
    }

    public function test_missing_message_id_is_null(): void
    {
        $this->assertNull($this->messageId("From: a@example.com\r\nSubject: Hola\r\n"));
    }

    private function messageId(string $rawHeader): ?string
    {
        $client = new WebklexMailboxClient(new ClientManager([]));

        return (new ReflectionMethod($client, 'messageIdFromRawHeader'))->invoke($client, $rawHeader);
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
