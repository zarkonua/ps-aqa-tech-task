<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Validates the search parameters of the notes list before any query runs:
 * malformed values are a 400, well-formed but unacceptable ones a 422.
 * The parsed result is handed to NoteSearchExtension through the context.
 *
 * @implements ProviderInterface<object>
 */
final class NoteCollectionProvider implements ProviderInterface
{
    public const CONTEXT_KEY = 'note_search';
    public const SORT_FIELDS = ['title', 'createdAt', 'updatedAt'];
    public const ORDERS = ['asc', 'desc'];
    public const MAX_LIMIT = 100;

    private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?:[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?([Zz]|[+-]\d{2}:\d{2}))?$/D';

    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private readonly ProviderInterface $collectionProvider,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $filters = $context['filters'] ?? [];

        $from = $this->date($filters, 'updatedFrom', false);
        $to = $this->date($filters, 'updatedTo', true);
        if (null !== $from && null !== $to && $from > $to) {
            throw new UnprocessableEntityHttpException('updatedFrom must not be later than updatedTo.');
        }

        $context[self::CONTEXT_KEY] = [
            'from' => $from,
            'to' => $to,
            'sortBy' => $this->choice($filters, 'sortBy', self::SORT_FIELDS),
            'order' => $this->choice($filters, 'order', self::ORDERS),
        ];

        // "limit" is the documented name of the page size; "itemsPerPage" keeps working.
        if (array_key_exists('limit', $filters)) {
            $limit = filter_var($filters['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT]]);
            if (false === $limit) {
                throw new UnprocessableEntityHttpException(sprintf('limit must be an integer between 1 and %d.', self::MAX_LIMIT));
            }
            $context['filters']['itemsPerPage'] = $limit;
        }

        return $this->collectionProvider->provide($operation, $uriVariables, $context);
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $choices
     */
    private function choice(array $filters, string $name, array $choices): ?string
    {
        if (!array_key_exists($name, $filters)) {
            return null;
        }

        if (!is_string($filters[$name]) || !in_array($filters[$name], $choices, true)) {
            throw new BadRequestHttpException(sprintf('%s must be one of: %s.', $name, implode(', ', $choices)));
        }

        return $filters[$name];
    }

    /**
     * A date covers its whole UTC day, so both ends of a range are inclusive.
     *
     * @param array<string, mixed> $filters
     */
    private function date(array $filters, string $name, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!array_key_exists($name, $filters)) {
            return null;
        }

        $value = $filters[$name];
        $utc = new \DateTimeZone('UTC');
        if (is_string($value) && 1 === preg_match(self::DATE_PATTERN, $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            if (!isset($m[4])) {
                return new \DateTimeImmutable($value.($endOfDay ? ' 23:59:59' : ' 00:00:00'), $utc);
            }

            if ($m[4] <= 23 && $m[5] <= 59 && $m[6] <= 59) {
                try {
                    return (new \DateTimeImmutable($value))->setTimezone($utc);
                } catch (\Exception) {
                    // Falls through to the 400 below.
                }
            }
        }

        throw new BadRequestHttpException(sprintf('%s must be an ISO 8601 date or date-time, e.g. 2026-10-11 or 2026-10-11T08:30:00Z.', $name));
    }
}
