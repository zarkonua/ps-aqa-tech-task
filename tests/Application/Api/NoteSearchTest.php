<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\User;
use App\Enum\ShareRole;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Works on its own set of notes with fixed timestamps:
 * s01..s12 owned by the owner (s01..s04 shared with the viewer), x01..x03 owned by "unrelated".
 * Notes sNN share "updated_at" in groups of four: 2026-03-01, -02, -03 at 12:00 UTC.
 */
final class NoteSearchTest extends ApiTestCase
{
    /** @var array<string, string> key => note ID */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        // The base notes of ApiTestCase would get in the way of exact expectations.
        $em->createQueryBuilder()->delete(Note::class, 'n')->where('n.id IN (:ids)')->setParameter('ids', array_values($this->noteIds))->getQuery()->execute();

        $owner = $em->find(User::class, $this->userIds['owner']);
        $viewer = $em->find(User::class, $this->userIds['viewer']);
        $unrelated = $em->find(User::class, $this->userIds['unrelated']);
        $titles = ['Alpha', 'alpha beta', 'ÜBER groß', '100% done', 'under_score', "O'Brien; DROP TABLE notes--", '日本語メモ', 'Zeta', 'apple pie', 'Banana', 'cherry', 'Delta'];

        $notes = [];
        foreach ($titles as $index => $title) {
            $key = sprintf('s%02d', $index + 1);
            $notes[$key] = (new Note())->setOwner($owner)->setTitle($title)->setContent('body '.$key.(0 === $index % 2 ? ' even APPLE' : ' odd'));
            $em->persist($notes[$key]);
            if ($index < 4) {
                $em->persist(new NoteShare($notes[$key], $viewer, ShareRole::Viewer));
            }
        }
        foreach (['x01', 'x02', 'x03'] as $key) {
            $notes[$key] = (new Note())->setOwner($unrelated)->setTitle('Alpha hidden '.$key)->setContent('apple secret');
            $em->persist($notes[$key]);
        }
        $em->flush();

        foreach ($notes as $key => $note) {
            $this->ids[$key] = (string) $note->getId();
        }
        $this->fixTimestamps();
    }

    private function fixTimestamps(): void
    {
        foreach ($this->ids as $key => $id) {
            $number = (int) substr($key, 1);
            $this->em()->getConnection()->executeStatement('UPDATE notes SET created_at = ?, updated_at = ? WHERE id = ?', [
                sprintf('2026-01-%02d 08:00:00', $number),
                sprintf('2026-03-%02d 12:00:00', intdiv($number - 1, 4) + 1),
                $id,
            ]);
        }
        $this->em()->clear();
    }

    public function testFullTextIsCaseInsensitiveOverTitleAndContent(): void
    {
        self::assertSame(['s01', 's02'], $this->search('owner', 'q=ALPHA'));
        self::assertSame(['s01', 's03', 's05', 's07', 's09', 's11'], $this->search('owner', 'q=apple'));
        self::assertSame(['s03'], $this->search('owner', 'q='.rawurlencode('über GROSS')) ?: $this->search('owner', 'q='.rawurlencode('über')));
        self::assertSame(['s07'], $this->search('owner', 'q='.rawurlencode('日本語')));
        self::assertSame([], $this->search('owner', 'q=nothing-matches-this'));
    }

    public function testSqlLikeTextIsTakenLiterally(): void
    {
        self::assertSame(['s04'], $this->search('owner', 'q='.rawurlencode('%')));
        self::assertSame(['s05'], $this->search('owner', 'q='.rawurlencode('_')));
        self::assertSame(['s06'], $this->search('owner', 'q='.rawurlencode("O'Brien; DROP TABLE")));
        self::assertSame([], $this->search('owner', 'q='.rawurlencode("' OR '1'='1")));
        self::assertSame([], $this->search('owner', 'q='.rawurlencode('<script>alert(1)</script>')));
        // The table is still there.
        self::assertCount(12, $this->search('owner', ''));
    }

    public function testQueryLength(): void
    {
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?q='.str_repeat('a', 200)));
        self::assertSame(422, $this->api('owner', 'GET', '/api/notes?q='.str_repeat('a', 201)));
        self::assertSame('q', $this->json()['violations'][0]['propertyPath']);
        self::assertSame(422, $this->api('owner', 'GET', '/api/notes?q[]=a'));
    }

    public function testFiltersAreJoinedByAnd(): void
    {
        self::assertSame(201, $this->api('owner', 'POST', '/api/tags', ['name' => 'T']));
        $tag = $this->json()['id'];
        foreach (['s01', 's02', 's09'] as $key) {
            self::assertSame(200, $this->api('owner', 'PUT', '/api/notes/'.$this->ids[$key].'/tags', ['tag_ids' => [$tag]]));
        }
        // Changing tags counts as an update of the note, so put the fixed timestamps back.
        self::assertSame(3, count($this->search('owner', 'updatedFrom='.gmdate('Y-m-d'))));
        $this->fixTimestamps();
        self::assertSame(['s01', 's02', 's09'], $this->search('owner', 'tagIds='.$tag));
        self::assertSame(['s01', 's09'], $this->search('owner', 'tagIds='.$tag.'&q=apple'));
        self::assertSame(['s01'], $this->search('owner', 'tagIds='.$tag.'&q=apple&updatedTo=2026-03-01'));
        self::assertSame(['s09'], $this->search('owner', 'tagIds='.$tag.'&q=apple&updatedFrom=2026-03-02&scope=all'));
        self::assertSame([], $this->search('owner', 'tagIds='.$tag.'&q=apple&updatedFrom=2026-03-02&scope=shared'));
        self::assertSame(['s01'], $this->search('viewer', 'tagIds='.$tag.'&q=apple&scope=shared'));
    }

    public function testDateRangeIsInclusiveAndUtc(): void
    {
        $day2 = ['s05', 's06', 's07', 's08'];
        self::assertSame($day2, $this->search('owner', 'updatedFrom=2026-03-02&updatedTo=2026-03-02'));
        self::assertSame($day2, $this->search('owner', 'updatedFrom=2026-03-02T12:00:00Z&updatedTo=2026-03-02T12:00:00Z'));
        self::assertSame([], $this->search('owner', 'updatedFrom=2026-03-02T12:00:01Z&updatedTo=2026-03-02T23:59:59Z'));
        // 14:00 at +02:00 is 12:00 UTC.
        self::assertSame($day2, $this->search('owner', 'updatedFrom='.rawurlencode('2026-03-02T14:00:00+02:00').'&updatedTo='.rawurlencode('2026-03-02T14:00:00+02:00')));
        self::assertSame(['s09', 's10', 's11', 's12'], $this->search('owner', 'updatedFrom=2026-03-03'));
        self::assertSame(['s01', 's02', 's03', 's04'], $this->search('owner', 'updatedTo=2026-03-01'));
        self::assertSame(12, count($this->search('owner', 'updatedFrom=2026-03-01&updatedTo=2026-03-03')));
        self::assertSame([], $this->search('owner', 'updatedTo=2026-02-28'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'malformed date' => ['updatedFrom=yesterday', 400];
        yield 'wrong date format' => ['updatedFrom=11.10.2026', 400];
        yield 'impossible date' => ['updatedTo=2026-02-30', 400];
        yield 'impossible time' => ['updatedTo=2026-02-10T25:00:00Z', 400];
        yield 'date-time without offset' => ['updatedFrom=2026-03-02T12:00:00', 400];
        yield 'empty date' => ['updatedFrom=', 400];
        yield 'date as list' => ['updatedFrom[]=2026-03-02', 400];
        yield 'date with trailing text' => ['updatedFrom=2026-03-02%0A', 400];
        yield 'inverted range' => ['updatedFrom=2026-03-03&updatedTo=2026-03-02', 422];
        yield 'inverted range by a second' => ['updatedFrom=2026-03-02T12:00:01Z&updatedTo=2026-03-02T12:00:00Z', 422];
        yield 'unknown sort' => ['sortBy=owner', 400];
        yield 'sort injection' => ['sortBy=title;DROP%20TABLE%20notes', 400];
        yield 'sort wrong case' => ['sortBy=updatedat', 400];
        yield 'unknown order' => ['sortBy=title&order=up', 400];
        yield 'order as list' => ['order[]=asc', 400];
        yield 'limit zero' => ['limit=0', 422];
        yield 'limit above max' => ['limit=101', 422];
        yield 'limit not a number' => ['limit=ten', 422];
        yield 'page zero' => ['page=0', 400];
    }

    #[DataProvider('invalidParameters')]
    public function testInvalidParametersAreRejected(string $query, int $expectedStatus): void
    {
        self::assertSame($expectedStatus, $this->api('owner', 'GET', '/api/notes?'.$query));
        self::assertSame($expectedStatus, $this->json()['status']);
        self::assertIsString($this->json()['detail']);
    }

    public function testDefaultSortIsUpdatedDescendingWithIdTieBreak(): void
    {
        $listed = $this->search('owner', '', false);

        // Newest day first; inside a day (equal timestamps) ascending by ID.
        $expected = [];
        foreach ([[9, 12], [5, 8], [1, 4]] as [$from, $to]) {
            $group = [];
            for ($i = $from; $i <= $to; ++$i) {
                $group[] = sprintf('s%02d', $i);
            }
            usort($group, fn (string $a, string $b): int => strcmp($this->ids[$a], $this->ids[$b]));
            array_push($expected, ...$group);
        }
        self::assertSame($expected, $listed);
        self::assertSame($listed, $this->search('owner', 'sortBy=updatedAt&order=desc', false));
    }

    public function testSorting(): void
    {
        self::assertSame(['s01', 's02', 's03'], array_slice($this->search('owner', 'sortBy=createdAt&order=asc', false), 0, 3));
        self::assertSame(['s12', 's11', 's10'], array_slice($this->search('owner', 'sortBy=createdAt', false), 0, 3));
        self::assertSame(['s12', 's11'], array_slice($this->search('owner', 'sortBy=createdAt&order=desc', false), 0, 2));

        $byTitle = $this->search('owner', 'sortBy=title', false);
        self::assertSame('s04', $byTitle[0], '"100% done" sorts first');
        self::assertSame($byTitle, array_reverse($this->search('owner', 'sortBy=title&order=desc', false)));
        self::assertSame(['s01', 's02'], array_slice($byTitle, 1, 2));

        // "order" alone applies to the default field.
        self::assertSame('2026-03-01T12:00:00+00:00', $this->firstUpdatedAt('order=asc'));
        self::assertSame('2026-03-03T12:00:00+00:00', $this->firstUpdatedAt('order=desc'));

        // The older "sort[...]" parameters keep working, and the new ones win when both are sent.
        self::assertSame($byTitle, $this->search('owner', 'sort[title]=asc', false));
        self::assertSame('2026-03-01T12:00:00+00:00', $this->firstUpdatedAt('sort[updatedAt]=asc'));
        self::assertSame($byTitle, $this->search('owner', 'sort[updatedAt]=desc&sortBy=title&order=asc', false));
    }

    public function testEqualTimestampsGiveStablePages(): void
    {
        foreach (['', 'sortBy=updatedAt&order=asc', 'sort[updatedAt]=desc'] as $sort) {
            $whole = $this->search('owner', $sort.'&limit=100', false);
            $paged = [];
            for ($page = 1; $page <= 4; ++$page) {
                $keys = $this->search('owner', $sort.'&limit=3&page='.$page, false);
                self::assertCount(3, $keys);
                self::assertSame(['12', (string) $page, '3'], $this->paginationHeaders());
                array_push($paged, ...$keys);
            }
            self::assertSame($whole, $paged, 'pages neither overlap nor skip for "'.$sort.'"');
            self::assertSame([], $this->search('owner', $sort.'&limit=3&page=5', false));
        }
    }

    public function testPageSize(): void
    {
        $em = $this->em();
        $owner = $em->find(User::class, $this->userIds['owner']);
        for ($i = 0; $i < 100; ++$i) {
            $em->persist((new Note())->setOwner($owner)->setTitle('bulk '.$i)->setContent('c'));
        }
        $em->flush();

        self::assertSame(200, $this->api('owner', 'GET', '/api/notes'));
        self::assertCount(20, $this->json());
        self::assertSame(['112', '1', '20'], $this->paginationHeaders());

        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?limit=100'));
        self::assertCount(100, $this->json());
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?limit=1&page=112'));
        self::assertCount(1, $this->json());

        // The older name still works; "limit" wins when both are sent.
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?itemsPerPage=7'));
        self::assertCount(7, $this->json());
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?itemsPerPage=7&limit=5'));
        self::assertCount(5, $this->json());
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?itemsPerPage=500'));
        self::assertCount(100, $this->json());
    }

    public function testTotalCountsOnlyAuthorizedNotes(): void
    {
        // "unrelated" owns three notes that match "alpha" and "apple"; nobody else ever sees or counts them.
        self::assertSame(['s01', 's02'], $this->search('owner', 'q=alpha&scope=all'));
        self::assertSame(2, $this->total());
        self::assertSame(['s01', 's02'], $this->search('viewer', 'q=alpha&scope=all'));
        self::assertSame(2, $this->total());
        self::assertSame(['s01', 's02', 's03', 's04'], $this->search('viewer', 'scope=shared'));
        self::assertSame(4, $this->total());
        self::assertSame([], $this->search('viewer', ''));
        self::assertSame(0, $this->total());
        self::assertSame([], $this->search('editor', 'q=apple&scope=all&sortBy=title'));
        self::assertSame(0, $this->total());
        self::assertSame(['x01', 'x02', 'x03'], $this->search('unrelated', 'q=apple'));
        self::assertSame(3, $this->total());

        self::assertSame(401, $this->api(null, 'GET', '/api/notes?q=alpha'));
        self::assertFalse($this->client->getResponse()->headers->has('X-Total-Count'));
    }

    public function testOpenApiDocumentsSearchParameters(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $parameters = array_column($this->json()['paths']['/api/notes']['get']['parameters'], null, 'name');

        foreach (['q', 'tagIds', 'tagMatch', 'categoryId', 'scope', 'updatedFrom', 'updatedTo', 'sortBy', 'order', 'page', 'limit'] as $name) {
            self::assertArrayHasKey($name, $parameters);
        }
        self::assertStringContainsString('"createdAt"', $parameters['sortBy']['description']);
        self::assertSame(200, $parameters['q']['schema']['maxLength']);
        self::assertSame(100, $parameters['limit']['schema']['maximum']);
    }

    /**
     * @return list<string> keys of the listed notes; sorted unless the response order matters
     */
    private function search(string $persona, string $query, bool $sorted = true): array
    {
        $limit = str_contains($query, 'limit=') ? '' : 'limit=100&';
        self::assertSame(200, $this->api($persona, 'GET', '/api/notes?'.$limit.$query), $query);

        $keys = [];
        foreach ($this->json() as $note) {
            $key = array_search($note['id'], $this->ids, true);
            self::assertNotFalse($key, 'unexpected note in the result: '.$note['title']);
            $keys[] = $key;
        }
        if ($sorted) {
            sort($keys);
        }

        return $keys;
    }

    private function total(): int
    {
        return (int) $this->client->getResponse()->headers->get('X-Total-Count');
    }

    /**
     * @return array{string|null, string|null, string|null}
     */
    private function paginationHeaders(): array
    {
        $headers = $this->client->getResponse()->headers;

        return [$headers->get('X-Total-Count'), $headers->get('X-Page'), $headers->get('X-Limit')];
    }

    private function firstUpdatedAt(string $query): string
    {
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?'.$query));

        return $this->json()[0]['updated_at'];
    }
}
