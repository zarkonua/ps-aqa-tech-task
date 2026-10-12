<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Note;
use App\Entity\User;
use App\Security\NoteAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Fills in the current user's role on a note right before it is serialized.
 */
final class NoteRoleNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'note_role_normalizer_already_called';

    public function __construct(
        private readonly Security $security,
        private readonly NoteAccess $access,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        $user = $this->security->getUser();
        if ($data instanceof Note && $user instanceof User) {
            $data->setRole($this->access->roleFor($data, $user));
        }

        $context[self::ALREADY_CALLED] = true;

        return $this->normalizer->normalize($data, $format, $context);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Note && !isset($context[self::ALREADY_CALLED]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Note::class => false,
        ];
    }
}
