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

class UserPurchaseReportExport implements FromArray, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithEvents
{
    protected array $data;
    protected array $summary;
    protected array $filters;

    public function __construct(array $data, array $summary, array $filters = [])
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
            'شناسه',
            'نام کاربر',
            'موبایل',
            'کد ملی',
            'کیف پول (تومان)',
            'تعداد سفارش',
            'تعداد کالا',
            'کل خرید (تومان)',
            'میانگین خرید (تومان)',
        ];
    }

    public function map($item): array
    {
        return [
            $item['user']['id'] ?? '',
            $item['user']['full_name'] ?? 'کاربر مهمان',
            $item['user']['mobile'] ?? '-',
            $item['user']['national_code'] ?? '-',
            $item['user']['has_wallet'] ? ($item['user']['wallet_balance'] ?? 0) : 'ندارد',
            $item['purchase_summary']['total_orders'] ?? 0,
            $item['purchase_summary']['total_items'] ?? 0,
            $item['purchase_summary']['total_spent'] ?? 0,
            $item['purchase_summary']['average_order_value'] ?? 0,
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
}