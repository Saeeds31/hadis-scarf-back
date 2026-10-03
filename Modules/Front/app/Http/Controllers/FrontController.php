<?php

namespace Modules\Front\Http\Controllers;

use App\Support\CacheService;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Articles\Models\Article;
use Modules\Attributes\Models\Attribute;
use Modules\Banners\Models\Banner;
use Modules\Categories\Models\Category;
use Modules\Menus\Models\Menu;
use Modules\Products\Http\Resources\ProductCardResource;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Settings\Models\Setting;
use Modules\Sliders\Models\Slider;

class FrontController extends Controller
{
    // app/Http/Controllers/ProductController.php

    public function priceRange(): array
    {
        // کمترین/بیشترین قیمت در جدول products
        $minProductPrice = Product::min('price');
        $maxProductPrice = Product::max('price');

        // کمترین/بیشترین قیمت در جدول variants
        $minVariantPrice = ProductVariant::min('price');
        $maxVariantPrice = ProductVariant::max('price');

        // محاسبه‌ی نهایی با در نظر گرفتن مقدار null
        $min = null;
        $max = null;
        if ($minProductPrice !== null && $minVariantPrice !== null) {
            $min = min($minProductPrice, $minVariantPrice);
        } elseif ($minProductPrice !== null) {
            $min = $minProductPrice;
        } else {
            $min = $minVariantPrice;
        }

        if ($maxProductPrice !== null && $maxVariantPrice !== null) {
            $max = max($maxProductPrice, $maxVariantPrice);
        } elseif ($maxProductPrice !== null) {
            $max = $maxProductPrice;
        } else {
            $max = $maxVariantPrice;
        }

        return [
            'min_price' => $min,
            'max_price' => $max,
        ];
    }

    public function filters()
    {
        $data = [];
        $data['categories'] = CacheService::rememberWithTags(
            [CacheService::TAG_CATEGORIES],
            CacheService::BASE_CATEGORIES,
            CacheService::TTL_ONE_MONTH,
            fn() => Category::with('children')
                ->whereNull('parent_id')
                ->get()
        );
        $data['price'] = CacheService::remember(
            CacheService::PRODUCTS_PRICE,
            CacheService::TTL_ONE_WEEK,
            fn() => $this->priceRange()
        );
        $data['color'] =  CacheService::rememberWithTags(
            [CacheService::TAG_ATTRIBUTES],
            CacheService::BASE_COLOR_ATTRIBUTE,
            CacheService::TTL_ONE_MONTH,
            fn() => Attribute::find(1)->values
        );
        $data['ghavareh'] = CacheService::rememberWithTags(
            [CacheService::TAG_ATTRIBUTES],
            CacheService::BASE_SIZE_ATTRIBUTE,
            CacheService::TTL_ONE_MONTH,
            fn() => Attribute::find(2)->values
        );
        $data['tarh'] = CacheService::rememberWithTags(
            [CacheService::TAG_ATTRIBUTES],
            CacheService::BASE_TARH_ATTRIBUTE,
            CacheService::TTL_ONE_MONTH,
            fn() => Attribute::find(3)->values
        );

        return response()->json([
            'success' => true,
            'message' => 'فیلتر های محصولات',
            'data'    => $data
        ], 200);
    }

    public function HomeProducts()
    {
        $categories = Category::with([
            'products' => function ($q) {
                $q->where('status', 'published')
                    ->with(['variants.values.attribute'])
                    ->latest()
                    ->take(8);
            }
        ])
            ->where('show_products_in_home', true)
            ->get();

        $result = $categories->map(function ($category) {
            return [
                'category' => $category,
                'products' => ProductCardResource::collection($category->products),
            ];
        });

        return response()->json($result);
    }
    public function home()
    {
        $data = [];
        $data['selected_categories'] = CacheService::rememberWithTags(
            [CacheService::TAG_CATEGORIES],
            'home_selected_categories',
            CacheService::TTL_ONE_MONTH,
            fn() => Category::where('show_in_home', 1)->get()
        );
        $data['top_discounted_products'] =
            CacheService::rememberWithTags(
                [CacheService::TAG_PRODUCTS],
                'home_top_discounted_products',
                CacheService::TTL_ONE_WEEK,
                fn() => ProductCardResource::collection(
                    Product::topDiscounted()
                )
            );
        $data['banners'] =
            CacheService::remember(
                CacheService::HOME_BANNER,
                CacheService::TTL_ONE_MONTH,
                fn() => Banner::groupedByPosition()
            );
        $data['sliders'] = CacheService::remember(
            CacheService::HOME_SLIDER,
            CacheService::TTL_ONE_MONTH,
            fn() => Slider::orderBy('id')->get()
        );
        $data['new_products'] =
            CacheService::rememberWithTags(
                [CacheService::TAG_PRODUCTS],
                'home_new_products',
                CacheService::TTL_ONE_WEEK,
                fn() => ProductCardResource::collection(
                    Product::latestProducts()
                )
            );


        $data['blogs'] = CacheService::rememberWithTags(
            [CacheService::TAG_BLOGS],
            'home_latest_articles',
            CacheService::TTL_ONE_MONTH,
            fn() => Article::latestArticles()
        );
        return response()->json([
            'success' => true,
            'message' => 'اطلاعات صفحه اصلی',
            'data'    => $data
        ], 200);
    }

    public function base(Request $request)
    {
        $data = [];
        // بررسی وضعیت لاگین کاربر
        $user = Auth::guard('sanctum')->user();
        $data['user'] = $user ??  null;
        // settings
        $data['settings'] =
            CacheService::remember(
                CacheService::BASE_SETTINGS,
                CacheService::TTL_ONE_MONTH,
                function () {
                    return Setting::all()
                        ->groupBy('group')
                        ->map(function ($group) {
                            return $group->mapWithKeys(function ($setting) {
                                return [$setting->key => $setting->value];
                            })->toArray();
                        });
                }
            );
        // menus
        $data['menus'] = CacheService::remember(
            CacheService::BASE_MENUS,
            CacheService::TTL_ONE_MONTH,
            function () {
                return Menu::with('children')
                    ->whereNull('parent_id')
                    ->get();
            }
        );
        return response()->json([
            'success' => true,
            'message' => 'home data successfully',
            'data'    => $data
        ], 200);
    }
}
