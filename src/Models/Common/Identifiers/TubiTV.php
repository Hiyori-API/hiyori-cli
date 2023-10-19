<?php

namespace Hiyori\Models\Common\Identifiers;

class TubiTV extends Identifier
{
    public const SHORTHAND = "ttv";
    public const SOURCE_NAME = "tubitv";
    public const PATTERN = "tubitv\.com\/series\/([\d]+)(\/.*|)";
    public const ENTRY_URL = "https://tubitv.com/series/%s";

}