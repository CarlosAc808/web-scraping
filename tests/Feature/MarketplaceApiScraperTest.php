<?php

namespace Tests\Feature;

use App\Services\Scraping\MarketplaceApiScraper;
use App\Services\Scraping\ScrapeException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceApiScraperTest extends TestCase
{
    public function test_amazon_product_urls_use_the_official_creators_api(): void
    {
        config([
            'services.amazon_creators.client_id' => 'client-id',
            'services.amazon_creators.client_secret' => 'client-secret',
            'services.amazon_creators.partner_tag' => 'partner-20',
        ]);
        Cache::flush();
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response([
                'access_token' => 'local-test-token',
                'expires_in' => 3600,
            ]),
            'https://creatorsapi.amazon/catalog/v1/getItems' => Http::response([
                'itemsResult' => ['items' => [[
                    'asin' => 'B012345678',
                    'detailPageURL' => 'https://www.amazon.com.mx/dp/B012345678',
                    'itemInfo' => [
                        'title' => ['displayValue' => 'Audífonos de prueba'],
                        'features' => [['displayValue' => 'Cancelación de ruido']],
                    ],
                    'images' => ['primary' => ['large' => ['url' => 'https://images.example.com/headphones.jpg']]],
                    'offersV2' => ['listings' => [[
                        'isBuyBoxWinner' => true,
                        'availability' => ['type' => 'IN_STOCK', 'maxOrderQuantity' => 4],
                        'price' => [
                            'money' => ['amount' => 2499, 'currency' => 'MXN', 'displayAmount' => '$2,499.00'],
                            'savingBasis' => ['money' => ['displayAmount' => '$2,999.00']],
                        ],
                    ]]],
                ]]],
            ]),
        ]);

        $result = (new MarketplaceApiScraper)->scrape('https://www.amazon.com.mx/dp/B012345678');

        $this->assertSame('Audífonos de prueba', $result['products'][0]['name']);
        $this->assertSame('$2,499.00', $result['products'][0]['price']);
        $this->assertSame('$2,999.00', $result['products'][0]['previous_price']);
        $this->assertSame('En stock (hasta 4)', $result['products'][0]['stock']);
        $this->assertSame('https://images.example.com/headphones.jpg', $result['products'][0]['image']);
        Http::assertSent(fn ($request) => $request->url() === 'https://creatorsapi.amazon/catalog/v1/getItems'
            && $request->hasHeader('x-marketplace', 'www.amazon.com.mx')
            && $request['partnerTag'] === 'partner-20'
            && $request['itemIds'] === ['B012345678']);
    }

    public function test_mercado_libre_catalog_urls_use_oauth_and_return_product_data(): void
    {
        config(['services.mercadolibre.access_token' => 'local-test-token']);
        Http::fake([
            'https://api.mercadolibre.com/products/MLM24473062' => Http::response([
                'id' => 'MLM24473062',
                'name' => 'Control Xbox Gamesir',
                'permalink' => 'https://www.mercadolibre.com.mx/control-gamesir/p/MLM24473062',
                'buy_box_winner' => ['item_id' => 'MLM123456789'],
            ]),
            'https://api.mercadolibre.com/items/MLM123456789' => Http::response([
                'id' => 'MLM123456789',
                'title' => 'Control Xbox Gamesir',
                'price' => 1299,
                'original_price' => 1599,
                'currency_id' => 'MXN',
                'available_quantity' => 8,
                'status' => 'active',
                'thumbnail' => 'https://http2.mlstatic.com/control.jpg',
                'permalink' => 'https://www.mercadolibre.com.mx/control-gamesir/p/MLM24473062',
            ]),
            'https://api.mercadolibre.com/items/MLM123456789/description' => Http::response([
                'plain_text' => 'Control con cable para Xbox.',
            ]),
        ]);

        $result = (new MarketplaceApiScraper)->scrape(
            'https://www.mercadolibre.com.mx/control-gamesir/p/MLM24473062',
        );

        $this->assertSame('Control Xbox Gamesir', $result['products'][0]['name']);
        $this->assertSame('1,299.00 MXN', $result['products'][0]['price']);
        $this->assertSame('1,599.00 MXN', $result['products'][0]['previous_price']);
        $this->assertSame('En stock (8)', $result['products'][0]['stock']);
        $this->assertSame('Control con cable para Xbox.', $result['products'][0]['description']);
        $this->assertSame('https://http2.mlstatic.com/control.jpg', $result['products'][0]['image']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadolibre.com/products/MLM24473062'
            && $request->hasHeader('Authorization', 'Bearer local-test-token'));
    }

    public function test_marketplace_api_adapters_require_local_credentials(): void
    {
        config([
            'services.amazon_creators.client_id' => null,
            'services.amazon_creators.client_secret' => null,
            'services.amazon_creators.partner_tag' => null,
            'services.mercadolibre.access_token' => null,
        ]);
        Http::preventStrayRequests();

        try {
            (new MarketplaceApiScraper)->scrape('https://www.amazon.com.mx/dp/B012345678');
            $this->fail('Amazon API credentials should be required.');
        } catch (ScrapeException $exception) {
            $this->assertStringContainsString('Creators API', $exception->getMessage());
        }

        try {
            (new MarketplaceApiScraper)->scrape('https://www.mercadolibre.com.mx/item/p/MLM24473062');
            $this->fail('Mercado Libre OAuth token should be required.');
        } catch (ScrapeException $exception) {
            $this->assertStringContainsString('token OAuth', $exception->getMessage());
        }
    }
}
