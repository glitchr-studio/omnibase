<?php

namespace Tests\Base\Fixtures\Warmup;

/**
 * A class for a package that is not installed (its interface does not
 * exist): the warm-up must pass it by, not die on it.
 */
class OptionalImplementation implements \Tests\Base\Fixtures\Absent\ProviderInterface
{
}
