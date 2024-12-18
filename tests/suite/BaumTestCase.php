<?php

use PHPUnit\Framework\TestCase;

class BaumTestCase extends TestCase
{
    public function assertArraysAreEqual($expected, $actual, $message = ''): void
    {
        $ex = var_export($expected, true);
        $ac = var_export($actual, true);

        $this->assertEquals($ex, $ac, $message);
    }
}
