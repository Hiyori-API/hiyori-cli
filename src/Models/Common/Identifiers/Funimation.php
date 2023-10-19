<?php

namespace Hiyori\Models\Common\Identifiers;

class Funimation extends Identifier
{
    public const SHORTHAND = "fm";
    public const SOURCE_NAME = "funimation";
    public const PATTERN = "funimation\.com\/shows\/(.*)";
    public const ENTRY_URL = "https://www.funimation.com/shows/%s";
}