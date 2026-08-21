<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImpressumParserService
{
    private const UA = 'Mozilla/5.0 (compatible; WWC-ImpressumBot/1.1; +https://wwc.kiservicehub.de)';

    public function analyze(string $siteUrl): array
    {
        $base = rtrim($siteUrl, '/');
        if (! preg_match('#^https?://#i', $base)) {
            $base = 'https://'.$base;
        }

        $fetched = $this->fetchImpressum($base);
        if ($fetched === null) {
            return [
                'ok' => false,
                'error' => 'Impressum-Seite nicht erreichbar',
                'impressum_url' => null,
                'client' => $this->emptyClient($base),
                'source' => 'none',
            ];
        }

        $text = $this->htmlToText($fetched['html']);
        $heuristic = $this->extractHeuristic($text, $base);
        $ai = $this->extractWithAi($this->impressumWindow($text));
        $client = $this->mergeClient($heuristic, $ai, $base);

        $ok = filled($client['company']) || filled($client['email']) || filled($client['address']) || filled($client['vat_id']);

        return [
            'ok' => $ok,
            'error' => $ok ? null : 'Impressum gefunden, Angaben unvollständig – bitte ergänzen.',
            'impressum_url' => $fetched['url'],
            'client' => $client,
            'excerpt' => Str::limit($this->impressumWindow($text), 1200),
            'source' => $ai ? 'ai+heuristic' : 'heuristic',
        ];
    }

    /**
     * @return array{url: string, html: string}|null
     */
    private function fetchImpressum(string $base): ?array
    {
        $home = $this->httpGet($base);
        $discovered = $home ? $this->discoverImpressumUrls($base, $home['html']) : [];

        $candidates = array_values(array_unique(array_merge($discovered, [
            $base.'/impressum',
            $base.'/impressum/',
            $base.'/impressum.html',
            $base.'/de/impressum',
            $base.'/de/impressum/',
            $base.'/imprint',
            $base.'/imprint/',
            $base.'/legal-notice',
            $base.'/legal',
            $base.'/kontakt/impressum',
            $base.'/ueber-uns/impressum',
            $base.'/?page_id=impressum',
        ])));

        foreach ($candidates as $url) {
            $page = $this->httpGet($url);
            if (! $page) {
                continue;
            }
            if ($this->looksLikeImpressum($page['html'], dedicated: true)) {
                return $page;
            }
        }

        if ($home && $this->looksLikeImpressum($home['html'], dedicated: false)) {
            return $home;
        }

        return $home;
    }

    /**
     * @return array{url: string, html: string}|null
     */
    private function httpGet(string $url): ?array
    {
        foreach ([true, false] as $verify) {
            try {
                $res = Http::timeout(15)
                    ->withOptions(['allow_redirects' => true, 'verify' => $verify])
                    ->withHeaders([
                        'User-Agent' => self::UA,
                        'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.5',
                    ])
                    ->get($url);
                if (! $res->successful()) {
                    continue;
                }
                $html = (string) $res->body();
                if (strlen($html) < 80) {
                    continue;
                }

                return [
                    'url' => (string) ($res->effectiveUri() ?? $url),
                    'html' => $html,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** @return string[] */
    private function discoverImpressumUrls(string $base, string $html): array
    {
        $found = [];
        if (! preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $m) {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $hay = mb_strtolower(strip_tags($m[2]).' '.$href);
            if (! preg_match('/impressum|imprint|legal[-_ ]?notice|anbieterkennzeichnung/', $hay)) {
                continue;
            }
            if (preg_match('/datenschutz|privacy|agb|cookie|login|mailto:|tel:/', $hay) && ! preg_match('/impressum|imprint/', $hay)) {
                continue;
            }
            $abs = $this->absolutize($base, $href);
            if ($abs) {
                $found[] = $abs;
            }
        }

        return array_values(array_unique($found));
    }

    private function absolutize(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$href;
        }

        return rtrim($base, '/').'/'.$href;
    }

    private function looksLikeImpressum(string $html, bool $dedicated): bool
    {
        $lower = mb_strtolower($html);
        $signals = 0;
        foreach (['impressum', 'anbieterkennzeichnung', '§ 5 tmg', '§5 tmg', 'ust-id', 'umsatzsteuer', 'handelsregister', 'vertreten durch'] as $needle) {
            if (str_contains($lower, $needle)) {
                $signals++;
            }
        }

        return $dedicated ? $signals >= 1 : $signals >= 2;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\s*\/\s*(p|div|h[1-6]|li|tr|section|article|header|footer|blockquote)\s*>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $text = $normalized;
            }
        }
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function impressumWindow(string $text): string
    {
        if (preg_match('/((?:Impressum|Angaben\s+gemäß\s*§\s*5\s*TMG).{0,2500}?)(?:Haftung für Inhalte|EU-Streitschlichtung|Datenschutzerklärung|$)/isu', $text, $m)) {
            return trim($m[1]);
        }

        return Str::limit($text, 4000, '');
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

    private function extractHeuristic(string $text, string $base): array
    {
        $client = $this->emptyClient($base);
        $window = $this->impressumWindow($text);
        $tmg = $this->extractTmgBlock($window) ?? $this->extractTmgBlock($text);

        if ($tmg) {
            $client = array_merge($client, array_filter($tmg, fn ($v) => filled($v)));
        }

        if (! $client['email'] && preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $window, $mails)) {
            $client['email'] = $this->preferEmail($mails[0]);
        }

        if (! $client['vat_id']) {
            $client['vat_id'] = $this->extractVatId($window) ?? $this->extractVatId($text);
        }

        if (! $client['company'] && preg_match('/\b((?:[A-ZÄÖÜ0-9][A-Za-zÄÖÜäöüß0-9.&\/\-]*(?:\s+[A-ZÄÖÜ0-9][A-Za-zÄÖÜäöüß0-9.&\/\-]*){0,6})\s+(?:GmbH(?:\s*&\s*Co\.?\s*KG)?|UG(?:\s*\(haftungsbeschränkt\))?|AG|KG|OHG|e\.?\s?K\.?|GbR|e\.V\.))\b/u', $window, $m)) {
            $client['company'] = trim($m[1]);
        }

        if (! $client['address'] && preg_match('/([A-ZÄÖÜa-zäöüß.\/\-]+(?:straße|strasse|str\.|weg|platz|allee|ring|gasse)\s+\d+[a-zA-Z]?)\s+(\d{5}\s+[A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ\-]+)/iu', $window, $m)) {
            $client['address'] = trim($m[1]."\n".$m[2]);
        }

        if (! empty($client['company'])) {
            $client['name'] = $client['company'];
        } elseif (! $client['name'] || $client['name'] === (parse_url($base, PHP_URL_HOST) ?: $base)) {
            $client['name'] = $client['company'] ?: $client['name'];
        }

        return $client;
    }

    /** @return array{name?: string, company?: ?string, email?: ?string, address?: ?string, vat_id?: ?string}|null */
    private function extractTmgBlock(string $text): ?array
    {
        if (! preg_match('/Angaben\s+gemäß\s*§\s*5\s*TMG\s*(.+?)(?:Verbraucherstreit|EU-Streitschlichtung|Haftung für Inhalte|$)/isu', $text, $m)) {
            return null;
        }

        $block = trim($m[1]);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $block) ?: []), fn ($l) => $l !== ''));
        if ($lines === []) {
            return null;
        }

        $out = [
            'company' => null,
            'email' => null,
            'address' => null,
            'vat_id' => $this->extractVatId($block),
            'name' => null,
        ];

        $street = null;
        $city = null;
        foreach ($lines as $line) {
            $plain = trim($line, " \t:-");
            if (preg_match('/e-?mail\s*:\s*(.+)/iu', $plain, $em)) {
                $out['email'] = $this->preferEmail([trim($em[1])]);
                continue;
            }
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $plain, $em) && ! $out['email']) {
                $out['email'] = strtolower($em[0]);
                continue;
            }
            if (preg_match('/^(Telefon|Telefax|Tel\.|Fax|Handelsregister|Registergericht|Vertreten)/iu', $plain)) {
                continue;
            }
            if (! $out['company'] && preg_match('/GmbH|UG|AG|KG|OHG|GbR|e\.K|e\.V/i', $plain)) {
                $out['company'] = $plain;
                continue;
            }
            if (! $street && preg_match('/\d+[a-zA-Z]?$/', $plain) && preg_match('/[A-Za-zÄÖÜäöüß]/', $plain) && ! preg_match('/^HRB|Telefon|Telefax/i', $plain)) {
                $street = $plain;
                continue;
            }
            if (! $city && preg_match('/^(\d{5})\s+(.+)$/u', $plain, $plz) && ! preg_match('/Handelsregister|HRB/i', $plain)) {
                $city = $plz[1].' '.trim(preg_replace('/\s+(Handelsregister|Registergericht|Vertreten).*/iu', '', $plz[2]) ?? $plz[2]);
            }
        }

        if ($street || $city) {
            $out['address'] = trim(($street ? $street."\n" : '').($city ?: ''));
        }
        if ($out['company']) {
            $out['name'] = $out['company'];
        }

        return $out;
    }

    private function extractVatId(string $text): ?string
    {
        if (preg_match('/\b(DE)\s*(\d(?:[\s.]*\d){8})\b/u', $text, $m)) {
            return 'DE'.preg_replace('/\D/', '', $m[2]);
        }
        if (preg_match('/Umsatzsteuer(?:\-|\s*)(?:ID|Identifikationsnummer)[^A-Z]{0,80}\b([A-Z]{2}\s*\d{2}(?:[\s.]*\d){7,})\b/iu', $text, $m)) {
            return strtoupper(preg_replace('/\s+/', '', $m[1]) ?? $m[1]);
        }

        return null;
    }

    /** @param string[] $emails */
    private function preferEmail(array $emails): ?string
    {
        $clean = [];
        foreach ($emails as $email) {
            $email = strtolower(trim($email, " \t.;,<>"));
            if ($email === '' || str_contains($email, 'example.') || str_contains($email, 'wixpress') || str_contains($email, 'wordpress.com')) {
                continue;
            }
            $clean[] = $email;
        }
        if ($clean === []) {
            return null;
        }
        foreach ($clean as $email) {
            if (preg_match('/^(info|kontakt|office|mail|hello|team)@/', $email)) {
                return $email;
            }
        }

        return $clean[0];
    }

    private function extractWithAi(string $text): ?array
    {
        $key = config('wwc.ai_api_key');
        if (! $key || strlen($text) < 40) {
            return null;
        }

        try {
            $prompt = "Extrahiere aus folgendem deutschen Impressum-Text strukturierte Kundendaten als reines JSON "
                ."mit Keys name, company, email, address, vat_id. Fehlende Felder als null. "
                ."vat_id ohne Leerzeichen (z.B. DE268871416). Adresse mit Zeilenumbruch zwischen Straße und PLZ/Ort.\n\n"
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
            $heuristicVal = $heuristic[$key] ?? null;
            $aiVal = is_array($ai) ? ($ai[$key] ?? null) : null;
            $out[$key] = (is_string($heuristicVal) && trim($heuristicVal) !== '')
                ? trim($heuristicVal)
                : (is_string($aiVal) && trim($aiVal) !== '' ? trim($aiVal) : $out[$key]);
        }
        if (is_string($out['vat_id'])) {
            $out['vat_id'] = strtoupper(preg_replace('/\s+/', '', $out['vat_id']) ?: $out['vat_id']);
        }
        if (! $out['name']) {
            $out['name'] = $out['company'] ?: (parse_url($base, PHP_URL_HOST) ?: 'Neuer Kunde');
        }

        return $out;
    }
}
