<?php

namespace App\Services\Scraping;

class ScraperService
{
    public function __construct(
        private readonly HtmlProductExtractor $extractor,
        private readonly MarketplaceApiScraper $marketplaceApi,
    ) {}

    /**
     * @return array{title: ?string, description: ?string, image: ?string, products: list<array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}>}
     */
    public function scrape(string $url): array
    {
        $currentUrl = $url;
        $visited = [];
        $deadline = microtime(true) + 25;
        $marketplaceApiError = null;
        $attemptAlternative = false;
        for ($redirect = 0; ; $redirect++) {
            try {
                $apiResult = $this->marketplaceApi->scrape($currentUrl);
            } catch (ScrapeException $exception) {
                $marketplaceApiError ??= $exception->getMessage();
                $apiResult = null;
            }
            if ($apiResult !== null) {
                return $apiResult;
            }

            $key = strtolower($currentUrl);
            if (isset($visited[$key])) {
                throw new ScrapeException('El sitio entró en un ciclo de redirecciones.');
            }
            $visited[$key] = true;

            $remainingSeconds = (int) ceil($deadline - microtime(true));
            if ($remainingSeconds < 1) {
                throw new ScrapeException('El sitio tardó demasiado en responder.');
            }

            [$host, $ip] = $this->resolvePublicHost($currentUrl);
            $ua = $this->resolveUserAgent($currentUrl, $attemptAlternative);
            $response = $this->requestPage($currentUrl, $host, $ip, $remainingSeconds, $ua);
            $status = $response['status'];

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response['location'] ?? null;
                if ($location !== null && ! $attemptAlternative && $this->isVerificationLocation($location)) {
                    $attemptAlternative = true;
                    unset($visited[$key]);
                    continue;
                }
                if ($redirect >= 5) {
                    throw new ScrapeException('El sitio realizó demasiadas redirecciones.');
                }
                if ($location === null) {
                    throw new ScrapeException('El sitio respondió con una redirección sin indicar la URL de destino.');
                }
                $currentUrl = $this->resolveRedirectUrl($location, $currentUrl);

                continue;
            }

            $html = $response['html'];
            $contentType = $response['content_type'];
            $curlError = $response['curl_error'];

            if (! $attemptAlternative && is_string($html) && ($status === 403 || $status === 429 || $this->isVerificationPage($currentUrl, $html))) {
                $attemptAlternative = true;
                unset($visited[$key]);
                continue;
            }

            break;
        }

        try {
            if (! is_string($html)) {
                throw new ScrapeException($curlError !== '' ? 'No se pudo leer el sitio: '.$curlError : 'No se pudo descargar el sitio.');
            }
            if ($status === 403 || $status === 429) {
                throw new ScrapeException($this->blockedSiteMessage($currentUrl, $status));
            }
            if ($this->isVerificationPage($currentUrl, $html)) {
                throw new ScrapeException($this->blockedSiteMessage($currentUrl, $status));
            }
            if ($status === 202 && preg_match('/(?:^|\.)(?:amazon\.[a-z.]+)$/i', (string) parse_url($currentUrl, PHP_URL_HOST)) === 1) {
                throw new ScrapeException($this->blockedSiteMessage($currentUrl, $status));
            }
            if ($status < 200 || $status >= 300) {
                throw new ScrapeException("El sitio respondió con el estado HTTP {$status}.");
            }
            if (! str_contains(strtolower($contentType), 'html') && ! str_contains(strtolower($contentType), 'xhtml')) {
                throw new ScrapeException('La URL no devolvió una página HTML que se pueda analizar.');
            }

            $result = $this->extractor->extract($html, $currentUrl);
            if ($result['products'] === []) {
                throw new ScrapeException('La página respondió, pero no se encontraron datos de producto. Prueba con la URL de una ficha o catálogo que publique metadatos de producto.');
            }

            return $result;
        } catch (ScrapeException $exception) {
            if ($marketplaceApiError === null) {
                throw $exception;
            }

            throw new ScrapeException(
                $exception->getMessage().' La API oficial tampoco pudo consultarse: '.$marketplaceApiError,
                previous: $exception,
            );
        }
    }

    private function resolveUserAgent(string $url, bool $alternative = false): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $crawlerUa = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $desktopUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

        $prefersCrawler = str_contains($host, 'mercadolibre.') || str_contains($host, 'mercadopago.');

        if ($alternative) {
            return $prefersCrawler ? $desktopUa : $crawlerUa;
        }

        return $prefersCrawler ? $crawlerUa : $desktopUa;
    }

    private function isVerificationLocation(string $location): bool
    {
        return preg_match('~/(?:captcha|challenge|account-verification|verify|validatecaptcha)(?:/|\?|$)~i', $location) === 1;
    }

    private function isVerificationPage(string $url, string $html): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('~/(?:captcha|challenge|account-verification|verify|validatecaptcha)(?:/|$)~', $path) === 1) {
            return true;
        }

        return preg_match('/(?:suspicious-traffic-frontend|gz-account-verification|validatecaptcha|robot check|automated access detected)/i', $html) === 1;
    }

    private function blockedSiteMessage(string $url, int $status): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'mercadolibre.')) {
            return 'Mercado Libre bloqueó esta consulta automática o pidió verificar la cuenta. No se puede extraer desde esta respuesta; usa una integración oficial autorizada o prueba más tarde.';
        }
        if (str_contains($host, 'amazon.')) {
            return 'Amazon bloqueó o no autorizó la consulta automática. Para obtener datos de producto de forma estable se requiere acceso autorizado a Creators API.';
        }
        if (str_contains($host, 'telcel.')) {
            return "Telcel rechazó la solicitud automática (HTTP {$status}). La tienda debe permitir el acceso o proporcionar una API autorizada.";
        }

        return "La tienda rechazó la solicitud automática o pidió verificación (HTTP {$status}). Puede requerir una sesión, permisos o una API autorizada.";
    }

    /**
     * @return array{html: string|false, status: int, content_type: string, curl_error: string, location: ?string}
     */
    private function requestPage(string $url, string $host, string $ip, int $timeoutSeconds, ?string $userAgent = null): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ScrapeException('No se pudo iniciar la conexión con el sitio.');
        }

        $userAgent ??= $this->resolveUserAgent($url);
        $location = null;
        $resolveAddress = str_contains($ip, ':') ? "[$ip]" : $ip;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(7, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_MAXFILESIZE => 5_000_000,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: es-MX,es;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                if (preg_match('/^Location:\s*(.+?)\s*$/i', $header, $matches) === 1) {
                    $location = trim($matches[1]);
                }

                return strlen($header);
            },
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => ["{$host}:443:{$resolveAddress}"],
            CURLOPT_PROXY => '',
        ]);

        try {
            $html = curl_exec($curl);

            return [
                'html' => $html,
                'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'content_type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
                'curl_error' => curl_error($curl),
                'location' => $location,
            ];
        } finally {
            curl_close($curl);
        }
    }

    private function resolveRedirectUrl(string $location, string $baseUrl): string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
            throw new ScrapeException('El sitio respondió con una URL de redirección inválida.');
        }

        $location = preg_replace('/#.*$/', '', $location) ?? $location;
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location) === 1 && preg_match('~^https://~i', $location) !== 1) {
            throw new ScrapeException('El sitio intentó redirigir a una dirección que no usa HTTPS.');
        }
        if (preg_match('~^https://~i', $location) === 1) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        $base = parse_url($baseUrl);
        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            throw new ScrapeException('No se pudo interpretar la URL de redirección del sitio.');
        }

        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($location, '?')) {
            return $origin.($base['path'] ?? '/').$location;
        }

        $parts = parse_url($location);
        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = $base['path'] ?? '/';
        } elseif (! str_starts_with($path, '/')) {
            $path = rtrim(dirname($base['path'] ?? '/'), '/').'/'.$path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        $resolved = $origin.'/'.implode('/', $segments);
        if (array_key_exists('query', $parts)) {
            $resolved .= '?'.$parts['query'];
        } elseif ($path === ($base['path'] ?? '/')) {
            $resolved .= isset($base['query']) ? '?'.$base['query'] : '';
        }

        return $resolved;
    }

    /**
     * @return array{string, string}
     */
    private function resolvePublicHost(string $url): array
    {
        $parts = parse_url($url);
        if (
            ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new ScrapeException('Usa una URL HTTPS pública, sin credenciales y en el puerto 443.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new ScrapeException('Solo se permiten sitios públicos.');
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $addresses[] = $record['ip'];
                    } elseif (isset($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            throw new ScrapeException('No se pudo resolver el dominio. Comprueba la URL y vuelve a intentarlo.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ScrapeException('Solo se permiten sitios públicos.');
            }
        }

        return [$host, $addresses[0]];
    }
}
