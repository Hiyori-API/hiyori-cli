<?php

namespace Hiyori\Models\Common\Identifiers;

class Funimation extends Identifier
{
    public const SHORTHAND = "fm";
    public const SOURCE_NAME = "funimation";
    public const PATTERN = "^https:\/\/www\.funimation\.com\/shows\/([a-z\-]+)";
}