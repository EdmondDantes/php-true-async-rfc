<?php

declare(strict_types=1);

namespace Async;

final class Coroutine implements Awaitable
{
    /**
     * Returns the Coroutine ID.
     */
    public function getId(): int {}
    
    /**
     * Returns the Coroutine local-context.
     */
    public function getContext(): Context {}
    
    /**
     * Returns the Coroutine debug trace.
     */
    public function getTrace(): array {}
    
    /**
     * Return spawn file and line.
     */
    public function getSpawnFileAndLine(): array {}
    
    /**
     * Return spawn location as string.
     */
    public function getSpawnLocation(): string {}
    
    /**
     * Return suspend file and line.
     */
    public function getSuspendFileAndLine(): array {}
    
    /**
     * Return suspend location as string.
     */
    public function getSuspendLocation(): string {}
    
    /**
     * Return true if the coroutine is started.
     */
    public function isStarted(): bool {}
    
    /**
     * Return true if the coroutine is running.
     */
    public function isRunning(): bool {}
    
    /**
     * Return true if the coroutine is suspended.
     */
    public function isSuspended(): bool {}
    
    /**
     * Return true if the coroutine is cancelled.
     */
    public function isCancelled(): bool {}
    
    /**
     * Return true if the coroutine is finished.
     */
    public function isFinished(): bool {}
    
    /**
     * Return awaiting debug information.
     */
    public function getAwaitingInfo(): array {}
    
    /**
     * Cancel the coroutine.
     */
    public function cancel(CancellationException $cancellationException): void {}
    
    /**
     * Define a callback to be executed when the coroutine is finished.
     */
    public function onCompletion(\Closure $callback): void {}
}