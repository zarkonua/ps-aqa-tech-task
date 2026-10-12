<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\CorrelationIdSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CorrelationIdSubscriberTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function headers(): iterable
    {
        yield 'well-formed is kept' => ['run-42_step.7', true];
        yield 'missing is generated' => [null, false];
        yield 'too short is replaced' => ['abc', false];
        yield 'too long is replaced' => [str_repeat('a', 65), false];
        yield 'unsafe characters are replaced' => ["id\r\nSet-Cookie: x=1", false];
        yield 'markup is replaced' => ['<script>alert(1)</script>', false];
    }

    #[DataProvider('headers')]
    public function testCorrelationId(?string $provided, bool $kept): void
    {
        $request = Request::create('/api/notes');
        if (null !== $provided) {
            $request->headers->set('X-Correlation-ID', $provided);
        }

        $response = $this->dispatch($request);
        $correlationId = (string) $response->headers->get('X-Correlation-ID');

        if ($kept) {
            self::assertSame($provided, $correlationId);
        } else {
            self::assertNotSame($provided, $correlationId);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $correlationId);
        }
    }

    public function testNonApiRequestsAreLeftAlone(): void
    {
        self::assertFalse($this->dispatch(Request::create('/account/notes'))->headers->has('X-Correlation-ID'));
    }

    private function dispatch(Request $request): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber = new CorrelationIdSubscriber();
        $response = new Response();

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }
}
