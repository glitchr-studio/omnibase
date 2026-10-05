<?php

namespace Base\Exception;

/** The `demo` environment refuses to start, or `demo:reset` to run (Base\Demo\DemoGuard). */
class DemoRefusedException extends \RuntimeException
{
}
