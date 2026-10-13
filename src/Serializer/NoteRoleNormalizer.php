<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\NoteRevisionRepository;
use App\Security\NoteAccess;
use App\Service\BugCatalog;
use App\Service\BugSwitch;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
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
        private readonly BugSwitch $bugs,
        private readonly RequestStack $requestStack,
        private readonly NoteRevisionRepository $revisions,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        $user = $this->security->getUser();
        if ($data instanceof Note && $user instanceof User) {
            $data->setRole($this->access->roleFor($data, $user));
        }

        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        // Injected defect BUG-004, only for test runs that have it switched on: a single note is read stale.
        $request = $this->requestStack->getMainRequest();
        if ($data instanceof Note && is_array($normalized) && $data->getVersion() > 1 && null !== $request && $request->isMethod('GET')
            && null !== $request->attributes->get('id') && $this->bugs->isActive(BugCatalog::STALE_READ)) {
            $previous = $this->revisions->findOneByNumber($data, $data->getVersion() - 1);
            if (null !== $previous) {
                $normalized['title'] = $previous->getTitle();
                $normalized['content'] = $previous->getContent();
            }
        }

        return $normalized;
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
