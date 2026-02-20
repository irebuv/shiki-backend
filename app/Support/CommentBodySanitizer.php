<?php

namespace App\Support;

class CommentBodySanitizer{
    public function sanitize(string $raw): string{
        //Basic XSS protection: remove any HTML tags.
        $value = strip_tags($raw);
        $value = trim($value);

        //Normalize repeated spaces and large line breaks.
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\R{3,}/u', "\n\n", $value) ?? $value;

        return trim($value);
    }
}
