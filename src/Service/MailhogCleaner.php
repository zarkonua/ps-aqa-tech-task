<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes captured mail from MailHog. Best effort: when MailHog is not configured or not reachable
 * nothing is removed and the caller is told so.
 */
final class MailhogCleaner
{
    public function __construct(
        #[Autowire('%env(default::MAILHOG_API_URL)%')]
        private readonly ?string $apiUrl,
    ) {
    }

    /**
     * @param list<string> $recipients
     *
     * @return int|null messages deleted, or null when MailHog could not be asked
     */
    public function deleteMailTo(array $recipients): ?int
    {
        if (null === $this->apiUrl || '' === $this->apiUrl || !function_exists('curl_init')) {
            return null;
        }

        $deleted = 0;
        foreach ($recipients as $recipient) {
            $found = $this->request('GET', '/api/v2/search?kind=to&limit=250&query='.rawurlencode($recipient));
            if (null === $found) {
                return null;
            }
            foreach (json_decode($found, true)['items'] ?? [] as $message) {
                if (is_string($message['ID'] ?? null) && null !== $this->request('DELETE', '/api/v1/messages/'.rawurlencode($message['ID']))) {
                    ++$deleted;
                }
            }
        }

        return $deleted;
    }

    private function request(string $method, string $path): ?string
    {
        $handle = curl_init(rtrim((string) $this->apiUrl, '/').$path);
        curl_setopt_array($handle, [\CURLOPT_CUSTOMREQUEST => $method, \CURLOPT_RETURNTRANSFER => true, \CURLOPT_CONNECTTIMEOUT => 1, \CURLOPT_TIMEOUT => 3]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);

        return false === $body || $status >= 400 || 0 === $status ? null : (string) $body;
    }
}
