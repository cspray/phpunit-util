<?php

namespace Amp\PHPUnit;

use Amp\Loop;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\Test;

class LoopReset implements TestListener {

    use TestListenerDefaultImplementation;

    public function endTest(Test $test, float $time) : void {
        Loop::set((new Loop\DriverFactory)->create());
        gc_collect_cycles(); // extensions using an event loop may otherwise leak the file descriptors to the loop
    }
}
