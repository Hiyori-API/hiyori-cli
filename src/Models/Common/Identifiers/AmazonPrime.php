<?php

namespace Hiyori\Models\Common\Identifiers;

class AmazonPrime extends Identifier
{
    public const SHORTHAND = "ap";
    public const SOURCE_NAME = "amazon_prime";
    public const PATTERN = "amazon\.com\/gp\/video\/detail\/([A-Z\d]+)/";
    public const ENTRY_URL = "https://www.amazon.com/gp/video/detail/%s";
}