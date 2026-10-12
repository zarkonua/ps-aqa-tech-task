<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Note;
use App\Entity\TaxonomyItem;

/**
 * JSON shapes of tags and categories.
 */
final class TaxonomyView
{
    /**
     * @return array{id: string, name: string, color: string|null}
     */
    public static function brief(TaxonomyItem $item): array
    {
        return ['id' => $item->getId(), 'name' => $item->getName(), 'color' => $item->getColor()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function full(TaxonomyItem $item): array
    {
        $utc = new \DateTimeZone('UTC');

        return self::brief($item) + [
            'version' => $item->getVersion(),
            'created_at' => $item->getCreatedAt()->setTimezone($utc)->format(\DATE_ATOM),
            'updated_at' => $item->getUpdatedAt()->setTimezone($utc)->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ofNote(Note $note): array
    {
        $tags = array_map(self::brief(...), $note->getTags()->toArray());
        usort($tags, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));

        return [
            'note_id' => (string) $note->getId(),
            'category' => null === $note->getCategory() ? null : self::brief($note->getCategory()),
            'tags' => $tags,
        ];
    }
}
