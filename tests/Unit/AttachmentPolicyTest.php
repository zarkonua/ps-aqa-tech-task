<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AttachmentPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachmentPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filenames(): iterable
    {
        yield 'plain' => ['report.pdf', 'report.pdf'];
        yield 'unix traversal' => ['../../etc/passwd.txt', 'passwd.txt'];
        yield 'windows traversal' => ['..\\..\\windows\\system32\\evil.txt', 'evil.txt'];
        yield 'absolute path' => ['/var/www/html/public/shell.txt', 'shell.txt'];
        yield 'drive letter' => ['C:\\Users\\me\\notes.txt', 'notes.txt'];
        yield 'null byte' => ["image.png\0.php", 'image.png.php'];
        yield 'control characters and header injection' => ["a\r\nSet-Cookie: x=1.txt", 'aSet-Cookie x=1.txt'];
        yield 'markup' => ['<script>alert(1)</script>.txt', 'script.txt'];
        yield 'reserved characters' => ['what?*|"is":this.csv', 'whatisthis.csv'];
        yield 'leading dots' => ['...hidden.txt', 'hidden.txt'];
        yield 'only an extension' => ['.txt', 'txt'];
        yield 'only dots' => ['...', 'file'];
        yield 'empty' => ['', 'file'];
        yield 'whitespace' => ["  spaced \t  name .txt ", 'spaced name.txt'];
        yield 'extension case' => ['Photo.JPG', 'Photo.jpg'];
        yield 'unicode kept' => ['звіт 日本語 café.pdf', 'звіт 日本語 café.pdf'];
        yield 'decomposed unicode composed' => ["cafe\u{0301}.txt", 'café.txt'];
        yield 'right-to-left override removed' => ["invoice\u{202E}fdp.txt", 'invoicefdp.txt'];
    }

    #[DataProvider('filenames')]
    public function testSanitizeFilename(string $input, string $expected): void
    {
        $sanitized = AttachmentPolicy::sanitizeFilename($input);

        self::assertSame($expected, $sanitized);
        self::assertDoesNotMatchRegularExpression('~[/\\\\\x00-\x1F]~', $sanitized);
        self::assertSame($sanitized, AttachmentPolicy::sanitizeFilename($sanitized), 'sanitizing twice changes nothing');
    }

    public function testLongNamesAreCutButKeepTheirExtension(): void
    {
        $sanitized = AttachmentPolicy::sanitizeFilename(str_repeat('я', 300).'.pdf');

        self::assertSame(150, mb_strlen($sanitized));
        self::assertStringEndsWith('.pdf', $sanitized);
    }

    public function testTypeComesFromContentAndExtensionTogether(): void
    {
        $samples = __DIR__.'/../fixtures/attachments/';

        self::assertSame('image/png', AttachmentPolicy::mimeTypeFor('a.png', $samples.'pixel.png'));
        self::assertSame('image/jpeg', AttachmentPolicy::mimeTypeFor('a.JPEG', $samples.'photo.jpg'));
        self::assertSame('application/pdf', AttachmentPolicy::mimeTypeFor('a.pdf', $samples.'valid.pdf'));
        self::assertSame('text/plain', AttachmentPolicy::mimeTypeFor('a.txt', $samples.'data.csv'));
        self::assertSame('text/csv', AttachmentPolicy::mimeTypeFor('a.csv', $samples.'data.csv'));

        self::assertNull(AttachmentPolicy::mimeTypeFor('a.jpg', $samples.'fake.jpg'));
        self::assertNull(AttachmentPolicy::mimeTypeFor('a.png', $samples.'photo.jpg'));
        self::assertNull(AttachmentPolicy::mimeTypeFor('a.txt', $samples.'valid.pdf'));
        self::assertNull(AttachmentPolicy::mimeTypeFor('a.exe', $samples.'notes.txt'));
        self::assertNull(AttachmentPolicy::mimeTypeFor('a', $samples.'notes.txt'));
    }
}
