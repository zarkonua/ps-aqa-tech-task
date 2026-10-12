<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AccountToken;
use App\Entity\AuditEvent;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;

final class AccountTest extends ApiTestCase
{
    private const NEW_PASSWORD = 'a-brand-new-passphrase';
    private const GENERIC = ['message' => 'If an account exists for this email, a password reset link has been sent.'];

    public function testResetRequestAnswersTheSameForKnownUnknownAndUnconfirmedEmails(): void
    {
        $this->createUser('pending', false);

        $responses = [];
        foreach ([$this->email('owner'), 'nobody.'.$this->runId.'@api-tests.test', $this->email('pending')] as $index => $email) {
            self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $email]));
            $responses[] = [(string) $this->client->getResponse()->getContent(), $this->client->getResponse()->headers->get('Content-Type')];
            self::assertEmailCount(0 === $index ? 1 : 0);
        }
        self::assertSame(self::GENERIC, json_decode($responses[0][0], true));
        self::assertSame($responses[0], $responses[1]);
        self::assertSame($responses[0], $responses[2]);

        self::assertCount(1, $this->em()->getRepository(AccountToken::class)->findBy(['user' => array_values($this->userIds)]));
    }

    public function testResetEmailCarriesAOneTimeTokenStoredOnlyAsHash(): void
    {
        $token = $this->requestReset('owner', strtoupper($this->email('owner')));

        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', $this->email('owner'));
        self::assertEmailSubjectContains($email, 'Reset your password');
        self::assertStringContainsString('/app?reset_token='.$token, $email->getTextBody());
        self::assertStringContainsString('30 minutes', $email->getTextBody());

        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM account_tokens WHERE user_id = ?', [$this->userIds['owner']]);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        self::assertNotContains($token, $row);
        self::assertSame('password_reset', $row['purpose']);
        self::assertNull($row['used_at']);
        $ttl = strtotime($row['expires_at'].' UTC') - time();
        self::assertGreaterThan(29 * 60, $ttl);
        self::assertLessThanOrEqual(30 * 60, $ttl);
    }

    public function testValidResetUpdatesCredentialsAndSignsOutEverywhere(): void
    {
        $oldJwt = $this->token('owner');
        $token = $this->requestReset('owner');

        self::assertSame(200, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $token, 'password' => self::NEW_PASSWORD]));
        self::assertSame(['message' => 'Password updated. Sign in with your new password.'], $this->json());

        self::assertSame(401, $this->signIn('owner', self::PASSWORD));
        self::assertSame(200, $this->signIn('owner', self::NEW_PASSWORD));
        $newJwt = $this->json()['token'];

        // Sessions from before the reset are over; the new one works.
        self::assertSame(401, $this->api(null, 'GET', '/api/notes', null, ['Authorization' => 'Bearer '.$oldJwt]));
        self::assertSame(200, $this->api(null, 'GET', '/api/notes', null, ['Authorization' => 'Bearer '.$newJwt]));

        // Replay fails and changes nothing.
        self::assertSame(422, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $token, 'password' => 'another-long-passphrase']));
        self::assertSame(['propertyPath' => 'token', 'message' => 'This reset link is invalid or was already used. Request a new one.'], $this->json()['violations'][0]);
        self::assertSame(200, $this->signIn('owner', self::NEW_PASSWORD));

        // Other accounts are untouched.
        self::assertSame(200, $this->signIn('viewer', self::PASSWORD));

        $types = $this->accountEvents('owner');
        self::assertContains(AuditEvent::PASSWORD_RESET_REQUESTED, $types);
        self::assertContains(AuditEvent::PASSWORD_RESET_COMPLETED, $types);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->requestReset('owner');
        $this->em()->getConnection()->executeStatement('UPDATE account_tokens SET expires_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $this->userIds['owner']]);

        self::assertSame(422, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $token, 'password' => self::NEW_PASSWORD]));
        self::assertSame('This reset link has expired. Request a new one.', $this->json()['violations'][0]['message']);
        self::assertSame(200, $this->signIn('owner', self::PASSWORD));
    }

    public function testNewerRequestReplacesOlderLink(): void
    {
        $first = $this->requestReset('owner');
        $second = $this->requestReset('owner');
        self::assertNotSame($first, $second);

        self::assertSame(422, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $first, 'password' => self::NEW_PASSWORD]));
        self::assertSame(200, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $second, 'password' => self::NEW_PASSWORD]));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|string, int, string|null}>
     */
    public static function invalidPublicPayloads(): iterable
    {
        $token = str_repeat('a', 64);
        yield 'request: malformed JSON' => ['request', '{"email":', 400, null];
        yield 'request: list' => ['request', '["a@example.com"]', 400, null];
        yield 'request: empty' => ['request', '', 422, 'email'];
        yield 'request: null' => ['request', ['email' => null], 422, 'email'];
        yield 'request: number' => ['request', ['email' => 5], 422, 'email'];
        yield 'request: not an email' => ['request', ['email' => 'not-an-email'], 422, 'email'];
        yield 'request: XSS' => ['request', ['email' => '<script>alert(1)</script>'], 422, 'email'];
        yield 'request: too long' => ['request', ['email' => str_repeat('a', 185).'@example.com'], 422, 'email'];
        yield 'request: unexpected field' => ['request', ['email' => 'a@example.com', 'password' => 'x'], 422, 'password'];
        yield 'confirm: malformed JSON' => ['confirm', '{', 400, null];
        yield 'confirm: empty' => ['confirm', [], 422, 'token'];
        yield 'confirm: missing password' => ['confirm', ['token' => $token], 422, 'password'];
        yield 'confirm: missing token' => ['confirm', ['password' => self::NEW_PASSWORD], 422, 'token'];
        yield 'confirm: token as list' => ['confirm', ['token' => [$token], 'password' => self::NEW_PASSWORD], 422, 'token'];
        yield 'confirm: password as number' => ['confirm', ['token' => $token, 'password' => 123456789012], 422, 'password'];
        yield 'confirm: 11 characters' => ['confirm', ['token' => $token, 'password' => str_repeat('a', 11)], 422, 'password'];
        yield 'confirm: 129 characters' => ['confirm', ['token' => $token, 'password' => str_repeat('a', 129)], 422, 'password'];
        yield 'confirm: unknown token' => ['confirm', ['token' => $token, 'password' => self::NEW_PASSWORD], 422, 'token'];
        yield 'confirm: token of the wrong shape' => ['confirm', ['token' => "' OR 1=1 --", 'password' => self::NEW_PASSWORD], 422, 'token'];
        yield 'confirm: unexpected field' => ['confirm', ['token' => $token, 'password' => self::NEW_PASSWORD, 'email' => 'a@example.com'], 422, 'email'];
    }

    #[DataProvider('invalidPublicPayloads')]
    public function testResetValidation(string $step, array|string $body, int $expectedStatus, ?string $expectedField): void
    {
        self::assertSame($expectedStatus, $this->api(null, 'POST', '/api/auth/password-reset/'.$step, $body));
        if (null !== $expectedField) {
            self::assertContains($expectedField, array_column($this->json()['violations'], 'propertyPath'));
        }
        self::assertEmailCount(0);
    }

    public function testPasswordLengthBoundaries(): void
    {
        foreach ([[str_repeat('é', 11), 422], [str_repeat('é', 12), 200], [str_repeat('b', 128), 200], [str_repeat('b', 129), 422]] as [$password, $status]) {
            $token = $this->requestReset('owner');
            $this->em()->getConnection()->executeStatement('DELETE FROM throttle_hits');
            self::assertSame($status, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $token, 'password' => $password]), (string) mb_strlen($password));
            // A rejected password does not use the link up.
            if (422 === $status) {
                self::assertSame(200, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $token, 'password' => self::NEW_PASSWORD]));
            }
        }
    }

    public function testResetRequestsAreThrottledPerEmail(): void
    {
        $unknown = 'ghost.'.$this->runId.'@api-tests.test';
        foreach ([$this->email('owner'), $unknown] as $email) {
            for ($i = 0; $i < 3; ++$i) {
                self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $email]));
            }
            // The limit applies the same way to an email without an account.
            self::assertSame(429, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $email]));
            self::assertSame('Too many attempts. Try again later.', $this->json()['error']);
            $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
            self::assertSame($retryAfter, $this->json()['retry_after']);
            self::assertGreaterThan(0, $retryAfter);
            self::assertLessThanOrEqual(900, $retryAfter);
            self::assertEmailCount(0);
        }

        self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $this->email('viewer')]));
    }

    public function testResetRequestsAreThrottledPerAddress(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => sprintf('u%d.%s@api-tests.test', $i, $this->runId)]));
        }
        self::assertSame(429, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $this->email('owner')]));
    }

    public function testFailedConfirmationsAreThrottled(): void
    {
        $token = $this->requestReset('owner');
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(422, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => str_repeat((string) $i, 64), 'password' => self::NEW_PASSWORD]));
        }
        // Even the right token has to wait now.
        self::assertSame(429, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $token, 'password' => self::NEW_PASSWORD]));
        self::assertSame(200, $this->signIn('owner', self::PASSWORD));
    }

    public function testChangePassword(): void
    {
        $oldJwt = $this->token('owner');
        $resetToken = $this->requestReset('owner');

        self::assertSame(403, $this->api('owner', 'POST', '/api/account/change-password', ['current_password' => 'wrong-password', 'new_password' => self::NEW_PASSWORD]));
        self::assertSame(['error' => 'Current password is incorrect.'], $this->json());
        self::assertSame(422, $this->api('owner', 'POST', '/api/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => self::PASSWORD]));
        self::assertSame(422, $this->api('owner', 'POST', '/api/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'short']));
        self::assertSame('new_password', $this->json()['violations'][0]['propertyPath']);
        self::assertSame(200, $this->signIn('owner', self::PASSWORD));

        self::assertSame(200, $this->api('owner', 'POST', '/api/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD]));
        self::assertSame(['message', 'token'], array_keys($this->json()));
        $newJwt = $this->json()['token'];

        self::assertSame(401, $this->api(null, 'GET', '/api/auth/me', null, ['Authorization' => 'Bearer '.$oldJwt]));
        self::assertSame(200, $this->api(null, 'GET', '/api/auth/me', null, ['Authorization' => 'Bearer '.$newJwt]));
        self::assertSame(401, $this->signIn('owner', self::PASSWORD));
        self::assertSame(200, $this->signIn('owner', self::NEW_PASSWORD));

        // A reset link requested before the change no longer works.
        self::assertSame(422, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $resetToken, 'password' => 'yet-another-passphrase']));
        self::assertContains(AuditEvent::PASSWORD_CHANGED, $this->accountEvents('owner'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, int}>
     */
    public static function invalidChangePasswordPayloads(): iterable
    {
        yield 'malformed JSON' => ['{"current_password"', 400];
        yield 'empty' => [[], 422];
        yield 'missing new' => [['current_password' => self::PASSWORD], 422];
        yield 'missing current' => [['new_password' => self::NEW_PASSWORD], 422];
        yield 'null current' => [['current_password' => null, 'new_password' => self::NEW_PASSWORD], 422];
        yield 'current as list' => [['current_password' => [self::PASSWORD], 'new_password' => self::NEW_PASSWORD], 422];
        yield '11 characters' => [['current_password' => self::PASSWORD, 'new_password' => str_repeat('x', 11)], 422];
        yield '129 characters' => [['current_password' => self::PASSWORD, 'new_password' => str_repeat('x', 129)], 422];
        yield 'unexpected field' => [['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'user_id' => 'x'], 422];
    }

    #[DataProvider('invalidChangePasswordPayloads')]
    public function testChangePasswordValidation(array|string $body, int $expectedStatus): void
    {
        self::assertSame($expectedStatus, $this->api('owner', 'POST', '/api/account/change-password', $body));
        self::assertSame(200, $this->signIn('owner', self::PASSWORD));
    }

    public function testWrongCurrentPasswordIsThrottled(): void
    {
        $body = ['current_password' => 'wrong-password', 'new_password' => self::NEW_PASSWORD];
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(403, $this->api('owner', 'POST', '/api/account/change-password', $body));
        }
        self::assertSame(429, $this->api('owner', 'POST', '/api/account/change-password', $body));
        // Locked out even with the right password, and for the email change as well.
        self::assertSame(429, $this->api('owner', 'POST', '/api/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD]));
        self::assertSame(429, $this->api('owner', 'PATCH', '/api/account/profile', ['email' => 'x.'.$this->email('owner'), 'current_password' => self::PASSWORD]));
        self::assertSame(200, $this->signIn('owner', self::PASSWORD));

        // Per account: another user is not affected.
        self::assertSame(403, $this->api('viewer', 'POST', '/api/account/change-password', $body));
    }

    public function testEmailChangeNeedsConfirmation(): void
    {
        $oldJwt = $this->token('owner');
        $newEmail = 'renamed.'.$this->email('owner');

        self::assertSame(200, $this->api('owner', 'GET', '/api/account/profile'));
        self::assertSame(['id' => $this->userIds['owner'], 'email' => $this->email('owner'), 'pending_email' => null], $this->json());

        self::assertSame(202, $this->api('owner', 'PATCH', '/api/account/profile', ['email' => ' '.strtoupper($newEmail).' ', 'current_password' => self::PASSWORD]));
        self::assertSame(['id' => $this->userIds['owner'], 'email' => $this->email('owner'), 'pending_email' => $newEmail], $this->json());
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', $newEmail);
        self::assertSame(1, preg_match('/email_change_token=([a-f0-9]{64})/', $mail->getTextBody(), $m));

        // Nothing changed yet.
        self::assertSame(200, $this->signIn('owner', self::PASSWORD));
        self::assertSame(200, $this->api('owner', 'GET', '/api/auth/me'));
        self::assertSame($this->email('owner'), $this->json()['email']);

        self::assertSame(200, $this->api(null, 'POST', '/api/account/email/confirm', ['token' => $m[1]]));
        self::assertSame($newEmail, $this->em()->find(User::class, $this->userIds['owner'])->getEmail());

        self::assertSame(401, $this->signIn('owner', self::PASSWORD));
        self::assertSame(200, $this->api(null, 'POST', '/api/auth/signin', ['email' => $newEmail, 'password' => self::PASSWORD]));
        $newJwt = $this->json()['token'];
        self::assertSame(401, $this->api(null, 'GET', '/api/notes', null, ['Authorization' => 'Bearer '.$oldJwt]));
        self::assertSame(200, $this->api(null, 'GET', '/api/account/profile', null, ['Authorization' => 'Bearer '.$newJwt]));
        self::assertSame(['email' => $newEmail, 'pending_email' => null], array_diff_key($this->json(), ['id' => 1]));
        // The notes stayed with the account.
        self::assertSame(200, $this->api(null, 'GET', '/api/notes', null, ['Authorization' => 'Bearer '.$newJwt]));
        self::assertCount(4, $this->json());

        self::assertSame(422, $this->api(null, 'POST', '/api/account/email/confirm', ['token' => $m[1]]));
        $types = $this->accountEvents('owner');
        self::assertContains(AuditEvent::EMAIL_CHANGE_REQUESTED, $types);
        self::assertContains(AuditEvent::EMAIL_CHANGED, $types);
    }

    public function testEmailChangeRefusals(): void
    {
        $uri = '/api/account/profile';
        $new = 'new.'.$this->email('owner');

        self::assertSame(403, $this->api('owner', 'PATCH', $uri, ['email' => $new, 'current_password' => 'wrong-password']));
        self::assertSame(422, $this->api('owner', 'PATCH', $uri, ['email' => strtoupper($this->email('owner')), 'current_password' => self::PASSWORD]));
        foreach ([[], ['email' => $new], ['current_password' => self::PASSWORD], ['email' => 'nope', 'current_password' => self::PASSWORD], ['email' => null, 'current_password' => self::PASSWORD], ['email' => $new, 'current_password' => self::PASSWORD, 'id' => 'x']] as $body) {
            self::assertSame(422, $this->api('owner', 'PATCH', $uri, $body), json_encode($body));
        }
        self::assertSame(400, $this->api('owner', 'PATCH', $uri, '{'));
        self::assertSame(401, $this->api(null, 'PATCH', $uri, ['email' => $new, 'current_password' => self::PASSWORD]));
        self::assertSame(401, $this->api(null, 'GET', $uri));
        self::assertEmailCount(0);

        // An address that already has an account: same answer, but no mail and no way to confirm.
        self::assertSame(202, $this->api('owner', 'PATCH', $uri, ['email' => $this->email('viewer'), 'current_password' => self::PASSWORD]));
        self::assertSame($this->email('viewer'), $this->json()['pending_email']);
        self::assertEmailCount(0);

        // Expired confirmation link.
        self::assertSame(202, $this->api('owner', 'PATCH', $uri, ['email' => $new, 'current_password' => self::PASSWORD]));
        preg_match('/email_change_token=([a-f0-9]{64})/', self::getMailerMessage()->getTextBody(), $m);
        $this->em()->getConnection()->executeStatement('UPDATE account_tokens SET expires_at = ? WHERE user_id = ? AND used_at IS NULL', [gmdate('Y-m-d H:i:s', time() - 1), $this->userIds['owner']]);
        self::assertSame(422, $this->api(null, 'POST', '/api/account/email/confirm', ['token' => $m[1]]));
        self::assertStringContainsString('expired', $this->json()['violations'][0]['message']);
        foreach ([[], ['token' => null], ['token' => 5], ['token' => str_repeat('f', 64)], ['token' => $m[1], 'email' => 'x@example.com']] as $body) {
            self::assertSame(422, $this->api(null, 'POST', '/api/account/email/confirm', $body), json_encode($body));
        }
        self::assertSame($this->email('owner'), $this->em()->find(User::class, $this->userIds['owner'])->getEmail());
    }

    public function testEmailTakenBeforeConfirmationIsAConflict(): void
    {
        $new = 'contested.'.$this->runId.'@api-tests.test';
        self::assertSame(202, $this->api('owner', 'PATCH', '/api/account/profile', ['email' => $new, 'current_password' => self::PASSWORD]));
        preg_match('/email_change_token=([a-f0-9]{64})/', self::getMailerMessage()->getTextBody(), $m);

        // Someone else registers the address before the link is opened.
        $other = (new User())->setEmail($new)->setPasswordHash('x')->setIsVerified(true);
        $this->em()->persist($other);
        $this->em()->flush();
        $this->userIds['contested'] = (string) $other->getId();

        self::assertSame(409, $this->api(null, 'POST', '/api/account/email/confirm', ['token' => $m[1]]));
        self::assertSame($this->email('owner'), $this->em()->find(User::class, $this->userIds['owner'])->getEmail());
    }

    public function testSettings(): void
    {
        self::assertSame(200, $this->api('owner', 'GET', '/api/account/settings'));
        self::assertSame(['timezone' => 'UTC'], $this->json());

        self::assertSame(200, $this->api('owner', 'PATCH', '/api/account/settings', ['timezone' => 'Europe/Kyiv']));
        self::assertSame(['timezone' => 'Europe/Kyiv'], $this->json());
        self::assertSame(200, $this->api('owner', 'GET', '/api/account/settings'));
        self::assertSame(['timezone' => 'Europe/Kyiv'], $this->json());
        // Per user.
        self::assertSame(200, $this->api('viewer', 'GET', '/api/account/settings'));
        self::assertSame(['timezone' => 'UTC'], $this->json());

        $invalid = [[], ['timezone' => null], ['timezone' => ''], ['timezone' => 'Mars/Phobos'], ['timezone' => 'europe/kyiv'], ['timezone' => '+02:00'], ['timezone' => 'EET'],
            ['timezone' => 120], ['timezone' => ['Europe/Kyiv']], ['timezone' => "Europe/Kyiv\n"], ['timezone' => '<script>'], ['timezone' => 'UTC', 'locale' => 'uk']];
        foreach ($invalid as $body) {
            self::assertSame(422, $this->api('owner', 'PATCH', '/api/account/settings', $body), json_encode($body));
        }
        self::assertSame(400, $this->api('owner', 'PATCH', '/api/account/settings', 'timezone=UTC'));
        self::assertSame(401, $this->api(null, 'GET', '/api/account/settings'));
        self::assertSame(401, $this->api(null, 'PATCH', '/api/account/settings', ['timezone' => 'UTC']));

        self::assertSame('Europe/Kyiv', $this->em()->find(User::class, $this->userIds['owner'])->getTimezone());
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/account/settings', ['timezone' => 'UTC']));
        self::assertSame([AuditEvent::SETTINGS_UPDATED, AuditEvent::SETTINGS_UPDATED], $this->accountEvents('owner'));
    }

    public function testAuditRecordsAndStoredDataHoldNoSecrets(): void
    {
        $token = $this->requestReset('owner');
        $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $token, 'password' => self::NEW_PASSWORD]);

        $connection = $this->em()->getConnection();
        $stored = json_encode([
            $connection->fetchAllAssociative('SELECT * FROM audit_events WHERE actor_id = ?', [$this->userIds['owner']]),
            $connection->fetchAllAssociative('SELECT * FROM account_tokens WHERE user_id = ?', [$this->userIds['owner']]),
            $connection->fetchAllAssociative('SELECT * FROM throttle_hits'),
            $connection->fetchAllAssociative('SELECT * FROM users WHERE id = ?', [$this->userIds['owner']]),
        ]);
        self::assertStringNotContainsString($token, $stored);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $stored);
        // Throttle keys (emails, addresses) are hashed.
        self::assertStringNotContainsString($this->email('owner'), json_encode($connection->fetchAllAssociative('SELECT * FROM throttle_hits')));
    }

    public function testOpenApiDocumentsAccountRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $paths = $this->json()['paths'];

        self::assertEqualsCanonicalizing(['202', '400', '422', '429', '500'], array_map('strval', array_keys($paths['/api/auth/password-reset/request']['post']['responses'])));
        self::assertEqualsCanonicalizing(['200', '400', '422', '429', '500'], array_map('strval', array_keys($paths['/api/auth/password-reset/confirm']['post']['responses'])));
        self::assertSame([], $paths['/api/auth/password-reset/request']['post']['security']);
        self::assertEqualsCanonicalizing(['200', '400', '401', '403', '422', '429', '500'], array_map('strval', array_keys($paths['/api/account/change-password']['post']['responses'])));
        self::assertEqualsCanonicalizing(['202', '400', '401', '403', '422', '429', '500'], array_map('strval', array_keys($paths['/api/account/profile']['patch']['responses'])));
        self::assertArrayHasKey('get', $paths['/api/account/settings']);
        self::assertArrayHasKey('patch', $paths['/api/account/settings']);
        self::assertArrayHasKey('post', $paths['/api/account/email/confirm']);
    }

    private function requestReset(string $persona, ?string $email = null): string
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM throttle_hits');
        self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $email ?? $this->email($persona)]));
        self::assertEmailCount(1);
        self::assertSame(1, preg_match('/reset_token=([a-f0-9]{64})/', self::getMailerMessage()->getTextBody(), $m));

        return $m[1];
    }

    private function signIn(string $persona, string $password): int
    {
        return $this->api(null, 'POST', '/api/auth/signin', ['email' => $this->email($persona), 'password' => $password]);
    }

    /**
     * @return list<string> types of the audit events the persona caused
     */
    private function accountEvents(string $persona): array
    {
        return array_map(
            static fn (AuditEvent $event): string => $event->getEventType(),
            $this->em()->getRepository(AuditEvent::class)->findBy(['actorId' => $this->userIds[$persona]]),
        );
    }
}
