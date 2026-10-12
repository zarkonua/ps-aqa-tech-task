<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class AccountMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
    }

    public function sendPasswordReset(string $to, string $link, int $validMinutes): void
    {
        $this->send(
            $to,
            'Reset your password',
            'Reset your password by opening this link:',
            $link,
            sprintf('This link expires in %d minutes and works once. If you did not ask for it, ignore this email.', $validMinutes),
        );
    }

    public function sendEmailChange(string $to, string $link, int $validMinutes): void
    {
        $this->send(
            $to,
            'Confirm your new email address',
            'Confirm this address for your account by opening this link:',
            $link,
            sprintf('This link expires in %d minutes and works once. Your sign-in email changes only after you open it.', $validMinutes),
        );
    }

    private function send(string $to, string $subject, string $intro, string $link, string $outro): void
    {
        $safeLink = htmlspecialchars($link, ENT_QUOTES);

        $this->mailer->send((new Email())
            ->from('no-reply@qa-task.local')
            ->to($to)
            ->subject($subject)
            ->text(sprintf("%s\n%s\n\n%s", $intro, $link, $outro))
            ->html(sprintf('<p>%s</p><p><a href="%s">%s</a></p><p>%s</p>', $intro, $safeLink, $safeLink, $outro)));
    }
}
