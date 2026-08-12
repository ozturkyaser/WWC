<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImpressumParserService
{
    public function analyze(string $siteUrl): array
    {
        $base = rtrim($siteUrl, '/');
        if (! preg_match('#^https?://#i', $base)) {
            $base = 'https://'.$base;
        }

        $candidates = [
            $base.'/impressum',
            $base.'/impressum/',
            $base.'/imprint',
            $base.'/legal-notice',
            $base.'/kontakt/impressum',
            $base.'/?page_id=impressum',
            $base,
        ];

        $html = '';
        $usedUrl = null;
        foreach ($candidates as $url) {
            try {
                $res = Http::timeout(12)
                    ->withHeaders(['User-Agent' => 'WWC-ImpressumBot/1.0'])
                    ->get($url);
                if (! $res->successful()) {
                    continue;
                }
                $body = (string) $res->body();
                $lower = mb_strtolower($body);
                if (
                    str_contains($lower, 'impressum')
                    || str_contains($lower, 'anbieterkennzeichnung')
                    || str_contains($lower, 'ust-id')
                    || str_contains($lower, 'handelsregister')
                    || $url === $base
                ) {
                    $html = $body;
                    $usedUrl = $url;
                    if ($url !== $base || str_contains($lower, 'impressum')) {
                        break;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($html === '') {
            return [
                'ok' => false,
                'error' => 'Impressum-Seite nicht erreichbar',
                'impressum_url' => null,
                'client' => $this->emptyClient($base),
                'source' => 'none',
            ];
        }

        $text = $this->htmlToText($html);
        $heuristic = $this->extractHeuristic($text, $base);
        $ai = $this->extractWithAi($text);
        $client = $this->mergeClient($heuristic, $ai, $base);

        return [
            'ok' => true,
            'impressum_url' => $usedUrl,
            'client' => $client,
            'excerpt' => Str::limit($text, 1200),
            'source' => $ai ? 'ai+heuristic' : 'heuristic',
        ];
    }

    private function emptyClient(string $base): array
    {
        $host = parse_url($base, PHP_URL_HOST) ?: $base;

        return [
            'name' => $host,
            'company' => null,
            'email' => null,
            'address' => null,
            'vat_id' => null,
        ];
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function extractHeuristic(string $text, string $base): array
    {
        $client = $this->emptyClient($base);

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
            $client['email'] = strtolower($m[0]);
        }

        if (preg_match('/(?:USt[\-\s]?Id(?:Nr)?\.?|Umsatzsteuer[\-\s]?ID|VAT)\s*[:\.]?\s*([A-Z]{2}\s?[0-9A-Z]+)/iu', $text, $m)) {
            $client['vat_id'] = preg_replace('/\s+/', '', $m[1]);
        }

        if (preg_match('/((?:[A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+){0,4})\s+(?:GmbH|UG|AG|KG|OHG|e\.?\s?K\.?|GbR))/u', $text, $m)) {
            $client['company'] = trim($m[1]);
            $client['name'] = $client['company'];
        } elseif (preg_match('/Impressum\s+([^\n]{5,80})/iu', $text, $m)) {
            $client['name'] = trim($m[1]);
        }

        if (preg_match('/(\d{5}\s+[A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ\-\s]+)/u', $text, $m)) {
            $street = null;
            if (preg_match('/([A-ZÄÖÜa-zäöüß\.\-\s]+\s+\d+[a-zA-Z]?)\s+'.$m[1].'/u', $text, $s)) {
                $street = trim($s[1]);
            }
            $client['address'] = trim(($street ? $street."\n" : '').$m[1]);
        }

        return $client;
    }

    private function extractWithAi(string $text): ?array
    {
        $key = config('wwc.ai_api_key');
        if (! $key || strlen($text) < 40) {
            return null;
        }

        try {
            $prompt = "Extrahiere aus folgendem deutschen Impressum-Text strukturierte Kundendaten als reines JSON "
                ."mit Keys name, company, email, address, vat_id. Fehlende Felder als null.\n\n"
                .Str::limit($text, 6000);

            $res = Http::timeout(30)
                ->withToken($key)
                ->post(rtrim((string) config('wwc.ai_api_base'), '/').'/chat/completions', [
                    'model' => config('wwc.ai_model'),
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'Du extrahierst Impressumsdaten für CRM. Antworte nur mit JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (! $res->successful()) {
                return null;
            }
            $content = data_get($res->json(), 'choices.0.message.content');
            $data = is_string($content) ? json_decode($content, true) : null;

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function mergeClient(array $heuristic, ?array $ai, string $base): array
    {
        $out = $this->emptyClient($base);
        foreach (['name', 'company', 'email', 'address', 'vat_id'] as $key) {
            $out[$key] = $ai[$key] ?? $heuristic[$key] ?? $out[$key];
            if (is_string($out[$key])) {
                $out[$key] = trim($out[$key]) ?: null;
            }
        }
        if (! $out['name']) {
            $out['name'] = $out['company'] ?: (parse_url($base, PHP_URL_HOST) ?: 'Neuer Kunde');
        }

        return $out;
    }
}
