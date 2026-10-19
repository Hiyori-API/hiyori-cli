<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Service\Combiner\Strategy\Concerns\IteratesBaseBatches;

abstract class CombinerStrategy implements CombinerStrategyInterface
{
    use IteratesBaseBatches;

    public const NAME = '';
}
