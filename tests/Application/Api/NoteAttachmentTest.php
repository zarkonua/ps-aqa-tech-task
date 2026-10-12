<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\NoteAttachment;
use App\Service\AttachmentStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class NoteAttachmentTest extends ApiTestCase
{
    private const SAMPLES = __DIR__.'/../../fixtures/attachments/';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        $storage = $this->storage();
        $storage->delete($storage->keys());
        array_map('unlink', array_filter($this->temporaryFiles, 'is_file'));

        parent::tearDown();
    }

    public function testPdfIsStoredAndDownloadedByteForByte(): void
    {
        self::assertSame(201, $this->upload('owner', 'shared_1', self::sample('valid.pdf'), ['X-Correlation-ID' => 'attachment-test-1']));
        $attachment = $this->json();

        self::assertSame(['id', 'note_id', 'filename', 'mime_type', 'size', 'sha256', 'uploaded_by', 'created_at', 'download_url'], array_keys($attachment));
        self::assertSame($this->noteIds['shared_1'], $attachment['note_id']);
        self::assertSame(['valid.pdf', 'application/pdf'], [$attachment['filename'], $attachment['mime_type']]);
        self::assertSame(filesize(self::SAMPLES.'valid.pdf'), $attachment['size']);
        self::assertSame(hash_file('sha256', self::SAMPLES.'valid.pdf'), $attachment['sha256']);
        self::assertSame($this->userIds['owner'], $attachment['uploaded_by']);
        self::assertSame('/api/attachments/'.$attachment['id'].'/download', $attachment['download_url']);
        self::assertSame($attachment['download_url'], $this->client->getResponse()->headers->get('Location'));

        // The viewer of the note downloads exactly what was uploaded.
        self::assertSame(200, $this->api('viewer', 'GET', $attachment['download_url']));
        $response = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(file_get_contents(self::SAMPLES.'valid.pdf'), file_get_contents($response->getFile()->getPathname()));
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=valid.pdf', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        self::assertSame(200, $this->api('viewer', 'GET', $this->noteUri('shared_1').'/attachments'));
        self::assertSame(['items' => [$attachment], 'total' => 1, 'max' => 5], $this->json());

        $events = $this->auditEvents('shared_1', AuditEvent::ATTACHMENT_UPLOADED);
        self::assertCount(1, $events);
        self::assertSame([$attachment['id'], 'attachment-test-1'], [$events[0]->getSubjectId(), $events[0]->getCorrelationId()]);
    }

    public function testFileIsStoredUnderAnOpaqueKeyOutsideTheWebRoot(): void
    {
        self::assertSame(201, $this->upload('owner', 'private_1', self::sample('notes.txt', 'My Secret Plans.txt')));
        $entity = $this->em()->find(NoteAttachment::class, $this->json()['id']);
        $path = $this->storage()->path($entity->getStorageKey());

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $entity->getStorageKey());
        self::assertFileExists($path);
        self::assertStringNotContainsStringIgnoringCase('secret', $path);
        self::assertStringNotContainsString('.txt', $path);
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertStringStartsWith($projectDir.'/var/', $path);
        self::assertStringNotContainsString('/public/', $path);
        // The key is not part of what clients see.
        self::assertStringNotContainsString($entity->getStorageKey(), json_encode($this->json()));
    }

    /**
     * @return iterable<string, array{string, string|null, string, string}>
     */
    public static function allowedFiles(): iterable
    {
        yield 'png' => ['pixel.png', null, 'pixel.png', 'image/png'];
        yield 'jpg' => ['photo.jpg', null, 'photo.jpg', 'image/jpeg'];
        yield 'jpeg extension, upper case' => ['photo.jpg', 'PHOTO.JPEG', 'PHOTO.jpeg', 'image/jpeg'];
        yield 'pdf' => ['valid.pdf', null, 'valid.pdf', 'application/pdf'];
        yield 'txt' => ['notes.txt', null, 'notes.txt', 'text/plain'];
        yield 'csv' => ['data.csv', null, 'data.csv', 'text/csv'];
        yield 'unicode name' => ['файл нотатки.txt', null, 'файл нотатки.txt', 'text/plain'];
        yield 'name with markup' => ['notes.txt', '<img src=x onerror=alert(1)>.txt', 'img src=x onerror=alert(1).txt', 'text/plain'];
        yield 'name with quotes and spaces' => ['notes.txt', '  my   "quoted"  notes .txt', 'my quoted notes.txt', 'text/plain'];
    }

    #[DataProvider('allowedFiles')]
    public function testAllowedTypes(string $sample, ?string $clientName, string $expectedName, string $expectedType): void
    {
        self::assertSame(201, $this->upload('editor', 'shared_1', self::sample($sample, $clientName)));
        self::assertSame([$expectedName, $expectedType], [$this->json()['filename'], $this->json()['mime_type']]);

        self::assertSame(200, $this->api('editor', 'GET', $this->json()['download_url']));
        $response = $this->client->getResponse();
        self::assertSame(file_get_contents(self::SAMPLES.$sample), file_get_contents($response->getFile()->getPathname()));
        self::assertStringStartsWith('attachment; filename=', (string) $response->headers->get('Content-Disposition'));
        self::assertDoesNotMatchRegularExpression('/[\r\n]/', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedTypes(): iterable
    {
        yield 'text named .jpg' => ['fake.jpg', 'fake.jpg'];
        yield 'pdf named .png' => ['valid.pdf', 'document.png'];
        yield 'png named .pdf' => ['pixel.png', 'image.pdf'];
        yield 'image named .txt' => ['photo.jpg', 'photo.txt'];
        yield 'executable extension' => ['notes.txt', 'run.exe'];
        yield 'php script' => ['notes.txt', 'shell.php'];
        yield 'double extension' => ['notes.txt', 'notes.txt.php'];
        yield 'svg' => ['notes.txt', 'drawing.svg'];
        yield 'html' => ['notes.txt', 'page.html'];
        yield 'no extension' => ['notes.txt', 'README'];
    }

    #[DataProvider('refusedTypes')]
    public function testContentMustMatchAnAllowedType(string $sample, string $clientName): void
    {
        // The Content-Type the client claims does not matter.
        self::assertSame(415, $this->upload('owner', 'private_1', self::sample($sample, $clientName, 'image/jpeg')));
        self::assertSame(['error' => 'Only PNG, JPG, PDF, TXT and CSV files are allowed, and the content must match the extension.'], $this->json());
        $this->assertNothingStored('private_1');
    }

    public function testSizeLimit(): void
    {
        $max = 10 * 1024 * 1024;

        self::assertSame(201, $this->upload('owner', 'private_1', $this->generated('edge.txt', $max)));
        self::assertSame($max, $this->json()['size']);

        self::assertSame(413, $this->upload('owner', 'private_1', $this->generated('too-big.txt', $max + 1)));
        self::assertSame(['error' => 'File is larger than 10 MiB.'], $this->json());

        self::assertSame(422, $this->upload('owner', 'private_1', $this->generated('empty.txt', 0)));
        self::assertSame('The file is empty.', $this->json()['violations'][0]['message']);

        self::assertCount(1, $this->storage()->keys());
    }

    public function testAtMostFiveAttachmentsPerNote(): void
    {
        $ids = [];
        for ($i = 1; $i <= 5; ++$i) {
            self::assertSame(201, $this->upload('owner', 'private_1', self::sample('notes.txt', "file $i.txt")));
            $ids[] = $this->json()['id'];
        }

        self::assertSame(422, $this->upload('owner', 'private_1', self::sample('notes.txt', 'file 6.txt')));
        self::assertSame(['propertyPath' => 'file', 'message' => 'A note can have at most 5 attachments.'], $this->json()['violations'][0]);
        self::assertCount(5, $this->storage()->keys());

        // The limit is per note, and deleting makes room.
        self::assertSame(201, $this->upload('owner', 'private_2', self::sample('notes.txt')));
        self::assertSame(204, $this->api('owner', 'DELETE', '/api/attachments/'.$ids[0]));
        self::assertSame(201, $this->upload('owner', 'private_1', self::sample('notes.txt', 'file 6.txt')));
        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('private_1').'/attachments'));
        self::assertSame(['file 2.txt', 'file 3.txt', 'file 4.txt', 'file 5.txt', 'file 6.txt'], array_column($this->json()['items'], 'filename'));
    }

    public function testMalformedUploads(): void
    {
        $uri = $this->noteUri('private_1').'/attachments';
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token('owner'), 'HTTP_ACCEPT' => 'application/json'];

        $cases = [
            'no file' => [[], []],
            'file under another name' => [[], ['upload' => self::sample('notes.txt')]],
            'several files' => [[], ['file' => [self::sample('notes.txt'), self::sample('data.csv')]]],
            'extra field' => [['note_id' => $this->noteIds['private_2']], ['file' => self::sample('notes.txt')]],
            'file as text field' => [['file' => 'not a file'], []],
        ];
        foreach ($cases as $name => [$fields, $files]) {
            $this->client->request('POST', $uri, $fields, $files, $auth);
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), $name);
            self::assertNotEmpty($this->json()['violations'], $name);
        }

        // A JSON body is not an upload either.
        self::assertSame(422, $this->api('owner', 'POST', $uri, ['file' => 'x']));
        $this->assertNothingStored('private_1');
    }

    public function testRolesAndVisibility(): void
    {
        self::assertSame(201, $this->upload('editor', 'shared_1', self::sample('notes.txt', 'by editor.txt')));
        $byEditor = $this->json();
        self::assertSame(201, $this->upload('owner', 'shared_1', self::sample('data.csv')));
        $byOwner = $this->json();
        self::assertSame(201, $this->upload('owner', 'private_1', self::sample('valid.pdf')));
        $private = $this->json();
        $list = $this->noteUri('shared_1').'/attachments';

        // Viewer: list and download only.
        self::assertSame(200, $this->api('viewer', 'GET', $list));
        self::assertSame(2, $this->json()['total']);
        self::assertSame(200, $this->api('viewer', 'GET', $byEditor['download_url']));
        self::assertSame(403, $this->upload('viewer', 'shared_1', self::sample('notes.txt')));
        self::assertSame(403, $this->api('viewer', 'DELETE', '/api/attachments/'.$byOwner['id']));
        self::assertSame(['error' => 'Your role does not allow changing the attachments of this note.'], $this->json());

        // No access to the note: nothing exists, whatever the ID.
        foreach (['unrelated', 'editor'] as $persona) {
            self::assertSame(404, $this->api($persona, 'GET', $private['download_url']), $persona);
            self::assertSame(404, $this->api($persona, 'DELETE', '/api/attachments/'.$private['id']), $persona);
            self::assertSame(404, $this->api($persona, 'GET', $this->noteUri('private_1').'/attachments'), $persona);
            self::assertSame(404, $this->upload($persona, 'private_1', self::sample('notes.txt')), $persona);
        }
        self::assertSame(404, $this->api('unrelated', 'GET', $byEditor['download_url']));
        self::assertSame(404, $this->api('owner', 'GET', '/api/attachments/00000000-0000-4000-8000-000000000000/download'));
        self::assertSame(404, $this->api('owner', 'GET', '/api/attachments/not-a-uuid/download'));
        self::assertSame(404, $this->api('owner', 'DELETE', '/api/attachments/..%2F..%2Fetc%2Fpasswd'));

        // No or expired token.
        $expired = ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)];
        self::assertSame(401, $this->api(null, 'GET', $byEditor['download_url']));
        self::assertSame(401, $this->api(null, 'GET', $byEditor['download_url'], null, $expired));
        self::assertSame(401, $this->api(null, 'GET', $list));
        self::assertSame(401, $this->api(null, 'DELETE', '/api/attachments/'.$byEditor['id']));
        self::assertSame(401, $this->upload(null, 'shared_1', self::sample('notes.txt')));

        // Editor may delete, also what the owner uploaded.
        self::assertSame(204, $this->api('editor', 'DELETE', '/api/attachments/'.$byOwner['id']));
        self::assertSame(404, $this->api('owner', 'GET', $byOwner['download_url']));
        self::assertSame(404, $this->api('editor', 'DELETE', '/api/attachments/'.$byOwner['id']));
        self::assertCount(1, $this->auditEvents('shared_1', AuditEvent::ATTACHMENT_DELETED));
        self::assertCount(2, $this->storage()->keys(), 'the deleted file is gone, the other two remain');
    }

    public function testRevokedUserLosesDownload(): void
    {
        self::assertSame(201, $this->upload('owner', 'shared_1', self::sample('valid.pdf')));
        $url = $this->json()['download_url'];
        self::assertSame(200, $this->api('viewer', 'GET', $url));
        self::assertSame(200, $this->api('editor', 'GET', $url));

        self::assertSame(204, $this->api('owner', 'DELETE', $this->sharesUri('shared_1', 'shared_1/viewer')));
        self::assertSame(404, $this->api('viewer', 'GET', $url));
        self::assertSame(404, $this->api('viewer', 'GET', $this->noteUri('shared_1').'/attachments'));
        self::assertSame(200, $this->api('editor', 'GET', $url));

        // Downgraded to viewer: can still read, no longer change.
        self::assertSame(200, $this->api('owner', 'PATCH', $this->sharesUri('shared_1', 'shared_1/editor'), ['role' => 'viewer']));
        self::assertSame(200, $this->api('editor', 'GET', $url));
        self::assertSame(403, $this->upload('editor', 'shared_1', self::sample('notes.txt')));
    }

    public function testDeletingANoteRemovesItsFiles(): void
    {
        self::assertSame(201, $this->upload('owner', 'private_1', self::sample('valid.pdf')));
        self::assertSame(201, $this->upload('owner', 'private_1', self::sample('notes.txt')));
        self::assertSame(201, $this->upload('owner', 'private_2', self::sample('pixel.png')));
        self::assertSame(201, $this->upload('owner', 'shared_1', self::sample('data.csv')));
        $kept = $this->json()['download_url'];
        self::assertCount(4, $this->storage()->keys());

        self::assertSame(204, $this->api('owner', 'DELETE', $this->noteUri('private_1')));
        self::assertCount(2, $this->storage()->keys());

        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'delete', 'ids' => [$this->noteIds['private_2']]]));
        self::assertCount(1, $this->storage()->keys());
        self::assertSame(200, $this->api('owner', 'GET', $kept));
    }

    public function testUploadsAreThrottled(): void
    {
        self::assertSame(201, $this->api('unrelated', 'POST', '/api/notes', ['title' => 't', 'content' => 'c']));
        $uri = '/api/notes/'.$this->json()['id'].'/attachments';
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token('unrelated'), 'HTTP_ACCEPT' => 'application/json'];

        $statuses = [];
        for ($i = 0; $i < 31; ++$i) {
            $this->client->request('POST', $uri, [], ['file' => self::sample('notes.txt')], $auth);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }
        // Five fit on the note, the next 25 are refused for the limit of the note, then the rate limit steps in.
        self::assertSame([201 => 5, 422 => 25, 429 => 1], array_count_values($statuses));
    }

    public function testPruneRemovesOrphanedFilesOnly(): void
    {
        self::assertSame(201, $this->upload('owner', 'private_1', self::sample('valid.pdf')));
        $url = $this->json()['download_url'];
        $orphan = $this->storage()->storeContent('left behind');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:attachments:prune'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Removed 1 orphaned file(s).', $tester->getDisplay());

        self::assertFalse($this->storage()->exists($orphan));
        self::assertSame(200, $this->api('owner', 'GET', $url));
    }

    public function testOpenApiDocumentsAttachmentRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $paths = $this->json()['paths'];

        $upload = $paths['/api/notes/{id}/attachments']['post'];
        self::assertEqualsCanonicalizing(['201', '401', '403', '404', '413', '415', '422', '429', '500'], array_map('strval', array_keys($upload['responses'])));
        self::assertArrayHasKey('multipart/form-data', $upload['requestBody']['content']);
        self::assertArrayHasKey('get', $paths['/api/notes/{id}/attachments']);
        self::assertArrayHasKey('get', $paths['/api/attachments/{id}/download']);
        self::assertEqualsCanonicalizing(['204', '401', '403', '404', '500'], array_map('strval', array_keys($paths['/api/attachments/{id}']['delete']['responses'])));
    }

    private function storage(): AttachmentStorage
    {
        return self::getContainer()->get(AttachmentStorage::class);
    }

    private function assertNothingStored(string $noteKey): void
    {
        self::assertSame([], $this->storage()->keys(), 'a refused upload leaves no file behind');
        self::assertSame(0, $this->em()->getRepository(NoteAttachment::class)->count(['note' => $this->noteIds[$noteKey]]));
    }

    private static function sample(string $file, ?string $clientName = null, ?string $claimedType = null): UploadedFile
    {
        return new UploadedFile(self::SAMPLES.$file, $clientName ?? $file, $claimedType, null, true);
    }

    private function generated(string $name, int $bytes): UploadedFile
    {
        $this->temporaryFiles[] = $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes > 0 ? str_repeat("0123456789abcde\n", intdiv($bytes, 16)).str_repeat('x', $bytes % 16) : '');

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    /**
     * @param array<string, string> $headers
     */
    private function upload(?string $persona, string $noteKey, UploadedFile $file, array $headers = []): int
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $persona) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($persona);
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request('POST', $this->noteUri($noteKey).'/attachments', [], ['file' => $file], $server);
        $this->em()->clear();

        return $this->client->getResponse()->getStatusCode();
    }
}
