<?php

namespace App\Support;

/**
 * Turns plain detail/comment text into safe HTML.
 *
 * One pass over a short string (comments are capped at 5,000 characters,
 * descriptions at 10,000). No client editor, so opening a panel stays cheap.
 */
class TextFormatter
{
    public static function render(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $parts = preg_split(
            '/(\[[^\]\r\n]{1,300}\]\((?:https?:\/\/|www\.)[^\s)]+\)|(?<![\w@\/])(?:https?:\/\/|www\.)[^\s<]+|@[A-Za-z0-9_]+)/i',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($parts === false) {
            return e($text);
        }

        $html = '';
        foreach ($parts as $part) {
            $html .= self::renderPart($part);
        }

        return $html;
    }

    private static function renderPart(string $part): string
    {
        if (preg_match('/^\[([^\]\r\n]{1,300})\]\(((?:https?:\/\/|www\.)[^\s)]+)\)$/i', $part, $matches) === 1) {
            $url = self::safeHttpUrl(self::withScheme($matches[2]));
            if ($url !== null) {
                return self::anchor($url, self::withMentions(e($matches[1])));
            }
        }

        if (preg_match('/^(?:https?:\/\/|www\.)[^\s<]+$/i', $part) === 1) {
            [$core, $trail] = self::trimTrail($part);
            if ($core !== '' && ! str_ends_with($core, '...')) {
                $url = self::safeHttpUrl(self::withScheme($core));
                if ($url !== null) {
                    return self::anchor($url, e($core)).e($trail);
                }
            }
        }

        if (preg_match('/^@[A-Za-z0-9_]+$/', $part) === 1) {
            return self::mention($part);
        }

        return self::withMentions(e($part));
    }

    private static function withScheme(string $url): string
    {
        if (preg_match('/^www\./i', $url) === 1) {
            return 'https://'.$url;
        }

        return $url;
    }

    private static function safeHttpUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return preg_replace('/^[a-z][a-z0-9+.\-]*:/i', $scheme.':', $url, 1) ?? $url;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function trimTrail(string $url): array
    {
        $trail = '';

        while ($url !== '') {
            $trimmed = false;

            foreach (['.', ',', ';', ':', '!', '?', "'", '"', '>'] as $char) {
                // Keep "..." so a shortened preview is not turned into a broken link.
                if ($char === '.' && str_ends_with($url, '...')) {
                    continue;
                }
                if (str_ends_with($url, $char)) {
                    $trail = $char.$trail;
                    $url = substr($url, 0, -strlen($char));
                    $trimmed = true;
                    break;
                }
            }

            if ($trimmed) {
                continue;
            }

            foreach ([')' => '(', ']' => '['] as $close => $open) {
                if (str_ends_with($url, $close) && substr_count($url, $close) > substr_count($url, $open)) {
                    $trail = $close.$trail;
                    $url = substr($url, 0, -strlen($close));
                    $trimmed = true;
                    break;
                }
            }

            if (! $trimmed) {
                break;
            }
        }

        return [$url, $trail];
    }

    private static function withMentions(string $escaped): string
    {
        $mentioned = preg_replace(
            '/@([A-Za-z0-9_]+)/',
            '<span class="font-medium text-blue-600">@$1</span>',
            $escaped
        );

        return $mentioned ?? $escaped;
    }

    private static function mention(string $token): string
    {
        return '<span class="font-medium text-blue-600">'.e($token).'</span>';
    }

    private static function anchor(string $url, string $labelHtml): string
    {
        return '<a href="'.e($url).'" title="'.e($url).'" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-600 underline decoration-blue-300 underline-offset-2 break-all hover:text-blue-800">'.$labelHtml.'</a>';
    }
}
