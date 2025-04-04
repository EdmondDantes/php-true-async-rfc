<?php

declare(strict_types=1);

namespace Async;

final class TaskGroup implements Awaitable
{
    public function __construct(
        private ?Scope  $scope          = null,
        private bool    $ignoreErrors   = false
    ) {}
    
    public function race(bool $ignoreErrors = false): Awaitable {}
    public function firstResult(bool $ignoreErrors = false): Awaitable {}
    public function getResults(): array {}
    public function getErrors(): array {}
    
    public function add(Coroutine ...$coroutines): self {}
    
    public function spawn(\Closure $closure, mixed ...$args): Coroutine {}
    
    /**
     * Cancel a task group.
     */
    public function cancel(CancellationException $cancellationException): void {}
    
    public function disposeResults(): void {}
    
    public function dispose(): void {}
}