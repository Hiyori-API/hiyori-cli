<?php

namespace Hiyori\Models\Common\Identifiers;

class TubiTV extends Identifier
{
    public const SHORTHAND = "ttv";
    public const SOURCE_NAME = "tubitv";
    public const PATTERN = "^https:\/\/tubitv\.com\/series\/([\d]+)(\/.*|)";
}