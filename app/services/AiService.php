<?php

namespace App\services;

class AiService
{
    public static function isConfigured(): bool
    {
        return self::apiKey() !== '';
    }

    public static function apiKey(): string
    {
        return (string)($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '');
    }

    public static function baseUrl(): string
    {
        $url = (string)($_ENV['OPENAI_BASE_URL'] ?? getenv('OPENAI_BASE_URL') ?: '');
        return $url !== '' ? rtrim($url, '/') : 'https://api.openai.com/v1';
    }

    public static function model(): string
    {
        return (string)($_ENV['OPENAI_MODEL'] ?? getenv('OPENAI_MODEL') ?: 'gpt-5.4-mini');
    }

    /**
     * Free-form DB Q&A. Sends schemas + row counts for all tables and returns the AI's answer as Markdown.
     *
     * @param list<array{table:string,columns:array,rowCount:?int}> $tableSchemas
     */
    public static function ask(string $userPrompt, string $driver, string $dbName, array $tableSchemas): string
    {
        $systemPrompt = self::buildAskSystemPrompt($driver, $dbName, $tableSchemas);
        return self::chat($systemPrompt, $userPrompt);
    }

    /**
     * @param list<array{table:string,columns:array}> $selectedSchemas
     * @param list<string> $allTables
     */
    public static function generate(
        string $userPrompt,
        string $driver,
        string $dbName,
        array $allTables,
        array $selectedSchemas
    ): string {
        $systemPrompt = self::buildSystemPrompt($driver, $dbName, $allTables, $selectedSchemas);
        return self::chat($systemPrompt, $userPrompt);
    }

    private static function chat(string $systemPrompt, string $userPrompt): string
    {
        $payload = [
            'model'       => self::model(),
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'temperature' => 0.2,
        ];

        $ch = curl_init(self::baseUrl() . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::apiKey(),
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('OpenAI request failed: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $status);
            throw new \RuntimeException('OpenAI error: ' . $msg);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || $content === '') {
            throw new \RuntimeException('OpenAI returned empty response');
        }
        return $content;
    }

    private static function buildSystemPrompt(
        string $driver,
        string $dbName,
        array $allTables,
        array $selectedSchemas
    ): string {
        $lines = [];
        $lines[] = "You are an expert SQL assistant for a {$driver} database named \"{$dbName}\".";
        $lines[] = "Generate a single SQL query that fulfills the user's request.";
        $lines[] = "Reply in Markdown with TWO distinct parts:";
        $lines[] = "1. A single ```sql fenced code block containing the query (and ONLY the SQL — no comments inside the block).";
        $lines[] = "2. Commentary OUTSIDE the code block: mention any assumptions you made, ambiguities in the request, and — when relevant — brief hints on how the user could rephrase their request to get a different shape of query (e.g. aggregation vs raw rows, join strategies, filtering options). Keep it concise.";
        $lines[] = "If the request is too ambiguous to pick one query confidently, still produce your best guess in the SQL block and explain the ambiguity and alternative phrasings in the commentary.";
        $lines[] = "Use only the tables and columns listed below. Do not invent names.";
        $lines[] = "Respond in the same language as the user's prompt.";
        $lines[] = '';
        $lines[] = 'Available tables in this database:';
        $lines[] = empty($allTables) ? '(none)' : '- ' . implode("\n- ", $allTables);

        if (!empty($selectedSchemas)) {
            $lines[] = '';
            $lines[] = 'Schemas for user-selected tables:';
            foreach ($selectedSchemas as $s) {
                $lines[] = '';
                $lines[] = "Table: {$s['table']}";
                foreach ($s['columns'] as $col) {
                    $nn  = ($col['Null'] ?? 'YES') === 'NO' ? ' NOT NULL' : '';
                    $pk  = ($col['Key'] ?? '') === 'PRI' ? ' PRIMARY KEY' : '';
                    $def = ($col['Default'] ?? null) !== null ? " DEFAULT {$col['Default']}" : '';
                    $lines[] = "  - {$col['Field']} {$col['Type']}{$nn}{$pk}{$def}";
                }
            }
        }

        return implode("\n", $lines);
    }

    private static function buildAskSystemPrompt(string $driver, string $dbName, array $tableSchemas): string
    {
        $lines = [];
        $lines[] = "You are a database expert answering questions about a {$driver} database named \"{$dbName}\".";
        $lines[] = "The user will ask free-form questions about the schema, data model, or how to accomplish something.";
        $lines[] = "Reply in Markdown. Be concise and direct. Use fenced code blocks when showing SQL or identifiers.";
        $lines[] = "Only reference tables and columns listed below. Do not invent names.";
        $lines[] = "Row counts reflect the current database state and may be approximate for very large tables.";
        $lines[] = "Respond in the same language as the user's question.";
        $lines[] = '';
        $lines[] = 'Tables (with row counts and schemas):';

        if (empty($tableSchemas)) {
            $lines[] = '(none)';
        } else {
            foreach ($tableSchemas as $s) {
                $lines[] = '';
                $count = $s['rowCount'] ?? null;
                $countStr = $count === null ? 'unknown' : (string)$count;
                $lines[] = "Table: {$s['table']} (rows: {$countStr})";
                foreach ($s['columns'] as $col) {
                    $nn  = ($col['Null'] ?? 'YES') === 'NO' ? ' NOT NULL' : '';
                    $pk  = ($col['Key'] ?? '') === 'PRI' ? ' PRIMARY KEY' : '';
                    $def = ($col['Default'] ?? null) !== null ? " DEFAULT {$col['Default']}" : '';
                    $lines[] = "  - {$col['Field']} {$col['Type']}{$nn}{$pk}{$def}";
                }
            }
        }

        return implode("\n", $lines);
    }
}
