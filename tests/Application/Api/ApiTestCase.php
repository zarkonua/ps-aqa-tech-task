<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\User;
use App\Enum\ShareRole;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Seeds the four sharing personas with two private and two shared notes under a unique
 * run prefix, and removes everything it created afterwards.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected string $runId;

    /** @var array<string, string> persona => user ID */
    protected array $userIds = [];

    /** @var array<string, string> note key => note ID */
    protected array $noteIds = [];

    /** @var array<string, string> "noteKey/persona" => share ID */
    protected array $shareIds = [];

    /** @var array<string, string> persona => JWT */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->runId = 't'.bin2hex(random_bytes(6));

        $em = $this->em();
        $users = [];
        foreach (['owner', 'viewer', 'editor', 'unrelated'] as $persona) {
            $users[$persona] = $this->createUser($persona);
        }

        $shares = [
            'private_1' => [],
            'private_2' => [],
            'shared_1' => ['viewer' => ShareRole::Viewer, 'editor' => ShareRole::Editor],
            'shared_2' => ['viewer' => ShareRole::Viewer],
        ];
        $notes = [];
        foreach ($shares as $key => $noteShares) {
            $notes[$key] = (new Note())->setOwner($users['owner'])->setTitle($this->runId.' '.$key)->setContent('content of '.$key);
            $em->persist($notes[$key]);
        }
        $em->flush();

        foreach ($shares as $key => $noteShares) {
            $this->noteIds[$key] = (string) $notes[$key]->getId();
            foreach ($noteShares as $persona => $role) {
                $share = new NoteShare($notes[$key], $users[$persona], $role);
                $em->persist($share);
                $this->shareIds[$key.'/'.$persona] = $share->getId();
            }
        }
        $em->flush();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $ids = array_values($this->userIds);
        $em->createQueryBuilder()->delete(AuditEvent::class, 'a')
            ->where('a.actorId IN (:ids) OR a.targetUserId IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
        $em->createQueryBuilder()->delete(User::class, 'u')
            ->where('u.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();

        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return $this->client->getContainer()->get('doctrine')->getManager();
    }

    protected function email(string $persona): string
    {
        return sprintf('%s.%s@api-tests.test', $persona, $this->runId);
    }

    protected function createUser(string $persona, bool $verified = true): User
    {
        $user = (new User())->setEmail($this->email($persona))->setPasswordHash('not-a-usable-hash')->setIsVerified($verified);
        $this->em()->persist($user);
        $this->em()->flush();
        $this->userIds[$persona] = (string) $user->getId();

        return $user;
    }

    protected function token(string $persona, ?int $expiresAt = null): string
    {
        if (null === $expiresAt && isset($this->tokens[$persona])) {
            return $this->tokens[$persona];
        }

        $container = $this->client->getContainer();
        $user = $this->em()->find(User::class, $this->userIds[$persona]);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = $container->get(JWTTokenManagerInterface::class);

        if (null !== $expiresAt) {
            return $jwt->createFromPayload($user, ['exp' => $expiresAt]);
        }

        return $this->tokens[$persona] = $jwt->create($user);
    }

    /**
     * @param array<string, mixed>|string|null $body    array is sent as JSON, string is sent raw
     * @param array<string, string>            $headers
     */
    protected function api(?string $persona, string $method, string $uri, array|string|null $body = null, array $headers = []): int
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if (null !== $persona) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($persona);
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $content = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;
        $this->client->request($method, $uri, [], [], $server, $content);
        // Assertions must read the database, not entities the request left in the identity map.
        $this->em()->clear();

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<mixed>
     */
    protected function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function sharesUri(string $noteKey, ?string $shareKey = null): string
    {
        $uri = sprintf('/api/notes/%s/shares', $this->noteIds[$noteKey]);

        return null === $shareKey ? $uri : $uri.'/'.$this->shareIds[$shareKey];
    }

    protected function noteUri(string $noteKey): string
    {
        return '/api/notes/'.$this->noteIds[$noteKey];
    }

    /**
     * @return list<string> note keys visible in the list response, sorted
     */
    protected function listedNoteKeys(): array
    {
        $keys = [];
        foreach ($this->json() as $note) {
            $key = array_search($note['id'], $this->noteIds, true);
            if (false !== $key) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * @return list<AuditEvent>
     */
    protected function auditEvents(string $noteKey, ?string $type = null): array
    {
        $criteria = ['noteId' => $this->noteIds[$noteKey]];
        if (null !== $type) {
            $criteria['eventType'] = $type;
        }

        return $this->em()->getRepository(AuditEvent::class)->findBy($criteria, ['createdAt' => 'ASC']);
    }
}
