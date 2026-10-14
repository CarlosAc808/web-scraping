<?php

namespace Tests\Unit;

use App\Services\Scraping\HtmlProductExtractor;
use PHPUnit\Framework\TestCase;

class HtmlProductExtractorTest extends TestCase
{
    public function test_extracts_products_from_schema_json_ld_and_resolves_image_urls(): void
    {
        $html = <<<'HTML'
        <!doctype html>
        <html><head>
          <meta property="og:title" content="Tienda de ejemplo">
          <meta property="og:image" content="/assets/store.png">
          <script type="application/ld+json">
            {"@context":"https://schema.org","@graph":[
              {"@type":"Product","name":"Mochila urbana","image":["/images/bag.jpg"],
               "description":"Mochila resistente","offers":{"@type":"Offer","price":"89.50",
               "priceCurrency":"MXN","availability":"https://schema.org/InStock"},
               "url":"/productos/mochila"}
            ]}
          </script>
        </head><body><h1>Catálogo</h1></body></html>
        HTML;

        $result = (new HtmlProductExtractor)->extract($html, 'https://shop.example.com/catalog');

        $this->assertSame('Tienda de ejemplo', $result['title']);
        $this->assertSame('https://shop.example.com/assets/store.png', $result['image']);
        $this->assertCount(1, $result['products']);
        $this->assertSame('Mochila urbana', $result['products'][0]['name']);
        $this->assertSame('89.50', $result['products'][0]['price']);
        $this->assertSame('MXN', $result['products'][0]['currency']);
        $this->assertSame('En stock', $result['products'][0]['stock']);
        $this->assertSame('https://shop.example.com/images/bag.jpg', $result['products'][0]['image']);
        $this->assertSame('https://shop.example.com/productos/mochila', $result['products'][0]['url']);
    }

    public function test_uses_open_graph_metadata_for_a_product_page_without_json_ld(): void
    {
        $html = <<<'HTML'
        <html><head>
          <meta property="og:title" content="Cámara compacta">
          <meta property="og:description" content="Cámara ligera para viajes">
          <meta property="og:image" content="https://cdn.example.com/camera.jpg">
          <meta property="product:price:amount" content="149.00">
          <meta property="product:price:currency" content="USD">
        </head></html>
        HTML;

        $result = (new HtmlProductExtractor)->extract($html, 'https://shop.example.com/camera');

        $this->assertCount(1, $result['products']);
        $this->assertSame('Cámara compacta', $result['products'][0]['name']);
        $this->assertSame('149.00', $result['products'][0]['price']);
        $this->assertSame('USD', $result['products'][0]['currency']);
        $this->assertSame('Cámara ligera para viajes', $result['products'][0]['description']);
    }

    public function test_does_not_mislabel_a_regular_site_homepage_as_a_product(): void
    {
        $html = '<html><head><meta property="og:title" content="Mi tienda"><meta property="og:image" content="/logo.png"></head></html>';

        $result = (new HtmlProductExtractor)->extract($html, 'https://shop.example.com/');

        $this->assertSame('Mi tienda', $result['title']);
        $this->assertSame([], $result['products']);
    }

    public function test_extracts_name_price_discount_and_image_from_a_server_rendered_product_page(): void
    {
        $html = <<<'HTML'
        <html><head>
          <meta property="og:title" content="Reloj Hilfiger | Liverpool">
          <meta property="og:description" content="Reloj para hombre">
          <meta property="og:image" content="https://cdn.example.com/watch.jpg">
        </head><body>
          <h1>Reloj Hilfiger Bruce para hombre</h1>
          <div data-testid="1193351464-configurator-price">
            <span data-testid="discounted"><span>$<!-- -->2,804</span><span><span class="invisible">.</span>15</span></span>
            <span data-testid="original"><span class="line-through">$<!-- -->3,299</span><span class="line-through"><span class="invisible">.</span>00</span></span>
          </div>
        </body></html>
        HTML;

        $result = (new HtmlProductExtractor)->extract($html, 'https://www.liverpool.com.mx/tienda/pdp/reloj-hilfiger/1193351464');

        $this->assertCount(1, $result['products']);
        $this->assertSame('Reloj Hilfiger Bruce para hombre', $result['products'][0]['name']);
        $this->assertSame('$2,804.15', $result['products'][0]['price']);
        $this->assertSame('$3,299.00', $result['products'][0]['previous_price']);
        $this->assertSame('https://cdn.example.com/watch.jpg', $result['products'][0]['image']);
        $this->assertSame('https://www.liverpool.com.mx/tienda/pdp/reloj-hilfiger/1193351464', $result['products'][0]['url']);
    }

    public function test_extracts_liverpool_price_when_its_current_markup_has_no_discounted_price_marker(): void
    {
        $html = <<<'HTML'
        <html><head>
          <meta property="og:title" content="Reloj Pearson para hombre FS6137 | Liverpool">
          <meta property="og:description" content="Compra Reloj Pearson para hombre FS6137 en Liverpool.">
          <meta property="og:image" content="https://ss376.liverpool.com.mx/lg/1182415177.jpg">
        </head><body>
          <h1>Reloj Pearson para hombre FS6137</h1>
          <div data-testid="1182415177-configurator-price">
            <span><span>$<!-- -->2,789</span><span><span class="invisible">.</span>00</span></span>
          </div>
          <button data-testid="buy-now-button">Comprar ahora</button>
          <button data-testid="add-to-bag-button">Agregar a mi bolsa</button>
        </body></html>
        HTML;

        $result = (new HtmlProductExtractor)->extract(
            $html,
            'https://www.liverpool.com.mx/tienda/pdp/reloj-pearson-para-hombre-fs6137/1182415177',
        );

        $this->assertCount(1, $result['products']);
        $this->assertSame('Reloj Pearson para hombre FS6137', $result['products'][0]['name']);
        $this->assertSame('$2,789.00', $result['products'][0]['price']);
        $this->assertSame('Compra en línea habilitada', $result['products'][0]['stock']);
        $this->assertSame('https://ss376.liverpool.com.mx/lg/1182415177.jpg', $result['products'][0]['image']);
    }

    public function test_reports_online_purchase_as_unavailable_when_purchase_actions_are_disabled(): void
    {
        $html = <<<'HTML'
        <html><head><meta property="og:title" content="Producto sin disponibilidad"></head>
        <body><h1>Producto sin disponibilidad</h1>
          <div data-testid="product-configurator-price">$100.00</div>
          <button data-testid="buy-now-button" disabled>Comprar ahora</button>
          <button data-testid="add-to-bag-button" aria-disabled="true">Agregar a mi bolsa</button>
        </body></html>
        HTML;

        $result = (new HtmlProductExtractor)->extract($html, 'https://shop.example.com/tienda/pdp/producto/1');

        $this->assertSame('Compra en línea deshabilitada', $result['products'][0]['stock']);
    }

    public function test_extracts_public_product_details_from_amazon_html(): void
    {
        $html = <<<'HTML'
        <html><head>
          <meta property="og:title" content="Audífonos Bluetooth">
          <meta property="og:description" content="Audífonos con cancelación de ruido">
          <meta property="og:image" content="https://images.example.com/headphones.jpg">
        </head><body>
          <h1>Audífonos Bluetooth inalámbricos</h1>
          <div class="a-price"><span class="a-offscreen">$2,499.00</span></div>
          <div id="availability"><span>En stock</span></div>
        </body></html>
        HTML;

        $result = (new HtmlProductExtractor)->extract($html, 'https://www.amazon.com.mx/dp/B012345678');

        $this->assertCount(1, $result['products']);
        $this->assertSame('Audífonos Bluetooth inalámbricos', $result['products'][0]['name']);
        $this->assertSame('$2,499.00', $result['products'][0]['price']);
        $this->assertSame('En stock', $result['products'][0]['stock']);
        $this->assertSame('Audífonos con cancelación de ruido', $result['products'][0]['description']);
        $this->assertSame('https://images.example.com/headphones.jpg', $result['products'][0]['image']);
    }

    public function test_extracts_public_product_details_from_mercado_libre_html(): void
    {
        $html = <<<'HTML'
        <html><head>
          <meta property="og:title" content="Control Gamesir">
          <meta property="og:description" content="Control con cable compatible con Xbox">
          <meta property="og:image" content="https://images.example.com/controller.jpg">
        </head><body>
          <h1>Control Gamesir G7 SE</h1>
          <div class="ui-pdp-price__second-line">
            <span>$</span>
            <span class="andes-money-amount__fraction">1,299</span>
            <span class="andes-money-amount__cents">00</span>
          </div>
          <div class="ui-pdp-price__part--old">
            <span>$</span>
            <span class="andes-money-amount__fraction">1,599</span>
            <span class="andes-money-amount__cents">00</span>
          </div>
          <span class="ui-pdp-stock-information__title">Stock disponible</span>
        </body></html>
        HTML;

        $result = (new HtmlProductExtractor)->extract($html, 'https://www.mercadolibre.com.mx/control/p/MLM24473062');

        $this->assertCount(1, $result['products']);
        $this->assertSame('Control Gamesir G7 SE', $result['products'][0]['name']);
        $this->assertSame('$1,299.00', $result['products'][0]['price']);
        $this->assertSame('$1,599.00', $result['products'][0]['previous_price']);
        $this->assertSame('En stock', $result['products'][0]['stock']);
        $this->assertSame('Control con cable compatible con Xbox', $result['products'][0]['description']);
        $this->assertSame('https://images.example.com/controller.jpg', $result['products'][0]['image']);
    }
}
