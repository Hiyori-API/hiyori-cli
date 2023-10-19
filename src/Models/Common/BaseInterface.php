<?php

namespace Hiyori\Models\Common;

interface BaseInterface
{
    public static function create(array $json): self;
}