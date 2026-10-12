<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use App\Security\NoteAccess;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Creates notifications. Delivering the same event again is harmless: the event key
 * identifies it, and a second delivery neither stores nor mails anything.
 */
final class Notifier
{
    public const RETENTION_DAYS = 90;
    public const DUE_WINDOW_HOURS = 24;

    private const SUBJECTS = [
        Notification::SHARE_GRANTED => 'A note was shared with you',
        Notification::SHARE_REVOKED => 'Your access to a note was removed',
        Notification::TASK_ASSIGNED => 'A task was assigned to you',
        Notification::TASK_DUE => 'A task is due soon',
        Notification::TASK_COMPLETED => 'A task was completed',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly NoteAccess $access,
        private readonly LoggerInterface $logger,
        private readonly RunClock $clock,
    ) {
    }

    /**
     * @param string $eventKey identifies the event; the same key never notifies the same user twice
     *
     * @return bool true when this call created the notification
     */
    public function notify(User $recipient, string $type, string $eventKey, ?User $actor, string $resourceType, string $resourceId): bool
    {
        $created = 1 === $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO notifications (id, user_id, type, event_id, actor_id, resource_type, resource_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [Uuid::v4(), $recipient->getId(), $type, hash('sha256', $eventKey), $actor?->getId(), $resourceType, $resourceId, gmdate('Y-m-d H:i:s')],
        );

        // Optional mail: only for a new notification, and only if the user has not opted out of this type.
        if ($created && $recipient->wantsEmailFor($type)) {
            $this->mail($recipient, $type, $actor);
        }

        return $created;
    }

    public function shareGranted(User $recipient, User $actor, string $shareId, Note $note): void
    {
        $this->notify($recipient, Notification::SHARE_GRANTED, 'share_granted:'.$shareId, $actor, Notification::RESOURCE_NOTE, (string) $note->getId());
    }

    public function shareRevoked(User $recipient, User $actor, string $shareId, Note $note): void
    {
        $this->notify($recipient, Notification::SHARE_REVOKED, 'share_revoked:'.$shareId, $actor, Notification::RESOURCE_NOTE, (string) $note->getId());
    }

    /**
     * Tells the assignee, unless they assigned the task to themselves.
     */
    public function taskAssigned(Task $task, User $actor): void
    {
        $assignee = $task->getAssignee();
        if (null !== $assignee && $assignee->getId() !== $actor->getId()) {
            $this->notify($assignee, Notification::TASK_ASSIGNED, sprintf('task_assigned:%s:%s:%d', $task->getId(), $assignee->getId(), $task->getVersion()), $actor, Notification::RESOURCE_TASK, $task->getId());
        }
    }

    /**
     * Tells the other person on the task: the owner when the assignee finished it, and the other way round.
     */
    public function taskCompleted(Task $task, User $actor): void
    {
        foreach ([$task->getOwner(), $task->getAssignee()] as $person) {
            if (null !== $person && $person->getId() !== $actor->getId()) {
                $this->notify($person, Notification::TASK_COMPLETED, sprintf('task_completed:%s:%d', $task->getId(), $task->getVersion()), $actor, Notification::RESOURCE_TASK, $task->getId());
            }
        }
    }

    /**
     * Notifies about unfinished tasks that are due within the next day or already overdue.
     * A task with an assignee is the assignee's to do; otherwise its owner's. One notification per due time.
     *
     * @param User|null $onlyFor limit to one user's tasks
     *
     * @return int notifications created
     */
    public function taskDue(?User $onlyFor = null): int
    {
        // A user of a test run whose clock was advanced is reminded by that clock.
        $now = $this->clock->now($onlyFor);
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.status != :done AND t.dueAt IS NOT NULL AND t.dueAt <= :until AND t.dueAt >= :since')
            ->setParameter('done', TaskStatus::Done)
            ->setParameter('until', $now->modify(sprintf('+%d hours', self::DUE_WINDOW_HOURS)))
            ->setParameter('since', $now->modify(sprintf('-%d days', self::RETENTION_DAYS)));
        if (null !== $onlyFor) {
            $queryBuilder->andWhere('(t.assignee = :user OR (t.owner = :user AND t.assignee IS NULL))')->setParameter('user', $onlyFor);
        }

        $created = 0;
        foreach ($queryBuilder->getQuery()->getResult() as $task) {
            $recipient = $task->getAssignee() ?? $task->getOwner();
            $created += (int) $this->notify($recipient, Notification::TASK_DUE, sprintf('task_due:%s:%d', $task->getId(), $task->getDueAt()->getTimestamp()), null, Notification::RESOURCE_TASK, $task->getId());
        }

        return $created;
    }

    /**
     * @return int notifications removed for being older than the retention period
     */
    public function prune(?User $onlyFor = null): int
    {
        $sql = 'DELETE FROM notifications WHERE created_at < ?';
        $parameters = [gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400)];
        if (null !== $onlyFor) {
            $sql .= ' AND user_id = ?';
            $parameters[] = $onlyFor->getId();
        }

        return (int) $this->entityManager->getConnection()->executeStatement($sql, $parameters);
    }

    /**
     * What a notification is about, as far as its recipient may still see it: once access to a note
     * or task is gone (revoked, unassigned, deleted), its title is no longer shown.
     *
     * @return array{type: string, id: string, title: string|null, available: bool}
     */
    public function resource(Notification $notification): array
    {
        $recipient = $notification->getUser();
        $title = null;
        if (Notification::RESOURCE_NOTE === $notification->getResourceType()) {
            $note = $this->entityManager->find(Note::class, $notification->getResourceId());
            if (null !== $note && $this->access->canView($note, $recipient)) {
                $title = $note->getTitle();
            }
        } else {
            $task = $this->entityManager->find(Task::class, $notification->getResourceId());
            if (null !== $task && in_array($recipient->getId(), [$task->getOwner()->getId(), $task->getAssignee()?->getId()], true)) {
                $title = $task->getTitle();
            }
        }

        return ['type' => $notification->getResourceType(), 'id' => $notification->getResourceId(), 'title' => $title, 'available' => null !== $title];
    }

    private function mail(User $recipient, string $type, ?User $actor): void
    {
        $line = (null === $actor ? '' : $actor->getEmail().': ').self::SUBJECTS[$type].'.';

        try {
            // The mail says what happened, not the content: titles stay behind the sign-in.
            $this->mailer->send((new Email())
                ->from('no-reply@qa-task.local')
                ->to($recipient->getEmail())
                ->subject(self::SUBJECTS[$type])
                ->text($line."\n\nSign in to see the details. You can turn these emails off in your profile.")
                ->html(sprintf('<p>%s</p><p>Sign in to see the details. You can turn these emails off in your profile.</p>', htmlspecialchars($line, ENT_QUOTES))));
        } catch (\Throwable $e) {
            // The notification itself is stored; a mail problem must not fail the action that caused it.
            $this->logger->warning('Notification mail could not be sent: {exception}', ['exception' => $e::class, 'type' => $type]);
        }
    }
}
