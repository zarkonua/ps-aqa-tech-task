<?php

declare(strict_types=1);

namespace App\Tests\Application\Command;

use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\SharingFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SharingFixturesTest extends KernelTestCase
{
    private const RUN_A = 'phpunit-fixtures-a';
    private const RUN_B = 'phpunit-fixtures-b';

    private Application $application;

    protected function setUp(): void
    {
        $this->application = new Application(self::bootKernel());
    }

    protected function tearDown(): void
    {
        $fixtures = self::getContainer()->get(SharingFixtures::class);
        $fixtures->purge(self::RUN_A);
        $fixtures->purge(self::RUN_B);

        parent::tearDown();
    }

    public function testSeedIsRepeatableIsolatedAndRemovable(): void
    {
        $first = $this->seed(self::RUN_A);
        $other = $this->seed(self::RUN_B);

        self::assertSame(['owner', 'viewer', 'editor', 'unrelated'], array_keys($first['users']));
        self::assertSame(['private_1', 'private_2', 'shared_1', 'shared_2'], array_keys($first['notes']));
        self::assertSame([], $first['notes']['private_1']['shares']);
        self::assertSame(['viewer' => 'viewer', 'editor' => 'editor'], array_map(static fn (array $share): string => $share['role'], $first['notes']['shared_1']['shares']));
        self::assertSame('owner.'.self::RUN_A.'@qa-fixtures.test', $first['users']['owner']['email']);
        self::assertSame(3, $first['version']);
        self::assertCount(60, $first['search_notes']);
        self::assertSame($first['search_notes']['search_01']['updated_at'], $first['search_notes']['search_06']['updated_at']);
        self::assertCount(11, $first['tags']['owner']);
        self::assertSame(['WORK', 'Work'], [$first['tags']['editor']['work_upper']['name'], $first['tags']['owner']['work']['name']]);
        self::assertSame('日本語', $first['tags']['owner']['japanese']['name']);
        self::assertSame([0, 10, 'projects'], [count($first['notes']['private_1']['tags']), count($first['notes']['private_2']['tags']), $first['notes']['private_2']['category']]);
        self::assertNotSame($first['users']['owner']['id'], $other['users']['owner']['id']);

        // Mutate the run, then reseed: same IDs, pristine state.
        $em = $this->em();
        $em->remove($em->find(NoteShare::class, $first['notes']['shared_1']['shares']['viewer']['id']));
        $em->find(Note::class, $first['notes']['private_1']['id'])->setTitle('changed');
        $em->flush();

        self::assertSame($first, $this->seed(self::RUN_A));
        $em = $this->em();
        self::assertNotNull($em->find(NoteShare::class, $first['notes']['shared_1']['shares']['viewer']['id']));
        self::assertSame($first['notes']['private_1']['title'], $em->find(Note::class, $first['notes']['private_1']['id'])->getTitle());
        self::assertTrue($em->find(User::class, $first['users']['viewer']['id'])->isVerified());
        self::assertCount(10, $em->find(Note::class, $first['notes']['private_2']['id'])->getTags());
        $searchNote = $em->find(Note::class, $first['search_notes']['search_07']['id']);
        self::assertSame($first['search_notes']['search_07']['updated_at'], $searchNote->getUpdatedAt()->format(\DATE_ATOM));
        self::assertSame($first['search_notes']['search_07']['title'], $searchNote->getTitle());
        self::assertSame('Personal', $em->find(Note::class, $first['notes']['shared_1']['id'])->getCategory()->getName());

        $tester = new CommandTester($this->application->find('app:fixtures:sharing:purge'));
        self::assertSame(Command::SUCCESS, $tester->execute(['run-id' => self::RUN_A]));
        self::assertStringContainsString('Removed 4 persona(s)', $tester->getDisplay());

        $em = $this->em();
        self::assertNull($em->find(User::class, $first['users']['owner']['id']));
        self::assertNull($em->find(Note::class, $first['notes']['shared_1']['id']));
        self::assertNull($em->find(Tag::class, $first['tags']['owner']['work']['id']));
        self::assertNotNull($em->find(Note::class, $other['notes']['shared_1']['id']), 'another run must not be affected');
    }

    public function testInvalidRunIdIsRejected(): void
    {
        $tester = new CommandTester($this->application->find('app:fixtures:sharing:seed'));
        self::assertSame(Command::INVALID, $tester->execute(['run-id' => 'Not Valid!']));
    }

    /**
     * @return array<string, mixed>
     */
    private function seed(string $runId): array
    {
        $tester = new CommandTester($this->application->find('app:fixtures:sharing:seed'));
        self::assertSame(Command::SUCCESS, $tester->execute(['run-id' => $runId]));

        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em;
    }
}
