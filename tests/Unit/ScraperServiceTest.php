<?php

namespace Tests\Unit;

use App\Services\Scraping\HtmlProductExtractor;
use App\Services\Scraping\MarketplaceApiScraper;
use App\Services\Scraping\ScrapeException;
use App\Services\Scraping\ScraperService;
use PHPUnit\Framework\TestCase;

class ScraperServiceTest extends TestCase
{
    public function test_local_network_urls_are_rejected_before_a_request_is_made(): void
    {
        $scraper = new ScraperService(new HtmlProductExtractor, new MarketplaceApiScraper);

        $this->expectException(ScrapeException::class);
        $this->expectExceptionMessage('Solo se permiten sitios públicos.');

        $scraper->scrape('https://127.0.0.1/');
    }

    public function test_non_https_urls_are_rejected(): void
    {
        $scraper = new ScraperService(new HtmlProductExtractor, new MarketplaceApiScraper);

        $this->expectException(ScrapeException::class);
        $this->expectExceptionMessage('Usa una URL HTTPS pública');

        $scraper->scrape('http://example.com/');
    }
}
