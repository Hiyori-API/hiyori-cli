<?php

declare(strict_types=1);

namespace Hiyori\Service;

final class Validator
{
    /**
     * Validate an entry (serialized array) against the Hiyori schema.
     *
     * @param array $entry Serialized entry (as stored in MongoDB)
     * @return array<string, string[]> Map of field => list of violation messages
     */
    public function validate(array $entry): array
    {
        $errors = [];

        // Title: required, must be a non-empty string
        if (!isset($entry['title']) || !is_string($entry['title']) || trim($entry['title']) === '') {
            $errors['title'][] = 'title is required and must be a non-empty string';
        }

        // Synonyms: optional array of strings
        if (isset($entry['synonyms']) && !$this->isArrayOfStrings($entry['synonyms'])) {
            $errors['synonyms'][] = 'synonyms must be an array of strings';
        }

        // Type: optional valid enum value
        if (isset($entry['type']) && is_string($entry['type'])) {
            $validTypes = ['tv', 'movie', 'ova', 'ona', 'special', 'music',
                'manga', 'novel', 'light_novel', 'one_shot', 'doujinshi', 'manhwa', 'manhua', 'oel'];
            if (!in_array($entry['type'], $validTypes, true)) {
                $errors['type'][] = "type '{$entry['type']}' is not a valid enum value";
            }
        }

        // Episodes / chapters / volumes: optional positive integers
        foreach (['episodes', 'chapters', 'volumes'] as $intField) {
            if (isset($entry[$intField]) && $entry[$intField] !== null) {
                if (!is_numeric($entry[$intField]) || (int) $entry[$intField] < 0) {
                    $errors[$intField][] = "$intField must be a non-negative integer";
                }
            }
        }

        // Status: optional valid value
        if (isset($entry['status']) && is_string($entry['status'])) {
            $validStatuses = ['finished', 'airing', 'to_be_aired', 'cancelled',
                'publishing', 'not_yet_published', 'on_hiatus', 'discontinued'];
            if (!in_array($entry['status'], $validStatuses, true)) {
                $errors['status'][] = "status '{$entry['status']}' is not a valid enum value";
            }
        }

        // Year: optional integer
        if (isset($entry['year']) && $entry['year'] !== null && (!is_numeric($entry['year']) || (int) $entry['year'] < 1900)) {
            $errors['year'][] = 'year must be a valid integer >= 1900';
        }

        // Images: optional array of valid URLs
        if (isset($entry['images']) && !$this->isArrayOfUrls($entry['images'])) {
            $errors['images'][] = 'images must be an array of valid URLs';
        }

        // References: required non-empty array of URLs
        if (empty($entry['references']) || !$this->isArrayOfUrls($entry['references'])) {
            $errors['references'][] = 'references must be a non-empty array of valid URLs';
        }

        // Reference IDs: optional object/array of scalars
        if (isset($entry['reference_ids']) && !$this->isScalarMap($entry['reference_ids'])) {
            $errors['reference_ids'][] = 'reference_ids must be an object of scalar values';
        }

        // Tags: optional array of strings
        if (isset($entry['tags']) && !$this->isArrayOfStrings($entry['tags'])) {
            $errors['tags'][] = 'tags must be an array of strings';
        }

        return $errors;
    }

    /**
     * Validate and return a normalized entry, or throw on fatal violations.
     *
     * @throws \InvalidArgumentException when entry fails fatal validation
     */
    public function validateOrFail(array $entry): array
    {
        $errors = $this->validate($entry);

        if ($errors !== []) {
            $flat = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $flat[] = "[$field] $message";
                }
            }
            throw new \InvalidArgumentException('Validation failed: ' . implode('; ', $flat));
        }

        return $entry;
    }

    /**
     * Validate the raw API payload (pre-transformation) for required fields.
     */
    public function validatePayload(array $payload): array
    {
        $errors = [];
        if (empty($payload['title'])) {
            $errors['title'][] = 'payload has no title';
        }
        if (empty($payload['id']) && empty($payload['mal_id'])) {
            $errors['id'][] = 'payload has no identifier';
        }

        return $errors;
    }

    private function isArrayOfStrings(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private function isArrayOfUrls(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || filter_var($item, FILTER_VALIDATE_URL) === false) {
                return false;
            }
        }

        return true;
    }

    private function isScalarMap(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return false;
            }
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }
}
