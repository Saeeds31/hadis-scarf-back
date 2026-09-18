<?php

namespace Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class VariantSalesReportExport implements FromArray, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithEvents
{
    protected array $data;
    protected array $summary;
    protected array $filters;

    public function __construct(array $data, array $summary = [], array $filters = [])
    {
        $this->data = $data;
        $this->summary = $summary;
        $this->filters = $filters;
    }

    public function array(): array
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'شناسه تنوع',
            'نام محصول',
            'SKU تنوع',
            'ویژگی‌ها',
            'قیمت (تومان)',
            'موجودی',
            'تعداد فروش',
            'تعداد سفارش',
            'درآمد کل (تومان)',
            'میانگین قیمت فروش (تومان)',
        ];
    }

    public function map($item): array
    {
        return [
            $item['variant']['id'] ?? '',
            $item['variant']['product']['title'] ?? '',
            $item['variant']['sku'] ?? '-',
            $this->formatAttributes($item['variant']['attributes'] ?? []),
            $item['variant']['price'] ?? 0,
            $item['variant']['stock'] ?? 0,
            $item['sales_summary']['total_quantity_sold'] ?? 0,
            $item['sales_summary']['total_orders'] ?? 0,
            $item['sales_summary']['total_revenue'] ?? 0,
            $item['sales_summary']['average_price'] ?? 0,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '305496'],
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // راست‌چین کردن کل شیت
                $sheet->setRightToLeft(true);

                // ارتفاع ردیف هدر
                $sheet->getRowDimension(1)->setRowHeight(28);

                // تعیین محدوده داده‌ها
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                $range = "A1:{$highestColumn}{$highestRow}";

                // اضافه کردن border به کل جدول
                $sheet->getStyle($range)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'BFBFBF'],
                        ],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                // رنگ‌آمیزی ردیف‌های زوج
                for ($row = 2; $row <= $highestRow; $row++) {
                    if ($row % 2 === 0) {
                        $sheet->getStyle("A{$row}:{$highestColumn}{$row}")
                            ->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setRGB('F2F2F2');
                    }
                }

                // فریز کردن ردیف هدر
                $sheet->freezePane('A2');
            },
        ];
    }

    // ============ فرمت‌کننده ویژگی‌ها ============

    protected function formatAttributes($attributes): string
    {
        if (empty($attributes)) {
            return '-';
        }

        if ($attributes instanceof \Illuminate\Support\Collection) {
            $attributes = $attributes->toArray();
        }

        if (!is_array($attributes)) {
            return '-';
        }

        $formatted = [];
        foreach ($attributes as $attr) {
            if (is_array($attr) && isset($attr['attribute_name'], $attr['value'])) {
                $formatted[] = $attr['attribute_name'] . ': ' . $attr['value'];
            } elseif (is_object($attr) && isset($attr->attribute_name, $attr->value)) {
                $formatted[] = $attr->attribute_name . ': ' . $attr->value;
            }
        }

        return !empty($formatted) ? implode(' | ', $formatted) : '-';
    }
}