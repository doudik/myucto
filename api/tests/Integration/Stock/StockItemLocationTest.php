<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Eshop\CatalogExportService;
use MyInvoice\Service\Eshop\CatalogExportWorker;
use MyInvoice\Service\Eshop\Import\CatalogImportService;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Eshop\Import\CatalogImportWorker;
use MyInvoice\Service\Eshop\ProductEditorService;
use MyInvoice\Service\Stock\StockItemDuplicationService;
use MyInvoice\Service\Stock\StockReportService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\Psr7\UploadedFile;

/**
 * Umístění na skladové kartě (`stock_items.location_text`): volný text, kde kus
 * fyzicky je (showroom, u partnera). Vynechaný klíč hodnotu nemění, prázdný text
 * ji smaže, duplikace karty ji nepřenáší.
 */
#[Group('integration')]
final class StockItemLocationTest extends StockTestCase
{
    private StockItemAction $action;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(StockItemAction::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testCreateTrimsLocationAndPartialUpdateKeepsOrClearsIt(): void
    {
        $sid = $this->createSupplier();

        $created = $this->call('POST', $sid, ['name' => 'Karta s umístěním', 'sku' => 'LOC-1', 'location_text' => '  Showroom  ']);
        self::assertSame(201, $created['status']);
        self::assertSame('Showroom', $created['body']['location_text']);
        $id = (int) $created['body']['id'];

        $kept = $this->call('PUT', $sid, ['name' => 'Přejmenovaná karta'], $id);
        self::assertSame(200, $kept['status']);
        self::assertSame('Showroom', $kept['body']['location_text']);

        $moved = $this->call('PUT', $sid, ['location_text' => 'Partner Brno'], $id);
        self::assertSame('Partner Brno', $moved['body']['location_text']);

        $cleared = $this->call('PUT', $sid, ['location_text' => '   '], $id);
        self::assertNull($cleared['body']['location_text']);

        $tooLong = $this->call('PUT', $sid, ['location_text' => str_repeat('x', 101)], $id);
        self::assertSame(400, $tooLong['status']);
        self::assertSame('validation_failed', $tooLong['body']['error']['code']);
        self::assertNull($this->itemsRepo->find($sid, $id)['location_text']);
    }

    public function testProductEditorKeepsOmittedLocationAndDuplicateDoesNotCopyIt(): void
    {
        $sid = $this->createSupplier();
        $id = $this->itemsRepo->insert($sid, ['sku' => 'LOC-ED', 'name' => 'Editor', 'location_text' => 'Showroom']);
        $editor = $this->container->get(ProductEditorService::class);

        $editor->save($sid, $id, ['row_version' => $this->itemsRepo->find($sid, $id)['row_version'], 'item' => ['name' => 'Editor 2']]);
        self::assertSame('Showroom', $this->itemsRepo->find($sid, $id)['location_text']);

        $editor->save($sid, $id, ['row_version' => $this->itemsRepo->find($sid, $id)['row_version'], 'item' => ['location_text' => ' Sklad B ']]);
        self::assertSame('Sklad B', $this->itemsRepo->find($sid, $id)['location_text']);

        // Kód, který kartu ukládá bez znalosti umístění (např. starší import), ho nesmí smazat.
        $base = $this->itemsRepo->find($sid, $id);
        unset($base['location_text']);
        $this->itemsRepo->update($sid, $id, $base);
        self::assertSame('Sklad B', $this->itemsRepo->find($sid, $id)['location_text']);

        $copy = $this->container->get(StockItemDuplicationService::class)->duplicate($sid, $id, [
            'sku' => 'LOC-ED-COPY', 'name' => 'Kopie', 'sections' => ['core'],
            'row_version' => $this->itemsRepo->find($sid, $id)['row_version'],
        ]);
        self::assertNull($copy['location_text']);
    }

    public function testListFiltersByLocationSearchesItAndOffersDistinctValues(): void
    {
        $sid = $this->createSupplier();
        $showroom = $this->itemsRepo->insert($sid, ['sku' => 'LOC-A', 'name' => 'Motocykl A', 'location_text' => 'Showroom']);
        $this->itemsRepo->insert($sid, ['sku' => 'LOC-B', 'name' => 'Motocykl B', 'location_text' => 'Partner Brno']);
        $this->itemsRepo->insert($sid, ['sku' => 'LOC-C', 'name' => 'Motocykl C', 'location_text' => 'Showroom']);
        $this->itemsRepo->insert($sid, ['sku' => 'LOC-D', 'name' => 'Bez umístění']);
        $this->itemsRepo->insert($this->createSupplier(), ['sku' => 'LOC-X', 'name' => 'Cizí', 'location_text' => 'Cizí sklad']);

        $byLocation = $this->callList($sid, ['location' => 'Showroom']);
        self::assertSame(200, $byLocation['status']);
        self::assertSame(['LOC-A', 'LOC-C'], array_column($byLocation['body']['data'], 'sku'));
        self::assertSame('Showroom', $byLocation['body']['data'][0]['location_text']);

        $bySearch = $this->callList($sid, ['q' => 'brno']);
        self::assertSame(['LOC-B'], array_column($bySearch['body']['data'], 'sku'));

        [$rows] = $this->itemsRepo->listPaged($sid, ['location' => 'showroom'], 50, 0);
        self::assertContains($showroom, array_column($rows, 'id'));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/stock/items/locations')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        $locations = $this->decode($this->action->locations($request, new Psr7Response()));
        self::assertSame(['Partner Brno', 'Showroom'], $locations['body']);
    }

    public function testStockStatusReportCarriesLocation(): void
    {
        $sid = $this->createSupplier();
        $warehouse = $this->warehouse($sid);
        $id = $this->itemsRepo->insert($sid, ['sku' => 'LOC-REP', 'name' => 'Sestava', 'location_text' => 'Showroom']);
        $this->levels->setLevel($sid, $warehouse, $id, 1_000, 1_000);

        $report = $this->container->get(StockReportService::class)->status($sid, []);
        self::assertSame('Showroom', $report['items'][0]['location_text']);
    }

    public function testCatalogImportAndExportCarryLocation(): void
    {
        $sid = $this->createSupplier();
        $existing = $this->itemsRepo->insert($sid, ['sku' => 'LOC-IMP-1', 'name' => 'Import 1', 'location_text' => 'Showroom']);

        $preview = $this->importPreview($sid, "sku;name;misto\nLOC-IMP-1;Import 1;Partner Brno\nLOC-IMP-2;Import 2;Showroom\n", [
            'mapping' => ['sku' => 'sku', 'name' => 'name', 'location_text' => 'misto'],
        ]);
        self::assertSame(2, $preview['report']['counts']['ready']);
        $this->container->get(CatalogImportService::class)->apply($sid, $preview['id'], $this->userId);
        $applied = $this->container->get(CatalogImportWorker::class)->tickKind($sid, CatalogImportService::APPLY_KIND);
        self::assertSame(2, $applied['report']['counts']['applied']);
        self::assertSame('Partner Brno', $this->itemsRepo->find($sid, $existing)['location_text']);
        self::assertSame('Showroom', $this->itemsRepo->findBySku($sid, 'LOC-IMP-2')['location_text']);

        $tooLong = $this->importPreview($sid, "sku;misto\nLOC-IMP-1;" . str_repeat('x', 101) . "\n", [
            'mapping' => ['sku' => 'sku', 'location_text' => 'misto'],
        ]);
        self::assertSame(1, $tooLong['report']['counts']['failed']);

        $service = $this->container->get(CatalogExportService::class);
        $job = $service->enqueue($sid, ['ids' => [$existing]], ['fields' => ['sku', 'location_text']], $this->userId);
        $this->container->get(CatalogExportWorker::class)->tick($sid);
        $stream = $service->download($sid, $job['id']);
        $lines = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim((string) stream_get_contents($stream))));
        fclose($stream);
        self::assertSame('Partner Brno', $lines[1]['data']['location_text']);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function importPreview(int $supplierId, string $csv, array $config): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'stock-location-');
        $this->files[] = $path;
        file_put_contents($path, $csv);
        $sources = $this->container->get(CatalogImportSourceStore::class);
        $source = $sources->upload($supplierId, new UploadedFile($path, 'fixture.csv', 'text/csv', strlen($csv)), $this->userId);
        $this->files[] = $sources->path($supplierId, $source['id']);
        $this->container->get(CatalogImportService::class)->preview($supplierId, $source['id'], $config, $this->userId);
        return $this->container->get(CatalogImportWorker::class)->tickKind($supplierId, CatalogImportService::STAGE_KIND, 10);
    }

    /** @param array<string,mixed> $body @return array{status:int,body:array<string,mixed>} */
    private function call(string $method, int $supplierId, array $body, ?int $id = null): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/stock/items' . ($id !== null ? '/' . $id : ''))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $response = $id === null
            ? $this->action->create($request, new Psr7Response())
            : $this->action->update($request, new Psr7Response(), ['id' => (string) $id]);
        return $this->decode($response);
    }

    /** @param array<string,mixed> $query @return array{status:int,body:array<string,mixed>} */
    private function callList(int $supplierId, array $query): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/stock/items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query);
        return $this->decode($this->action->list($request, new Psr7Response()));
    }

    /** @return array{status:int,body:array<mixed>} */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }
}
