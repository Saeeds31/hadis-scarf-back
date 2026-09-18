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

class OrderReportExport implements FromArray, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithEvents
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
            'شناسه سفارش',
            'نام مشتری',
            'موبایل',
            'مبلغ کل (تومان)',
            'تخفیف (تومان)',
            'هزینه ارسال (تومان)',
            'وضعیت سفارش',
            'وضعیت پرداخت',
            'روش پرداخت',
            'استان',
            'شهر',
            'روش حمل و نقل',
            'کد تخفیف',
            'تعداد اقلام',
            'تاریخ ثبت',
        ];
    }

    public function map($order): array
    {
        return [
            $order['id'] ?? '',
            $order['user']['full_name'] ?? 'کاربر مهمان',
            $order['user']['mobile'] ?? '-',
            $order['total'] ?? 0,
            $order['discount_amount'] ?? 0,
            $order['shipping_cost'] ?? 0,
            $this->translateStatus($order['status'] ?? ''),
            $this->translatePaymentStatus($order['payment_status'] ?? ''),
            $this->translatePaymentMethod($order['payment_method'] ?? ''),
            $order['address']['province']['name'] ?? '-',
            $order['address']['city']['name'] ?? '-',
            $order['shipping']['title'] ?? '-',
            $order['coupon']['code'] ?? '-',
            isset($order['items']) ? count($order['items']) : 0,
            isset($order['created_at']) ? date('Y-m-d H:i', strtotime($order['created_at'])) : '',
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

    // ============ مترجم‌ها ============

    protected function translateStatus(string $status): string
    {
        $map = [
            'pending'    => 'در انتظار',
            'reserved'   => 'رزرو شده',
            'processing' => 'در حال پردازش',
            'shipped'    => 'ارسال شده',
            'completed'  => 'تکمیل شده',
            'canceled'   => 'لغو شده',
            'cancelled'  => 'لغو شده',
            'returned'   => 'مرجوعی',
            'paid'       => 'پرداخت شده',
            'delivered'  => 'تحویل داده شده',
            'failed'     => 'خطا شده',
        ];
        return $map[$status] ?? $status;
    }

    protected function translatePaymentStatus(string $status): string
    {
        $map = [
            'pending'  => 'در انتظار پرداخت',
            'paid'     => 'پرداخت شده',
            'failed'   => 'ناموفق',
            'refunded' => 'برگشت داده شده',
        ];
        return $map[$status] ?? $status;
    }

    protected function translatePaymentMethod(string $method): string
    {
        $map = [
            'online'        => 'پرداخت آنلاین',
            'wallet'        => 'کیف پول',
            'cod'           => 'پرداخت در محل',
            'card_transfer' => 'کارت به کارت',
        ];
        return $map[$method] ?? $method;
    }
}