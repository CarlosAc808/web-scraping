<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Scraping\HtmlProductExtractor;
use App\Services\Scraping\MarketplaceApiScraper;
use App\Services\Scraping\ScrapeException;
use App\Services\Scraping\ScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScrapingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_a_bearer_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alex Mendoza',
            'email' => 'alex@example.com',
            'password' => 'MaxunPass!1',
            'password_confirmation' => 'MaxunPass!1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'alex@example.com')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);
        $this->assertDatabaseHas('users', ['email' => 'alex@example.com']);
    }

    public function test_user_can_log_in_and_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'MaxunPass!1']);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'MaxunPass!1',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'incorrecta',
        ])->assertUnprocessable()->assertJsonPath('message', 'El correo o la contraseña no son correctos.');
    }

    public function test_creating_a_robot_runs_scraping_and_persists_real_extracted_fields(): void
    {
        $this->actingAs(User::factory()->create());
        $scraper = new class(new HtmlProductExtractor, new MarketplaceApiScraper) extends ScraperService
        {
            public function scrape(string $url): array
            {
                return [
                    'title' => 'Catálogo',
                    'description' => null,
                    'image' => null,
                    'products' => [[
                        'name' => 'Mochila urbana',
                        'price' => '89.50',
                        'previous_price' => null,
                        'currency' => 'MXN',
                        'description' => 'Mochila resistente',
                        'stock' => 'En stock',
                        'image' => 'https://cdn.example.com/bag.jpg',
                        'url' => 'https://shop.example.com/products/bag',
                    ]],
                ];
            }
        };
        $this->app->instance(ScraperService::class, $scraper);

        $response = $this->postJson('/api/robots', [
            'name' => 'Catálogo de tienda',
            'url' => 'https://shop.example.com/catalog',
            'interval_minutes' => 10,
            'output_format' => 'json',
            'instructions' => null,
            'monitor_enabled' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.execution.status_code', 'success')
            ->assertJsonPath('data.execution.interval_minutes', 10)
            ->assertJsonPath('data.execution.products.0.name', 'Mochila urbana')
            ->assertJsonPath('data.execution.products.0.price', '89.50')
            ->assertJsonPath('data.execution.products.0.image', 'https://cdn.example.com/bag.jpg');
        $this->assertDatabaseHas('robots', ['name' => 'Catálogo de tienda', 'interval_minutes' => 10]);
        $this->assertDatabaseHas('executions', ['status' => 'success']);

        $this->getJson('/api/executions?search=Catálogo&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.products.0.image', 'https://cdn.example.com/bag.jpg');
    }

    public function test_rerunning_a_robot_saves_refreshed_price_and_availability(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $robot = $user->robots()->create([
            'name' => 'Reloj Liverpool',
            'url' => 'https://www.liverpool.com.mx/tienda/pdp/reloj/1182415177',
            'output_format' => 'json',
        ]);
        $robot->executions()->create([
            'status' => 'success',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
            'products' => [[
                'name' => 'Reloj',
                'price' => null,
                'previous_price' => null,
                'currency' => null,
                'description' => null,
                'stock' => null,
                'image' => null,
                'url' => $robot->url,
            ]],
        ]);
        $scraper = new class(new HtmlProductExtractor, new MarketplaceApiScraper) extends ScraperService
        {
            public function scrape(string $url): array
            {
                return [
                    'title' => 'Reloj Liverpool',
                    'description' => null,
                    'image' => null,
                    'products' => [[
                        'name' => 'Reloj',
                        'price' => '$2,789.00',
                        'previous_price' => null,
                        'currency' => null,
                        'description' => null,
                        'stock' => 'Compra en línea habilitada',
                        'image' => null,
                        'url' => $url,
                    ]],
                ];
            }
        };
        $this->app->instance(ScraperService::class, $scraper);

        $this->postJson("/api/robots/{$robot->id}/executions")
            ->assertCreated()
            ->assertJsonPath('data.products.0.price', '$2,789.00')
            ->assertJsonPath('data.products.0.stock', 'Compra en línea habilitada');

        $this->assertDatabaseCount('executions', 2);
    }

    public function test_robot_creation_rejects_non_https_urls(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/robots', [
                'name' => 'Catálogo de tienda',
                'url' => 'http://shop.example.com',
                'output_format' => 'json',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    public function test_failed_scrapes_are_saved_with_an_explanation(): void
    {
        $this->actingAs(User::factory()->create());
        $scraper = new class(new HtmlProductExtractor, new MarketplaceApiScraper) extends ScraperService
        {
            public function scrape(string $url): array
            {
                throw new ScrapeException('El sitio no publicó productos.');
            }
        };
        $this->app->instance(ScraperService::class, $scraper);

        $this->postJson('/api/robots', [
            'name' => 'Robot de prueba',
            'url' => 'https://shop.example.com/products',
            'output_format' => 'json',
        ])->assertCreated()
            ->assertJsonPath('data.execution.status_code', 'failed')
            ->assertJsonPath('data.execution.error', 'El sitio no publicó productos.');

        $this->assertDatabaseHas('executions', [
            'status' => 'failed',
            'error_message' => 'El sitio no publicó productos.',
        ]);
    }

    public function test_executions_require_authentication(): void
    {
        $this->getJson('/api/executions')->assertUnauthorized();
    }

    public function test_user_can_update_robot_interval_and_monitor_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $robot = $user->robots()->create([
            'name' => 'Monitor Test',
            'url' => 'https://shop.example.com/item',
            'interval_minutes' => 15,
            'output_format' => 'json',
            'monitor_enabled' => true,
        ]);

        $this->patchJson("/api/robots/{$robot->id}", [
            'interval_minutes' => 5,
            'monitor_enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.interval_minutes', 5)
            ->assertJsonPath('data.monitor_enabled', false);

        $this->assertDatabaseHas('robots', [
            'id' => $robot->id,
            'interval_minutes' => 5,
            'monitor_enabled' => false,
        ]);
    }

    public function test_executions_can_be_filtered_by_robot_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $robot1 = $user->robots()->create([
            'name' => 'Robot 1',
            'url' => 'https://shop1.example.com',
            'output_format' => 'json',
        ]);
        $robot2 = $user->robots()->create([
            'name' => 'Robot 2',
            'url' => 'https://shop2.example.com',
            'output_format' => 'json',
        ]);

        $robot1->executions()->create(['status' => 'success', 'started_at' => now()]);
        $robot2->executions()->create(['status' => 'success', 'started_at' => now()]);

        $this->getJson("/api/executions?robot_id={$robot1->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.robot_id', $robot1->id);
    }

    public function test_price_history_endpoint_returns_chronological_points_and_trends(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $robot = $user->robots()->create([
            'name' => 'Monitor Laptop',
            'url' => 'https://shop.example.com/laptop',
            'output_format' => 'json',
        ]);

        $robot->executions()->create([
            'status' => 'success',
            'started_at' => now()->subHours(2),
            'products' => [[
                'name' => 'Laptop Gamer',
                'price' => '$1,500.00',
                'currency' => 'USD',
                'url' => 'https://shop.example.com/laptop',
            ]],
        ]);

        $robot->executions()->create([
            'status' => 'success',
            'started_at' => now()->subHour(),
            'products' => [[
                'name' => 'Laptop Gamer',
                'price' => '$1,350.00',
                'currency' => 'USD',
                'url' => 'https://shop.example.com/laptop',
            ]],
        ]);

        $response = $this->getJson('/api/price-history');
        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Laptop Gamer')
            ->assertJsonPath('data.0.initial_price', 1500)
            ->assertJsonPath('data.0.current_price', 1350)
            ->assertJsonPath('data.0.trend', 'down')
            ->assertJsonPath('data.0.price_diff', -150)
            ->assertJsonPath('data.0.price_diff_percent', -10);
    }

    public function test_executions_can_be_filtered_to_latest_only_per_robot(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $robot1 = $user->robots()->create([
            'name' => 'Robot 1',
            'url' => 'https://shop1.example.com',
            'output_format' => 'json',
        ]);

        $robot1->executions()->create(['status' => 'success', 'started_at' => now()->subMinute()]);
        $second = $robot1->executions()->create(['status' => 'success', 'started_at' => now()]);

        $this->getJson('/api/executions?latest_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $second->id);
    }
}
