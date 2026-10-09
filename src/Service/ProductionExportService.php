<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRepository;
use App\Repository\PrintFileRepository;
use App\Support\Config;
use RuntimeException;
use ZipArchive;

/**
 * A batch of orders, packed for the shop floor.
 *
 * The archive holds the press files and one collation sheet listing, for every
 * piece, the size, the colour, the quantity and the placement. That sheet is
 * the thing that matters: the PDFs say what to print, and only the sheet says
 * what to press it onto and how many. Printing a run from the order screen
 * alone means somebody counting shirts off a web page.
 *
 * Each press file appears once, however many lines call for it -- ten shirts in
 * five sizes sharing a design put one PDF in the archive, and five rows on the
 * sheet that all point at it by name.
 */
final class ProductionExportService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PrintFileRepository $printFiles,
    ) {
    }

    public static function make(): self
    {
        return new self(OrderRepository::make(), PrintFileRepository::make());
    }

    /**
     * @param array<int,int> $orderIds
     * @return array{path:string,name:string,orders:int,files:int,pieces:int}
     */
    public function build(array $orderIds): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('امتداد zip غير مثبّت على الخادم.');
        }

        $orders = $this->orders->findManyWithItems($orderIds);

        if ($orders === []) {
            throw new RuntimeException('لا طلبات في هذه الدفعة.');
        }

        $files = [];

        foreach ($this->printFiles->forOrders($orderIds) as $file) {
            $files[(int) $file['custom_design_id']] = $file;
        }

        $directory = Config::get('app.paths.storage') . '/exports';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('مجلد التصدير غير قابل للكتابة.');
        }

        $name = 'production-' . date('Ymd-His') . '.zip';
        $path = $directory . '/' . $name;

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذّر إنشاء الملف المضغوط.');
        }

        $root   = (string) Config::get('app.paths.print');
        $rows   = [];
        $pieces = 0;
        $added  = [];

        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $pieces  += (int) $item['quantity'];
                $customId = $item['custom_design_id'] === null ? 0 : (int) $item['custom_design_id'];
                $file     = $files[$customId] ?? null;
                $inZip    = '';

                if ($file !== null) {
                    $inZip  = 'print/design-' . $customId . '.pdf';
                    $source = $root . '/' . ltrim((string) $file['pdf_path'], '/');

                    // Once per design, not once per line.
                    if (!isset($added[$inZip]) && is_file($source)) {
                        $zip->addFile($source, $inZip);
                        $added[$inZip] = true;
                    }
                }

                $rows[] = [
                    (string) $order['order_number'],
                    (string) $item['product_name'],
                    (string) $item['sku'],
                    (string) $item['color_name'],
                    (string) $item['size_code'],
                    (string) $item['quantity'],
                    self::placement((string) $item['placement']),
                    self::printing($item),
                    $inZip,
                ];
            }
        }

        $zip->addFromString('collation.csv', $this->sheet($rows));
        $zip->addFromString('README.txt', $this->readme($orders, count($added), $pieces));
        $zip->close();

        return [
            'path'   => $path,
            'name'   => $name,
            'orders' => count($orders),
            'files'  => count($added),
            'pieces' => $pieces,
        ];
    }

    /**
     * CSV, opened by whatever the shop already has.
     *
     * The BOM is not decoration: without it Excel reads a UTF-8 file as the
     * system codepage and every Arabic colour name on the sheet turns to
     * mojibake -- on a sheet whose whole job is telling somebody which shirt
     * to pick up.
     *
     * @param array<int,array<int,string>> $rows
     */
    private function sheet(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'رقم الطلب', 'القطعة', 'SKU', 'اللون', 'المقاس',
            'الكمية', 'الموضع', 'الطباعة', 'ملف الطباعة',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @param array<int,array<string,mixed>> $orders */
    private function readme(array $orders, int $files, int $pieces): string
    {
        $numbers = array_map(static fn (array $o): string => (string) $o['order_number'], $orders);

        return implode("\n", [
            'دفعة إنتاج — ' . date('Y-m-d H:i'),
            '',
            'الطلبات: ' . count($orders) . ' (' . implode('، ', $numbers) . ')',
            'القطع:   ' . $pieces,
            'ملفات الطباعة: ' . $files,
            '',
            'collation.csv فيه سطر لكل قطعة: المقاس واللون والكمية والموضع،',
            'وعمود يشير إلى ملف الطباعة داخل مجلد print/.',
            '',
            'ملف طباعة واحد قد يخدم عدة أسطر — تصميم واحد على مقاسات مختلفة',
            'هو نفس الورقة على المكبس.',
        ]) . "\n";
    }

    private static function placement(string $placement): string
    {
        return match ($placement) {
            'back'  => 'الظهر',
            'both'  => 'الوجهان',
            default => 'الصدر',
        };
    }

    private static function printing(array $item): string
    {
        return match ($item['item_type']) {
            'ready'  => 'جاهز: ' . (string) $item['design_name'],
            'custom' => 'تصميم العميل',
            default  => 'بلا طباعة',
        };
    }
}
