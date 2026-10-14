<?php

namespace App\Services\Scraping;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MarketplaceApiScraper
{
    /**
     * @return array{title: ?string, description: ?string, image: ?string, products: list<array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}>}|null
     */
    public function scrape(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('/(?:^|\.)amazon\.[a-z.]+$/', $host) === 1) {
            $asin = $this->amazonAsin($path);
            if ($asin !== null) {
                $marketplace = preg_match('/^amazon\./', $host) === 1 ? 'www.'.$host : $host;

                return $this->scrapeAmazon($asin, $marketplace, $url);
            }
        }

        if (preg_match('/(?:^|\.)mercadolibre\.[a-z.]+$/', $host) === 1) {
            $productId = $this->mercadoLibreProductId($path);
            if ($productId !== null) {
                return $this->scrapeMercadoLibre($productId, str_contains(strtolower($path), '/p/'), $url);
            }
        }

        return null;
    }

    private function amazonAsin(string $path): ?string
    {
        if (preg_match('~/(?:dp|gp/(?:product|aw/d|aw/dp))/([A-Z0-9]{10})(?:[/?]|$)~i', $path, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function mercadoLibreProductId(string $path): ?string
    {
        if (preg_match('~(?:^|/p/|/|-)(MLM\d+)(?:[/?-]|$)~i', $path, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    /**
     * @return array{title: ?string, description: ?string, image: ?string, products: list<array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}>}
     */
    private function scrapeAmazon(string $asin, string $marketplace, string $sourceUrl): array
    {
        $clientId = (string) config('services.amazon_creators.client_id');
        $clientSecret = (string) config('services.amazon_creators.client_secret');
        $partnerTag = (string) config('services.amazon_creators.partner_tag');
        if ($clientId === '' || $clientSecret === '' || $partnerTag === '') {
            throw new ScrapeException('Para consultar productos de Amazon configura localmente las credenciales de Creators API y el Partner Tag. No se intentará eludir el bloqueo de la tienda.');
        }

        $token = $this->amazonAccessToken($clientId, $clientSecret);
        $response = $this->send(
            fn () => Http::acceptJson()
                ->withToken($token)
                ->withHeaders(['x-marketplace' => $marketplace])
                ->timeout(8)
                ->post('https://creatorsapi.amazon/catalog/v1/getItems', [
                    'itemIds' => [$asin],
                    'itemIdType' => 'ASIN',
                    'languagesOfPreference' => ['es_MX'],
                    'marketplace' => $marketplace,
                    'partnerTag' => $partnerTag,
                    'resources' => [
                        'images.primary.large',
                        'itemInfo.features',
                        'itemInfo.title',
                        'offersV2.listings.availability',
                        'offersV2.listings.price',
                    ],
                ]),
            'Amazon Creators API',
        );

        $this->assertApiResponse($response, 'Amazon Creators API');
        $item = data_get($response->json(), 'itemsResult.items.0')
            ?? data_get($response->json(), 'itemResults.items.0')
            ?? data_get($response->json(), 'itemsResult.items.0.item');
        if (! is_array($item)) {
            throw new ScrapeException('Amazon Creators API no devolvió datos accesibles para este ASIN. Verifica el marketplace, el Partner Tag y los permisos de la cuenta.');
        }

        $title = data_get($item, 'itemInfo.title.displayValue')
            ?? data_get($item, 'itemInfo.title');
        $features = data_get($item, 'itemInfo.features', []);
        $features = is_array($features) ? ($features['displayValues'] ?? $features) : [];
        $description = is_array($features)
            ? implode(' · ', array_filter(array_map(
                fn ($feature) => is_array($feature) ? ($feature['displayValue'] ?? null) : (is_string($feature) ? $feature : null),
                $features,
            )))
            : null;
        $listing = $this->preferredListing(data_get($item, 'offersV2.listings', []));
        $money = data_get($listing, 'price.money', []);
        $availability = data_get($listing, 'availability', []);
        $price = is_array($money) ? ($money['displayAmount'] ?? $this->formatMoney($money['amount'] ?? null, $money['currency'] ?? null)) : null;
        $image = data_get($item, 'images.primary.large.url');
        $stock = $this->amazonAvailability(is_array($availability) ? $availability : []);
        $productUrl = $item['detailPageURL'] ?? $sourceUrl;

        return [
            'title' => is_string($title) ? $title : null,
            'description' => $description !== '' ? $description : null,
            'image' => is_string($image) ? $image : null,
            'products' => [[
                'name' => is_string($title) && $title !== '' ? $title : $asin,
                'price' => is_string($price) ? $price : null,
                'previous_price' => data_get($listing, 'price.savingBasis.money.displayAmount'),
                'currency' => is_array($money) ? ($money['currency'] ?? null) : null,
                'description' => $description !== '' ? $description : null,
                'stock' => $stock,
                'image' => is_string($image) ? $image : null,
                'url' => is_string($productUrl) ? $productUrl : $sourceUrl,
            ]],
        ];
    }

    private function amazonAccessToken(string $clientId, string $clientSecret): string
    {
        $cacheKey = 'amazon-creators-token-'.hash('sha256', $clientId);
        $token = Cache::get($cacheKey);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $response = $this->send(
            fn () => Http::asForm()
                ->acceptJson()
                ->timeout(8)
                ->post('https://api.amazon.com/auth/o2/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'creatorsapi::default',
                ]),
            'Amazon Login with Amazon',
        );
        $this->assertApiResponse($response, 'Amazon Login with Amazon');
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new ScrapeException('Amazon Login with Amazon no devolvió un token de acceso válido.');
        }

        $expiresIn = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put($cacheKey, $token, $expiresIn);

        return $token;
    }

    /**
     * @return array{title: ?string, description: ?string, image: ?string, products: list<array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}>}
     */
    private function scrapeMercadoLibre(string $productId, bool $catalogProduct, string $sourceUrl): array
    {
        $token = (string) config('services.mercadolibre.access_token');
        if ($token === '') {
            throw new ScrapeException('Para consultar Mercado Libre configura localmente un token OAuth de su API. La página enviada redirige a una verificación anti-automatización.');
        }

        $resource = $catalogProduct ? 'products' : 'items';
        $response = $this->send(
            fn () => Http::acceptJson()
                ->withToken($token)
                ->timeout(8)
                ->get('https://api.mercadolibre.com/'.$resource.'/'.rawurlencode($productId)),
            'API de Mercado Libre',
        );
        $this->assertApiResponse($response, 'API de Mercado Libre');
        $data = $response->json();
        if (! is_array($data)) {
            throw new ScrapeException('La API de Mercado Libre devolvió una respuesta no válida.');
        }

        $winner = $catalogProduct ? ($data['buy_box_winner'] ?? []) : $data;
        $itemId = is_array($winner) && is_string($winner['item_id'] ?? null)
            ? $winner['item_id']
            : (is_string($data['id'] ?? null) && ! $catalogProduct ? $data['id'] : null);
        $item = $winner;
        if ($catalogProduct && $itemId !== null) {
            $itemResponse = $this->send(
                fn () => Http::acceptJson()
                    ->withToken($token)
                    ->timeout(8)
                    ->get('https://api.mercadolibre.com/items/'.rawurlencode($itemId)),
                'API de Mercado Libre',
            );
            if ($itemResponse->successful() && is_array($itemResponse->json())) {
                $item = $itemResponse->json();
            }
        }
        if (! is_array($item)) {
            $item = [];
        }

        $title = $item['title'] ?? $data['name'] ?? $data['title'] ?? null;
        $pictures = $item['pictures'] ?? $data['pictures'] ?? [];
        $image = $item['thumbnail'] ?? ($pictures[0]['secure_url'] ?? $pictures[0]['url'] ?? null);
        $price = $item['price'] ?? $winner['price'] ?? null;
        $currency = $item['currency_id'] ?? $winner['currency_id'] ?? null;
        $quantity = $item['available_quantity'] ?? $winner['available_quantity'] ?? null;
        $status = strtolower((string) ($item['status'] ?? $winner['status'] ?? ''));
        $stock = is_numeric($quantity)
            ? ((int) $quantity > 0 && $status !== 'paused' ? 'En stock ('.(int) $quantity.')' : 'Agotado')
            : ($status === 'active' ? 'Publicación activa; inventario no informado' : null);
        $description = $item['short_description']['content'] ?? $data['short_description']['content'] ?? null;
        if ((! is_string($description) || $description === '') && $itemId !== null) {
            $descriptionResponse = $this->send(
                fn () => Http::acceptJson()
                    ->withToken($token)
                    ->timeout(8)
                    ->get('https://api.mercadolibre.com/items/'.rawurlencode($itemId).'/description'),
                'API de Mercado Libre',
            );
            if ($descriptionResponse->successful()) {
                $description = $descriptionResponse->json('plain_text');
            }
        }
        $url = $item['permalink'] ?? $data['permalink'] ?? $sourceUrl;

        return [
            'title' => is_string($title) ? $title : null,
            'description' => is_string($description) ? $description : null,
            'image' => is_string($image) ? $image : null,
            'products' => [[
                'name' => is_string($title) && $title !== '' ? $title : $productId,
                'price' => $this->formatMoney($price, $currency),
                'previous_price' => $this->formatMoney($item['original_price'] ?? $winner['original_price'] ?? null, $currency),
                'currency' => is_string($currency) ? $currency : null,
                'description' => is_string($description) ? $description : null,
                'stock' => $stock,
                'image' => is_string($image) ? $image : null,
                'url' => is_string($url) ? $url : $sourceUrl,
            ]],
        ];
    }

    private function assertApiResponse(Response $response, string $service): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new ScrapeException("{$service} rechazó las credenciales o no tiene permisos para este producto. Revisa la configuración y los permisos de la cuenta.");
        }
        if ($response->status() === 429) {
            throw new ScrapeException("{$service} alcanzó su límite de consultas. Inténtalo más tarde.");
        }

        throw new ScrapeException("{$service} respondió con HTTP {$response->status()}.");
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request, string $service): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new ScrapeException("No fue posible conectar con {$service}. Inténtalo de nuevo más tarde.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function preferredListing(mixed $listings): array
    {
        if (! is_array($listings)) {
            return [];
        }
        foreach ($listings as $listing) {
            if (is_array($listing) && ($listing['isBuyBoxWinner'] ?? false)) {
                return $listing;
            }
        }

        return is_array($listings[0] ?? null) ? $listings[0] : [];
    }

    /**
     * @param  array<string, mixed>  $availability
     */
    private function amazonAvailability(array $availability): ?string
    {
        $type = strtoupper((string) ($availability['type'] ?? ''));

        return match ($type) {
            'IN_STOCK' => isset($availability['maxOrderQuantity'])
                ? 'En stock (hasta '.(int) $availability['maxOrderQuantity'].')'
                : 'En stock',
            'IN_STOCK_SCARCE' => 'Pocas piezas disponibles',
            'OUT_OF_STOCK' => 'Agotado',
            'PREORDER' => 'Preventa',
            'AVAILABLE_DATE', 'LEADTIME' => 'Disponible bajo pedido',
            'UNAVAILABLE' => 'No disponible',
            default => null,
        };
    }

    private function formatMoney(mixed $amount, mixed $currency): ?string
    {
        if (is_string($amount) && $amount !== '') {
            return $amount;
        }
        if (! is_numeric($amount)) {
            return null;
        }

        return number_format((float) $amount, 2, '.', ',').(is_string($currency) && $currency !== '' ? ' '.$currency : '');
    }
}
