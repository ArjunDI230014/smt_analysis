<?php

namespace App\Libraries;

class AgentSearch
{
    public static function normalize(string $value): string
    {
        $value = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()', $value);
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }

    /** Match every search word, in any order, allowing small spelling mistakes. */
    public static function matches(string $name, string $search): bool
    {
        $name = self::normalize($name);
        $search = self::normalize($search);
        if ($search === '') {
            return false;
        }
        $words = explode(' ', $name);
        foreach (explode(' ', $search) as $term) {
            if (str_contains($name, $term)) {
                continue;
            }
            $distance = strlen($term) >= 7 ? 2 : (strlen($term) >= 4 ? 1 : 0);
            $matched = false;
            foreach ($words as $word) {
                if ($distance > 0 && levenshtein($term, $word) <= $distance) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
    }
}
