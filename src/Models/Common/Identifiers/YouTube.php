<?php

namespace Hiyori\Models\Common\Identifiers;

class YouTube extends Identifier
{
    public const SHORTHAND = "yt";
    public const SOURCE_NAME = "youtube";
    public const PATTERN = "^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]{11})";
    public const ENTRY_URL = "https://www.youtube.com/watch?v=%s";
}