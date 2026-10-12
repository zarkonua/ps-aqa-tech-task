<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * The request body is not a JSON object (reported as 400).
 */
final class MalformedPayloadException extends \RuntimeException
{
}
