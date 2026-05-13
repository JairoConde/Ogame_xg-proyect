<?php

declare(strict_types=1);

/**
 * Encode/decode bot-managed alliance metadata stored in alliance_request (TEXT).
 * Human alliances keep free text; bots store JSON with bot_managed=true.
 */
if (!function_exists('botAllianceParseRequirements')) {
    /**
     * @return array<string, mixed>|null Parsed meta with keys bot_managed, requirements, soft_text, etc.
     */
    function botAllianceParseRequirements(?string $rawRequest): ?array
    {
        if ($rawRequest === null || $rawRequest === '') {
            return null;
        }
        $trim = ltrim($rawRequest);
        if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
            return null;
        }
        $decoded = json_decode($rawRequest, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (empty($decoded['bot_managed'])) {
            return null;
        }

        return $decoded;
    }
}

if (!function_exists('botAllianceEncodeRequirements')) {
    /**
     * @param array<string, mixed> $meta Full meta object (will be JSON-encoded).
     */
    function botAllianceEncodeRequirements(array $meta): string
    {
        return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}

if (!function_exists('botAllianceHumanReadableRequest')) {
    /**
     * Text shown to humans when alliance_request holds bot JSON.
     */
    function botAllianceHumanReadableRequest(?string $rawRequest, string $fallback): string
    {
        $meta = botAllianceParseRequirements($rawRequest);
        if ($meta === null) {
            return (string) $rawRequest;
        }
        $soft = isset($meta['soft_text']) ? (string) $meta['soft_text'] : '';

        return $soft !== '' ? $soft : $fallback;
    }
}
