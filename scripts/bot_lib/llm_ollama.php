<?php

declare(strict_types=1);

/**
 * Cliente HTTP mínimo para Ollama (/api/chat). Sin dependencias externas.
 *
 * Activación (entorno):
 *   BOT_LLM_ENABLED=1
 *   BOT_LLM_URL=http://127.0.0.1:11434   (sin barra final; o http://host.docker.internal:11434)
 *   BOT_LLM_MODEL=llama3.2
 *   BOT_LLM_TIMEOUT_SEC=20
 *   BOT_LLM_LOG_PROMPTS=1          (opcional: imprime en stdout el JSON a POST /api/chat; sale en docker compose logs llm-worker)
 *   BOT_LLM_LOG_PROMPTS_MAX_BYTES  (opcional: truncar; default 24000, máx. 524288)
 *
 * Si BOT_LLM_URL contiene host.docker.internal y falla la red (p. ej. WSL),
 * se sustituye automáticamente por la puerta de enlace por defecto del
 * contenedor (véase /proc/net/route), que suele ser el host donde corre Ollama.
 */
if (!function_exists('botLlmIsEnabled')) {
    function botLlmIsEnabled(): bool
    {
        $v = getenv('BOT_LLM_ENABLED');

        return $v !== false && in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('botLlmDefaultGatewayFromProc')) {
    /**
     * IPv4 del default gateway en Linux (p. ej. host Docker desde un contenedor).
     */
    function botLlmDefaultGatewayFromProc(): ?string
    {
        $raw = @file('/proc/net/route', FILE_IGNORE_NEW_LINES);
        if ($raw === false) {
            return null;
        }
        array_shift($raw);
        foreach ($raw as $line) {
            $t = preg_split('/\s+/', trim($line));
            if (count($t) < 3) {
                continue;
            }
            if ($t[1] !== '00000000' || $t[2] === '00000000') {
                continue;
            }
            $hex = $t[2];
            if (strlen($hex) !== 8 || !ctype_xdigit($hex)) {
                continue;
            }
            $parts = str_split($hex, 2);

            return (string) hexdec($parts[3]) . '.' . (string) hexdec($parts[2]) . '.'
                . (string) hexdec($parts[1]) . '.' . (string) hexdec($parts[0]);
        }

        return null;
    }
}

if (!function_exists('botLlmResolveOllamaBaseUrl')) {
    function botLlmResolveOllamaBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            $gw = botLlmDefaultGatewayFromProc();

            return $gw !== null ? "http://{$gw}:11434" : 'http://127.0.0.1:11434';
        }
        if (strcasecmp($url, 'auto') === 0) {
            $gw = botLlmDefaultGatewayFromProc();

            return $gw !== null ? "http://{$gw}:11434" : 'http://127.0.0.1:11434';
        }
        if (stripos($url, 'host.docker.internal') !== false) {
            $gw = botLlmDefaultGatewayFromProc();
            if ($gw !== null) {
                return str_ireplace('host.docker.internal', $gw, $url);
            }
        }

        return $url;
    }
}

if (!function_exists('botLlmOllamaConfig')) {
    /**
     * @return array{url: string, model: string, timeout_sec: float}
     */
    function botLlmOllamaConfig(): array
    {
        $url = getenv('BOT_LLM_URL');
        if ($url === false || trim((string) $url) === '') {
            $url = 'http://127.0.0.1:11434';
        } else {
            $url = (string) $url;
        }
        $url = botLlmResolveOllamaBaseUrl($url);
        $model = getenv('BOT_LLM_MODEL');
        if ($model === false || trim((string) $model) === '') {
            $model = 'llama3.2';
        }
        $t = getenv('BOT_LLM_TIMEOUT_SEC');
        $timeout = $t !== false && is_numeric($t) ? (float) $t : 20.0;
        $timeout = max(1.0, min(120.0, $timeout));

        return [
            'url' => $url,
            'model' => (string) $model,
            'timeout_sec' => $timeout,
        ];
    }
}

if (!function_exists('botLlmLogPromptsEnabled')) {
    function botLlmLogPromptsEnabled(): bool
    {
        $v = getenv('BOT_LLM_LOG_PROMPTS');

        return $v !== false && in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('botLlmLogPromptsMaxBytes')) {
    function botLlmLogPromptsMaxBytes(): int
    {
        $v = getenv('BOT_LLM_LOG_PROMPTS_MAX_BYTES');
        if ($v !== false && is_numeric($v)) {
            return max(1024, min(524288, (int) $v));
        }

        return 24000;
    }
}

if (!function_exists('botLlmLogOllamaRequest')) {
    /**
     * Volcado del cuerpo POST a Ollama (solo si BOT_LLM_LOG_PROMPTS está activo).
     * Va a stdout para que coincida con el resto de líneas del llm_worker en Docker.
     */
    function botLlmLogOllamaRequest(?int $jobId, string $jsonBody): void
    {
        if (!botLlmLogPromptsEnabled()) {
            return;
        }
        $max = botLlmLogPromptsMaxBytes();
        $suffix = '';
        if (strlen($jsonBody) > $max) {
            $jsonBody = substr($jsonBody, 0, $max);
            $suffix = "\n... [truncado, BOT_LLM_LOG_PROMPTS_MAX_BYTES={$max}]";
        }
        $tag = $jobId !== null ? "job_id={$jobId}" : 'job_id=?';
        $chunk = date('c') . " llm_ollama_request {$tag} POST /api/chat body:\n" . $jsonBody . $suffix . "\n";
        fwrite(STDOUT, $chunk);
        fflush(STDOUT);
    }
}

if (!function_exists('botLlmExtractJsonObject')) {
    /**
     * @return array<string, mixed>|null
     */
    function botLlmExtractJsonObject(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        if (preg_match('/^```(?:json)?\s*(\{[\s\S]*\})\s*```$/iu', $content, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}

if (!function_exists('botLlmOllamaChat')) {
    /**
     * Una llamada no streaming a /api/chat. Devuelve el texto del assistant o null.
     *
     * @param int|null $logJobId Si no es null y BOT_LLM_LOG_PROMPTS=1, se incluye en el volcado a stdout.
     */
    function botLlmOllamaChat(string $systemPrompt, string $userPrompt, ?int $logJobId = null): ?string
    {
        if (!botLlmIsEnabled()) {
            return null;
        }
        $cfg = botLlmOllamaConfig();
        $payload = [
            'model' => $cfg['model'],
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'options' => [
                'temperature' => 0.35,
                'num_predict' => 512,
            ],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (botLlmLogPromptsEnabled()) {
            $pretty = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );
            botLlmLogOllamaRequest($logJobId, $pretty);
        }
        $url = $cfg['url'] . '/api/chat';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            $tOut = (int) ceil($cfg['timeout_sec']);
            $curlOpts = [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(15, max(2, (int) ceil($cfg['timeout_sec'] / 4))),
                CURLOPT_TIMEOUT => $tOut,
            ];
            if (defined('CURL_IPRESOLVE_V4')) {
                $curlOpts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            curl_setopt_array($ch, $curlOpts);
            $raw = curl_exec($ch);
            curl_close($ch);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $json,
                    'timeout' => $cfg['timeout_sec'],
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
        }

        try {
            $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        if (!is_array($envelope)) {
            return null;
        }
        $msg = $envelope['message'] ?? null;
        if (!is_array($msg)) {
            return null;
        }
        $content = $msg['content'] ?? null;

        return is_string($content) ? $content : null;
    }
}
