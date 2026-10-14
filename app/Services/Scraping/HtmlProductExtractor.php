<?php

namespace App\Services\Scraping;

use DOMDocument;
use DOMElement;
use DOMXPath;

class HtmlProductExtractor
{
    /**
     * Extract product data from common Schema.org JSON-LD and Open Graph metadata.
     *
     * @return array{title: ?string, description: ?string, image: ?string, products: list<array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}>}
     */
    public function extract(string $html, string $pageUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $metadata = $this->metadata($xpath);
        $title = $metadata['og:title'] ?? $metadata['twitter:title'] ?? $this->nodeText($xpath, '(//title)[1]') ?? $this->nodeText($xpath, '(//h1)[1]');
        $description = $metadata['og:description'] ?? $metadata['description'] ?? $metadata['twitter:description'] ?? null;
        $image = $this->absoluteUrl($metadata['og:image'] ?? $metadata['twitter:image'] ?? null, $pageUrl)
            ?? $this->extractDomImage($xpath, $pageUrl);
        $heading = $this->nodeText($xpath, '(//h1)[1]');

        $products = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $json = json_decode($script->textContent, true);
            if (is_array($json)) {
                $this->findProducts($json, $products, $pageUrl);
            }
        }

        $this->findMicrodataProducts($xpath, $products, $pageUrl);

        $domPrice = $this->extractDomPrice($xpath);
        $domStock = $this->extractDomStock($xpath)
            ?? $this->availabilityLabel($this->firstValue($metadata, ['product:availability', 'og:availability', 'availability']) ?? '');
        $metaPrice = $this->firstValue($metadata, ['product:price:amount', 'og:price:amount']);
        $isProductPage = str_contains(strtolower($metadata['og:type'] ?? ''), 'product')
            || $metaPrice !== null
            || $domPrice !== null
            || $domStock !== null
            || preg_match('~/(?:dp|gp/(?:product|aw/d|aw/dp)|pdp|product|products|producto|productos|p|up|item|articulo)/~i', (string) parse_url($pageUrl, PHP_URL_PATH)) === 1
            || preg_match('~(?:^|\.)(?:mercadolibre|amazon)\.[a-z.]+~i', (string) parse_url($pageUrl, PHP_URL_HOST)) === 1;

        $rawName = $heading ?? $title;
        $cleanName = $rawName !== null ? preg_replace('/\s*[-|:]\s*(?:\$\s*[\d,.]+|Mercado Libre|Amazon\.[a-z.]+).*$/iu', '', $rawName) : null;
        $productName = ($cleanName !== null && trim($cleanName) !== '') ? trim($cleanName) : $rawName;

        if ($products === [] && $isProductPage && $productName !== null) {
            $price = $domPrice['price'] ?? $metaPrice;
            $products[] = [
                'name' => $productName,
                'price' => $price,
                'previous_price' => $domPrice['previous_price'] ?? null,
                'currency' => $this->firstValue($metadata, ['product:price:currency', 'og:price:currency'])
                    ?? $this->guessCurrency($pageUrl, $price),
                'description' => $description,
                'stock' => $domStock,
                'image' => $image,
                'url' => $this->absoluteUrl($metadata['og:url'] ?? $pageUrl, $pageUrl),
            ];
        } elseif ($isProductPage && count($products) === 1) {
            $products[0]['price'] ??= $domPrice['price'] ?? null;
            $products[0]['previous_price'] ??= $domPrice['previous_price'] ?? null;
            $products[0]['stock'] ??= $domStock;
            $products[0]['image'] ??= $image;
            $products[0]['currency'] ??= $this->firstValue($metadata, ['product:price:currency', 'og:price:currency'])
                ?? $this->guessCurrency($pageUrl, $products[0]['price']);
            $products[0]['url'] ??= $pageUrl;
        }

        foreach ($products as &$p) {
            $p['image'] ??= $image;
            $p['currency'] ??= $this->guessCurrency($pageUrl, $p['price'] ?? null);
            $p['url'] ??= $pageUrl;
        }
        unset($p);

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'products' => array_values($products),
        ];
    }

    /**
     * @return array{price: ?string, previous_price: ?string}|null
     */
    private function extractDomPrice(DOMXPath $xpath): ?array
    {
        $container = $xpath->query('//*[@data-testid and contains(translate(@data-testid, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "configurator-price")]')?->item(0);
        $readPrice = function (string $query, ?\DOMNode $context = null) use ($xpath): ?string {
            $node = $xpath->query($query, $context)?->item(0);
            if ($node === null) {
                return null;
            }

            if ($node instanceof DOMElement && $node->hasAttribute('content')) {
                return trim($node->getAttribute('content')) ?: null;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

            return $text !== '' ? $text : null;
        };

        if ($container !== null) {
            $containerText = trim(preg_replace('/\s+/u', ' ', $container->textContent) ?? '');
            $current = $readPrice('.//*[@data-testid="discounted"]', $container)
                ?? $readPrice('.//*[@itemprop="price"]', $container)
                ?? ($containerText !== '' ? $containerText : null);
            $previous = $readPrice('.//*[@data-testid="original"]', $container);

            return $current !== null || $previous !== null
                ? ['price' => $current, 'previous_price' => $previous]
                : null;
        }

        $fraction = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ui-pdp-price__second-line ")]//*[contains(concat(" ", normalize-space(@class), " "), " andes-money-amount__fraction ")]')?->item(0)
            ?? $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " a-price ")]//*[contains(concat(" ", normalize-space(@class), " "), " a-offscreen ")]')?->item(0)
            ?? $xpath->query('//*[@id="priceblock_ourprice" or @id="priceblock_dealprice" or @id="price_inside_buybox" or @itemprop="price"]')?->item(0);
        $current = $fraction instanceof DOMElement && str_contains($fraction->getAttribute('class'), 'andes-money-amount__fraction')
            ? $this->mercadoLibrePrice($xpath, $fraction)
            : $readPrice('//*[@id="priceblock_ourprice" or @id="priceblock_dealprice" or @id="price_inside_buybox" or @itemprop="price"]')
                ?? $readPrice('//*[contains(concat(" ", normalize-space(@class), " "), " a-price ")]//*[contains(concat(" ", normalize-space(@class), " "), " a-offscreen ")]');
        $oldFraction = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ui-pdp-price__part--old ")]//*[contains(concat(" ", normalize-space(@class), " "), " andes-money-amount__fraction ")]')?->item(0);
        $previous = $oldFraction instanceof DOMElement
            ? $this->mercadoLibrePrice($xpath, $oldFraction)
            : $readPrice('//del//*[contains(concat(" ", normalize-space(@class), " "), " a-offscreen ")]')
            ?? $readPrice('//*[@data-a-strike="true"]');

        return $current !== null || $previous !== null
            ? ['price' => $current, 'previous_price' => $previous]
            : null;
    }

    private function mercadoLibrePrice(DOMXPath $xpath, DOMElement $fraction): ?string
    {
        $amount = trim($fraction->textContent);
        if ($amount === '') {
            return null;
        }
        $parent = $fraction->parentNode;
        if ($parent instanceof DOMElement) {
            $cents = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " andes-money-amount__cents ")]', $parent)?->item(0);
            if ($cents !== null && trim($cents->textContent) !== '') {
                $amount .= '.'.trim($cents->textContent);
            }
            $prefix = preg_match('/(?:\$|MXN)/u', $parent->textContent, $matches) === 1 ? $matches[0] : '';

            return $prefix.$amount;
        }

        return $amount;
    }

    private function extractDomStock(DOMXPath $xpath): ?string
    {
        $buttons = $xpath->query('//button[@data-testid="buy-now-button" or @data-testid="add-to-bag-button"]');
        if ($buttons !== false && $buttons->length > 0) {
            foreach ($buttons as $button) {
                if (! $button instanceof DOMElement) {
                    continue;
                }
                if (! $button->hasAttribute('disabled') && strtolower($button->getAttribute('aria-disabled')) !== 'true') {
                    return 'Compra en línea habilitada';
                }
            }

            return 'Compra en línea deshabilitada';
        }

        $availability = $this->nodeText($xpath, '//*[@id="availability"]')
            ?? $this->nodeText($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " ui-pdp-stock-information__title ")]');

        return $availability !== null ? $this->availabilityLabel($availability) : null;
    }

    /**
     * @return array<string, string>
     */
    private function metadata(DOMXPath $xpath): array
    {
        $metadata = [];
        foreach ($xpath->query('//meta[@content]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
            $value = trim($node->getAttribute('content'));
            if ($key !== '' && $value !== '') {
                $metadata[$key] ??= $value;
            }
        }

        return $metadata;
    }

    private function nodeText(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);
        $text = $node ? trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '') : '';

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}>  $products
     */
    private function findProducts(array $value, array &$products, string $pageUrl): void
    {
        $types = $value['@type'] ?? [];
        $types = is_array($types) ? $types : [$types];
        $isProduct = in_array('Product', $types, true) || in_array('ProductModel', $types, true);

        if ($isProduct) {
            $product = $this->normaliseProduct($value, $pageUrl);
            if ($product !== null) {
                $this->appendUnique($products, $product);
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested)) {
                $this->findProducts($nested, $products, $pageUrl);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}|null
     */
    private function normaliseProduct(array $value, string $pageUrl): ?array
    {
        $name = $this->scalar($value['name'] ?? null);
        if ($name === null) {
            return null;
        }

        $offers = $value['offers'] ?? [];
        if (array_is_list($offers) && is_array($offers)) {
            $offers = $offers[0] ?? [];
        }
        $price = is_array($offers) ? ($offers['price'] ?? $offers['lowPrice'] ?? null) : null;
        $currency = is_array($offers) ? $this->scalar($offers['priceCurrency'] ?? null) : null;
        $availability = is_array($offers) ? $this->scalar($offers['availability'] ?? null) : null;
        $image = $this->imageValue($value['image'] ?? null);
        $url = $this->scalar($value['url'] ?? (is_array($offers) ? ($offers['url'] ?? null) : null));

        return [
            'name' => $name,
            'price' => $this->scalar($price),
            'previous_price' => null,
            'currency' => $currency,
            'description' => $this->scalar($value['description'] ?? null),
            'stock' => $availability !== null ? $this->availabilityLabel($availability) : null,
            'image' => $this->absoluteUrl($image, $pageUrl),
            'url' => $this->absoluteUrl($url, $pageUrl),
        ];
    }

    /**
     * @param  list<array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}>  $products
     */
    private function findMicrodataProducts(DOMXPath $xpath, array &$products, string $pageUrl): void
    {
        foreach ($xpath->query('//*[@itemtype and contains(translate(@itemtype, "PRODUCT", "product"), "schema.org/product")]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $get = function (string $itemprop) use ($xpath, $node): ?string {
                $found = $xpath->query('.//*[@itemprop="'.$itemprop.'"]', $node)?->item(0);
                if (! $found instanceof DOMElement) {
                    return null;
                }

                return trim($found->getAttribute('content') ?: $found->getAttribute('href') ?: $found->getAttribute('src') ?: $found->textContent) ?: null;
            };
            $name = $get('name');
            if ($name === null) {
                continue;
            }

            $product = [
                'name' => $name,
                'price' => $get('price'),
                'previous_price' => null,
                'currency' => $get('priceCurrency'),
                'description' => $get('description'),
                'stock' => $get('availability') ? $this->availabilityLabel($get('availability')) : null,
                'image' => $this->absoluteUrl($get('image'), $pageUrl),
                'url' => $this->absoluteUrl($get('url'), $pageUrl),
            ];
            $this->appendUnique($products, $product);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function imageValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $first = $value[0] ?? $value;

            return is_array($first) ? $this->scalar($first['url'] ?? $first['contentUrl'] ?? null) : $this->scalar($first);
        }

        return null;
    }

    private function scalar(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }
        if (is_array($value) && isset($value['value'])) {
            return $this->scalar($value['value']);
        }

        return null;
    }

    private function availabilityLabel(string $availability): ?string
    {
        $value = strtolower(basename(parse_url($availability, PHP_URL_PATH) ?: $availability));
        if (preg_match('/pre.?order|pre.?sale/', $value) === 1) {
            return 'Preventa';
        }
        if (preg_match('/out.?of.?stock|sold.?out|discontinued|unavailable|no.?disponible|agotad/', $value) === 1) {
            return 'Agotado';
        }
        if (preg_match('/in.?stock|limitedavailability|onlineonly|available|stock.?disponible|disponible/', $value) === 1) {
            return 'En stock';
        }

        return $availability !== '' ? $availability : null;
    }

    private function absoluteUrl(?string $url, string $pageUrl): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null) {
            return in_array(strtolower($scheme), ['http', 'https'], true) ? $url : null;
        }

        $page = parse_url($pageUrl);
        if (! isset($page['scheme'], $page['host'])) {
            return null;
        }

        $origin = $page['scheme'].'://'.$page['host'].(isset($page['port']) ? ':'.$page['port'] : '');
        if (str_starts_with($url, '//')) {
            return $page['scheme'].':'.$url;
        }
        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }
        if (str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return $origin.($page['path'] ?? '/').$url;
        }

        return $origin.rtrim(dirname($page['path'] ?? '/'), '/').'/'.$url;
    }

    /**
     * @param  list<array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}>  $products
     * @param  array{name: string, price: ?string, previous_price: ?string, currency: ?string, description: ?string, stock: ?string, image: ?string, url: ?string}  $product
     */
    private function appendUnique(array &$products, array $product): void
    {
        foreach ($products as $existing) {
            if (strcasecmp($existing['name'], $product['name']) === 0 && $existing['url'] === $product['url']) {
                return;
            }
        }
        $products[] = $product;
    }

    private function firstValue(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($values[$key])) {
                return $values[$key];
            }
        }

        return null;
    }

    private function extractDomImage(DOMXPath $xpath, string $pageUrl): ?string
    {
        $selectors = [
            '//*[@id="landingImage"]',
            '//*[@id="imgBlkFront"]',
            '//*[@id="main-image"]',
            '//img[@data-a-dynamic-image]',
            '//img[contains(@class, "a-dynamic-image")]',
            '//img[contains(@class, "ui-pdp-image")]',
            '//img[@data-zoom]',
            '//*[@id="imageBlock"]//img',
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $hires = $node->getAttribute('data-old-hires');
                if ($hires !== '' && ! str_starts_with($hires, 'data:')) {
                    return $this->absoluteUrl($hires, $pageUrl);
                }
                if ($node->hasAttribute('data-a-dynamic-image')) {
                    $dynamic = json_decode($node->getAttribute('data-a-dynamic-image'), true);
                    if (is_array($dynamic) && $dynamic !== []) {
                        $firstUrl = array_key_first($dynamic);
                        if (is_string($firstUrl) && $firstUrl !== '') {
                            return $this->absoluteUrl($firstUrl, $pageUrl);
                        }
                    }
                }
                $src = $node->getAttribute('src') ?: $node->getAttribute('data-src');
                if ($src !== '' && ! str_starts_with($src, 'data:') && ! str_contains($src, 'transparent-pixel')) {
                    return $this->absoluteUrl($src, $pageUrl);
                }
            }
        }

        return null;
    }

    private function guessCurrency(string $pageUrl, ?string $price = null): ?string
    {
        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        if (str_ends_with($host, '.com.mx') || str_ends_with($host, '.mx')) {
            return 'MXN';
        }
        if (str_ends_with($host, '.com.ar')) {
            return 'ARS';
        }
        if (str_ends_with($host, '.com.co')) {
            return 'COP';
        }
        if (str_ends_with($host, '.com.br')) {
            return 'BRL';
        }
        if (str_ends_with($host, '.cl')) {
            return 'CLP';
        }
        if (str_ends_with($host, '.es')) {
            return 'EUR';
        }
        if (str_ends_with($host, '.com') || str_ends_with($host, '.us')) {
            return 'USD';
        }

        return null;
    }
}
