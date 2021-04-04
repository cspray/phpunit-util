<?php

namespace Amp\PHPUnit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Revolt\EventLoop\Loop;
use Revolt\EventLoop\Driver\TracingDriver;
use Revolt\Future\Deferred;
use Revolt\Future\Future;
use function Revolt\Future\all;
use function Revolt\Future\spawn;

abstract class AsyncTestCase extends PHPUnitTestCase
{
    const RUNTIME_PRECISION = 2;

    private Deferred $deferred;

    private string $timeoutId;

    /** @var int Minimum runtime in milliseconds. */
    private int $minimumRuntime = 0;

    /** @var string Temporary storage for actual test name. */
    private string $realTestName;

    private bool $setUpInvoked = false;

    private bool $ignoreWatchers = false;

    private bool $includeReferencedWatchers = false;

    /**
     * @codeCoverageIgnore Invoked before code coverage data is being collected.
     */
    final public function setName(string $name): void
    {
        parent::setName($name);
        $this->realTestName = $name;
    }

    /**
     * Execute any needed cleanup after the test before loop watchers are checked.
     */
    protected function cleanup(): void
    {
        // Empty method in base class.
    }

    protected function setUp(): void
    {
        $this->setUpInvoked = true;
        Loop::getDriver()->clear(); // remove all watchers from the event loop
        \gc_collect_cycles(); // extensions using an event loop may otherwise leak the file descriptors to the loop

        $this->deferred = new Deferred;

        Loop::setErrorHandler(function (\Throwable $exception): void {
            if ($this->deferred->isComplete()) {
                return;
            }

            $this->deferred->error(new LoopCaughtException($exception));
        });
    }

    /** @internal */
    final protected function runAsyncTest(mixed ...$args): mixed
    {
        if (!$this->setUpInvoked) {
            self::fail(\sprintf(
                '%s::setUp() overrides %s::setUp() without calling the parent method',
                \str_replace("\0", '@', \get_class($this)), // replace NUL-byte in anonymous class name
                self::class
            ));
        }

        parent::setName($this->realTestName);

        $start = \microtime(true);

        try {
            try {
                [$returnValue] = all(
                    spawn(function () use ($args): mixed {
                        try {
                            $result = ([$this, $this->realTestName])(...$args);
                            if ($result instanceof Future) {
                                $result = $result->join();
                            }
                            return $result;
                        } finally {
                            if (!$this->deferred->isComplete()) {
                                $this->deferred->complete(null);
                            }
                        }
                    }),
                    $this->deferred->getFuture()
                );
            } finally {
                $this->cleanup();
            }
        } catch (\Throwable $exception) {
            $this->ignoreLoopWatchers();
            throw $exception;
        } finally {
            $this->clear();
        }

        $end = \microtime(true);

        if ($this->minimumRuntime > 0) {
            $actualRuntime = (int) (\round($end - $start, self::RUNTIME_PRECISION) * 1000);
            $msg = 'Expected test to take at least %dms but instead took %dms';
            self::assertGreaterThanOrEqual(
                $this->minimumRuntime,
                $actualRuntime,
                \sprintf($msg, $this->minimumRuntime, $actualRuntime)
            );
        }

        return $returnValue;
    }

    final protected function runTest(): mixed
    {
        parent::setName('runAsyncTest');
        return parent::runTest();
    }

    /**
     * Fails the test if the loop does not run for at least the given amount of time.
     *
     * @param int $runtime Required run time in milliseconds.
     */
    final protected function setMinimumRuntime(int $runtime): void
    {
        if ($runtime < 1) {
            throw new \Error('Minimum runtime must be at least 1ms');
        }

        $this->minimumRuntime = $runtime;
    }

    /**
     * Fails the test (and stops the loop) after the given timeout.
     *
     * @param int $timeout Timeout in milliseconds.
     */
    final protected function setTimeout(int $timeout): void
    {
        $this->timeoutId = Loop::delay($timeout, function () use ($timeout): void {
            Loop::setErrorHandler(null);

            $additionalInfo = '';

            $loop = Loop::getDriver();
            if ($loop instanceof TracingDriver) {
                $additionalInfo .= "\r\n\r\n" . $loop->dump();
            } elseif (\class_exists(TracingDriver::class)) {
                $additionalInfo .= "\r\n\r\nSet AMP_DEBUG_TRACE_WATCHERS=true as environment variable to trace watchers keeping the loop running.";
            } else {
                $additionalInfo .= "\r\n\r\nSet REVOLT_DEBUG_TRACE_WATCHERS=true as environment variable to trace watchers keeping the loop running.";
            }

            if ($this->deferred->isComplete()) {
                return;
            }

            try {
                $this->fail('Expected test to complete before ' . $timeout . 'ms time limit' . $additionalInfo);
            } catch (AssertionFailedError $e) {
                $this->deferred->error($e);
            }
        });

        Loop::unreference($this->timeoutId);
    }

    /**
     * Test will fail if the event loop contains active referenced watchers when the test ends.
     */
    final protected function checkLoopWatchers(): void
    {
        $this->ignoreWatchers = false;
    }

    /**
     * Test will not fail if the event loop contains active watchers when the test ends.
     */
    final protected function ignoreLoopWatchers(): void
    {
        $this->ignoreWatchers = true;
    }

    /**
     * Test will fail if the event loop contains active referenced or unreferenced watchers when the test ends.
     */
    final protected function checkUnreferencedLoopWatchers(): void
    {
        $this->ignoreWatchers = false;
        $this->includeReferencedWatchers = true;
    }

    /**
     * Test will not fail if the event loop contains active, but unreferenced, watchers when the test ends.
     */
    final protected function ignoreUnreferencedLoopWatchers(): void
    {
        $this->includeReferencedWatchers = false;
    }

    /**
     * @param int           $invocationCount Number of times the callback must be invoked or the test will fail.
     * @param callable|null $returnCallback Callable providing a return value for the callback.
     *
     * @return callable|MockObject Mock object having only an __invoke method.
     */
    final protected function createCallback(int $invocationCount, callable $returnCallback = null): callable
    {
        $mock = $this->createMock(CallbackStub::class);
        $invocationMocker = $mock->expects(self::exactly($invocationCount))
            ->method('__invoke');

        if ($returnCallback) {
            $invocationMocker->willReturnCallback($returnCallback);
        }

        return $mock;
    }

    private function clear(): void
    {
        try {
            if (isset($this->timeoutId)) {
                Loop::cancel($this->timeoutId);
                return;
            }

            if ($this->ignoreWatchers) {
                return;
            }

            $info = Loop::getInfo();

            $watcherCount = $info['enabled_watchers']['referenced'];

            if ($this->includeReferencedWatchers) {
                $watcherCount += $info['enabled_watchers']['unreferenced'];
            }

            if ($watcherCount > 0) {
                self::fail(
                    "Found enabled watchers at end of test '{$this->getName()}': " . \json_encode(
                        $info,
                        \JSON_PRETTY_PRINT
                    ),
                );
            }
        } finally {
            Loop::getDriver()->clear();
        }
    }
}
