<?php

namespace Modules\Anipo\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Anipo\Models\AnipoOrder;
use Modules\Orders\Models\Order;

class OrderPayloadBuilder
{
    public function __construct(protected AnipoClient $client) {}

    public function build(Order $order, AnipoOrder $anipoOrder): array
    {
        $address = $order->address;
        $user    = $order->user;

        $province = $this->normalize($address?->province?->name);
        $city     = $this->normalize($address?->city?->name);

        $province = $this->matchProvince($province, $order->id);
        $city     = $this->matchCity($city, $province, $order->id);

        return [
            'order_code'           => $anipoOrder->anipo_order_code,
            'receiver_name'        => $this->normalize($address?->receiver_name ?: $user?->full_name ?: 'کاربر'),
            'receiver_mobile'      => $this->cleanMobile($address?->phone ?: $user?->mobile),
            'receiver_province'    => $province,
            'receiver_city'        => $city,
            'receiver_postal_code' => $address?->postal_code,
            'receiver_address'     => $this->normalize($address?->address_line),
            'weight_g'             => $this->calcWeight($order),
            'box_type'             => $this->guessBoxType($order),
            'parcel_content'       => $this->summarize($order),
            'order_total'          => $this->toRial((float) $order->total),
            'order_status'         => $order->status_label,
        ];
    }

    /**
     * تطبیق نام استان با لیست آنی‌پو (کش ۲۴ ساعته)
     */
    protected function matchProvince(?string $name, int $orderId): ?string
    {
        if (!$name) return null;

        $map = Cache::remember('anipo:provinces_map', now()->addDay(), function () {
            $res   = $this->client->provinces();
            $items = $res['data'] ?? $res;

            $map = [];
            foreach ($items as $item) {
                $n = is_array($item) ? ($item['name'] ?? null) : (string) $item;
                if ($n) $map[$this->normalize($n)] = $n;
            }
            return $map;
        });

        if (isset($map[$name])) {
            return $map[$name];
        }

        Log::channel('daily')->warning('Anipo: province exact match failed', [
            'order_id' => $orderId,
            'local'    => $name,
        ]);

        return $name;
    }

    /**
     * تطبیق نام شهر با لیست آنی‌پو برای همان استان (کش ۲۴ ساعته per-province)
     */
    protected function matchCity(?string $name, ?string $province, int $orderId): ?string
    {
        if (!$name || !$province) return $name;

        $cacheKey = 'anipo:cities_map:' . md5($province);

        $map = Cache::remember($cacheKey, now()->addDay(), function () use ($province) {
            $res   = $this->client->cities($province);
            $items = $res['data'] ?? $res;

            $map = [];
            foreach ($items as $item) {
                $n = is_array($item) ? ($item['name'] ?? null) : (string) $item;
                if ($n) $map[$this->normalize($n)] = $n;
            }
            return $map;
        });

        if (isset($map[$name])) {
            return $map[$name];
        }

        Log::channel('daily')->warning('Anipo: city exact match failed', [
            'order_id' => $orderId,
            'province' => $province,
            'local'    => $name,
        ]);

        return $name;
    }

    protected function normalize(?string $value): ?string
    {
        if ($value === null) return null;

        $value = str_replace(
            ['ي', 'ك', 'ۀ', 'ة'],
            ['ی', 'ک', 'ه', 'ه'],
            $value
        );

        $value = str_replace(["\u{200C}", "\t", "\n", "\r"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value) === '' ? null : trim($value);
    }

    protected function cleanMobile(?string $mobile): ?string
    {
        if (!$mobile) return null;

        $mobile = preg_replace('/\D+/', '', $mobile);

        if (str_starts_with($mobile, '98')) {
            $mobile = '0' . substr($mobile, 2);
        }
        if (str_starts_with($mobile, '9') && strlen($mobile) === 10) {
            $mobile = '0' . $mobile;
        }

        return strlen($mobile) === 11 ? $mobile : null;
    }

    protected function calcWeight(Order $order): ?int
    {
        $sum = 0;
        foreach ($order->items as $item) {
            $w = (int) ($item->product?->weight_g ?? 0);
            $sum += $w * max(1, (int) $item->quantity);
        }

        return $sum > 0 ? $sum : null;
    }

    protected function guessBoxType(Order $order): ?int
    {
        $count = (int) $order->items->sum('quantity');

        return match (true) {
            $count <= 1  => 1,
            $count <= 3  => 2,
            $count <= 6  => 3,
            $count <= 10 => 4,
            default      => 5,
        };
    }

    protected function summarize(Order $order): ?string
    {
        $text = $order->items
            ->map(fn($i) => ($i->product?->name ?? 'کالا') . ' × ' . $i->quantity)
            ->take(5)
            ->implode('، ');

        return $text === '' ? null : $text;
    }

    protected function toRial(float $amount): int
    {
        return config('anipo.money_unit') === 'toman'
            ? (int) round($amount * 10)
            : (int) round($amount);
    }
}
