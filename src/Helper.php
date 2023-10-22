<?php

namespace Hiyori;

use Hiyori\Models\Anime\Season;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class Helper
{
    public static function cleanse(string $string): string
    {
        return trim($string);
    }

    public static function prepareAsIdentifer(string $string): string
    {
        return strtolower(
            self::cleanse($string)
        );
    }

    public static function toArray(string $json): array
    {
        return json_decode($json, true);
    }

    // https://stackoverflow.com/a/76375080
    public static function convertMongoResultToArray($result): array|string
    {
        if (is_object($result)) {
            if ($result instanceof \MongoDB\Model\BSONDocument) {
                $result = $result->getArrayCopy();
            } elseif ($result instanceof \MongoDB\Model\BSONArray) {
                $result = iterator_to_array($result);
            } elseif ($result instanceof \MongoDB\BSON\ObjectId) {
                $result = (string) $result;
            }

            if (is_array($result)) {
                foreach ($result as $key => $value) {
                    $result[$key] = self::convertMongoResultToArray($value);
                }
            }
        }

        return $result;
    }
}