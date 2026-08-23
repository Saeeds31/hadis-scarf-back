<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Modules\Categories\Models\Category;

class ReportsController extends Controller
{
    /**
     * Dashboard summary with charts
     */
    public function dashboardReport(Request $request)
    {
        // وضعیت‌های معتبر برای سفارشات موفق
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        // کوئری پایه برای سفارشات موفق
        $baseQuery = Order::where(function ($query) use ($validStatuses) {
            $query->whereIn('status', $validStatuses)
                ->orWhere('payment_status', 'paid');
        });

        // اعمال فیلتر تاریخ
        if ($request->filled('date_from')) {
            $baseQuery->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $baseQuery->whereDate('created_at', '<=', $request->date_to);
        }

        // آمار خلاصه
        $summary = [
            'total_sales' => $baseQuery->sum('total') ?? 0,
            'total_orders' => $baseQuery->count(),
            'average_order_value' => $baseQuery->count() > 0
                ? round($baseQuery->sum('total') / $baseQuery->count())
                : 0,
            'total_customers' => $baseQuery->distinct('user_id')->count('user_id'),
            'total_discount' => $baseQuery->sum('discount_amount') ?? 0,
        ];

        // فروش روزانه (نمودار)
        $dailySales = Order::where(function ($query) use ($validStatuses) {
            $query->whereIn('status', $validStatuses)
                ->orWhere('payment_status', 'paid');
        })
            ->when($request->filled('date_from'), function ($q) use ($request) {
                return $q->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                return $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('SUM(discount_amount) as total_discount')
            )
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        // محصولات پرفروش
        $topProducts = OrderItem::whereHas('order', function ($q) use ($request, $validStatuses) {
            $q->where(function ($query) use ($validStatuses) {
                $query->whereIn('status', $validStatuses)
                    ->orWhere('payment_status', 'paid');
            });
            if ($request->filled('date_from')) {
                $q->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $q->whereDate('created_at', '<=', $request->date_to);
            }
        })
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.title',
                'products.main_image',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.price * order_items.quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as order_count')
            )
            ->groupBy('products.id', 'products.title', 'products.main_image')
            ->orderBy('total_revenue', 'desc')
            ->limit(10)
            ->get();

        // فروش به تفکیک روش پرداخت
        $paymentMethods = Order::where(function ($query) use ($validStatuses) {
            $query->whereIn('status', $validStatuses)
                ->orWhere('payment_status', 'paid');
        })
            ->when($request->filled('date_from'), function ($q) use ($request) {
                return $q->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                return $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->select(
                'payment_method',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(total) as total_amount'),
                DB::raw('AVG(total) as average_amount')
            )
            ->groupBy('payment_method')
            ->get();

        // فروش به تفکیک وضعیت
        $ordersByStatus = Order::when($request->filled('date_from'), function ($q) use ($request) {
            return $q->whereDate('created_at', '>=', $request->date_from);
        })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                return $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total_amount'))
            ->groupBy('status')
            ->get();

        // فروش ماهانه (برای نمودار)
        $monthlySales = Order::where(function ($query) use ($validStatuses) {
            $query->whereIn('status', $validStatuses)
                ->orWhere('payment_status', 'paid');
        })
            ->when($request->filled('date_from'), function ($q) use ($request) {
                return $q->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                return $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('COUNT(*) as total_orders')
            )
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();

        return response()->json([
            'summary' => $summary,
            'daily_sales' => $dailySales,
            'monthly_sales' => $monthlySales,
            'top_products' => $topProducts,
            'payment_methods' => $paymentMethods,
            'orders_by_status' => $ordersByStatus,
            'filters' => $request->all(),
        ]);
    }

    /**
     * Comprehensive Product Report with In/Out/Stock
     */
    public function productInventoryReport(Request $request)
    {
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        $query = Product::query()
            ->with(['categories', 'variants.values.attribute']);

        // فیلترها
        if ($request->filled('category_id')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('categories.id', $request->category_id);
            });
        }

        if ($request->filled('product_id')) {
            $query->where('id', $request->product_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                    ->orWhere('sku', 'like', "%{$request->search}%")
                    ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // دریافت محصولات
        $products = $query->get();

        $reportData = $products->map(function ($product) use ($request, $validStatuses) {
            // کوئری پایه برای آیتم‌های سفارش
            $orderItemsQuery = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($validStatuses) {
                    $q->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                });

            // اعمال فیلتر تاریخ
            if ($request->filled('date_from')) {
                $orderItemsQuery->whereHas('order', function ($q) use ($request) {
                    $q->whereDate('created_at', '>=', $request->date_from);
                });
            }
            if ($request->filled('date_to')) {
                $orderItemsQuery->whereHas('order', function ($q) use ($request) {
                    $q->whereDate('created_at', '<=', $request->date_to);
                });
            }

            // محاسبه آمار فروش
            $salesData = $orderItemsQuery->select(
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(price * quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_id) as total_orders')
            )->first();

            // فروش به ازای هر شخص
            $perPersonSales = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($request, $validStatuses) {
                    $q->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                    if ($request->filled('date_from')) {
                        $q->whereDate('created_at', '>=', $request->date_from);
                    }
                    if ($request->filled('date_to')) {
                        $q->whereDate('created_at', '<=', $request->date_to);
                    }
                })
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->select(
                    'users.id as user_id',
                    'users.full_name',
                    'users.mobile',
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as total_spent'),
                    DB::raw('COUNT(DISTINCT orders.id) as order_count')
                )
                ->groupBy('users.id', 'users.full_name', 'users.mobile')
                ->orderBy('total_quantity', 'desc')
                ->limit(10)
                ->get();

            // فروش‌های اخیر
            $recentSales = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($request, $validStatuses) {
                    $q->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                    if ($request->filled('date_from')) {
                        $q->whereDate('created_at', '>=', $request->date_from);
                    }
                    if ($request->filled('date_to')) {
                        $q->whereDate('created_at', '<=', $request->date_to);
                    }
                })
                ->with(['order.user', 'order'])
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get()
                ->map(function ($item) {
                    return [
                        'order_id' => $item->order_id,
                        'customer_name' => $item->order->user->full_name ?? 'کاربر مهمان',
                        'customer_mobile' => $item->order->user->mobile ?? '-',
                        'quantity' => $item->quantity,
                        'price_per_unit' => $item->price,
                        'total_price' => $item->price * $item->quantity,
                        'sold_at' => $item->order->created_at->format('Y-m-d H:i'),
                        'order_status' => $item->order->status,
                        'payment_status' => $item->order->payment_status,
                    ];
                });

            // داده‌های نمودار (فروش روزانه)
            $chartData = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($request, $validStatuses) {
                    $q->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                    if ($request->filled('date_from')) {
                        $q->whereDate('created_at', '>=', $request->date_from);
                    }
                    if ($request->filled('date_to')) {
                        $q->whereDate('created_at', '<=', $request->date_to);
                    }
                })
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->select(
                    DB::raw('DATE(orders.created_at) as date'),
                    DB::raw('SUM(order_items.quantity) as daily_quantity'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as daily_revenue')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // محاسبه موجودی ورودی (بر اساس فروش + موجودی فعلی)
            $totalIncoming = $product->stock + ($salesData->total_quantity ?? 0);

            return [
                'product' => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'price' => $product->price,
                    'stock' => $product->stock,
                    'main_image' => $product->main_image,
                    'status' => $product->status,
                    'categories' => $product->categories->pluck('name'),
                    'variants' => $product->variants->map(function ($variant) {
                        return [
                            'id' => $variant->id,
                            'sku' => $variant->sku,
                            'price' => $variant->price,
                            'stock' => $variant->stock,
                            'attributes' => $variant->values->map(function ($value) {
                                return $value->attribute->name . ': ' . $value->value;
                            })->join(' - '),
                        ];
                    }),
                ],
                'inventory_summary' => [
                    'total_incoming' => $totalIncoming,
                    'total_outgoing' => $salesData->total_quantity ?? 0,
                    'current_stock' => $product->stock,
                ],
                'sales_summary' => [
                    'total_quantity_sold' => $salesData->total_quantity ?? 0,
                    'total_revenue' => $salesData->total_revenue ?? 0,
                    'total_orders' => $salesData->total_orders ?? 0,
                    'average_price' => ($salesData->total_quantity ?? 0) > 0
                        ? round(($salesData->total_revenue ?? 0) / ($salesData->total_quantity ?? 0))
                        : 0,
                ],
                'per_person_sales' => $perPersonSales,
                'recent_sales' => $recentSales,
                'chart_data' => $chartData,
            ];
        });

        // خلاصه آماری کل
        $summary = [
            'total_products' => $products->count(),
            'total_stock' => $products->sum('stock'),
            'total_revenue' => $reportData->sum('sales_summary.total_revenue'),
            'total_items_sold' => $reportData->sum('sales_summary.total_quantity_sold'),
            'products_with_sales' => $reportData->filter(function ($item) {
                return $item['sales_summary']['total_quantity_sold'] > 0;
            })->count(),
            'products_without_sales' => $reportData->filter(function ($item) {
                return $item['sales_summary']['total_quantity_sold'] == 0;
            })->count(),
        ];

        return response()->json([
            'data' => $reportData,
            'summary' => $summary,
            'filters' => $request->all(),
        ]);
    }

    /**
     * Detailed sales report by product with pagination
     */
    public function productDetailedReport(Request $request)
    {
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        $query = Product::query()
            ->with(['categories', 'variants.values.attribute'])
            ->withCount([
                'orderItems as total_sold' => function ($q) use ($request, $validStatuses) {
                    $q->whereHas('order', function ($q2) use ($request, $validStatuses) {
                        $q2->where(function ($query) use ($validStatuses) {
                            $query->whereIn('status', $validStatuses)
                                ->orWhere('payment_status', 'paid');
                        });
                        if ($request->filled('date_from')) {
                            $q2->whereDate('created_at', '>=', $request->date_from);
                        }
                        if ($request->filled('date_to')) {
                            $q2->whereDate('created_at', '<=', $request->date_to);
                        }
                    });
                }
            ])
            ->withSum([
                'orderItems as total_revenue' => function ($q) use ($request, $validStatuses) {
                    $q->whereHas('order', function ($q2) use ($request, $validStatuses) {
                        $q2->where(function ($query) use ($validStatuses) {
                            $query->whereIn('status', $validStatuses)
                                ->orWhere('payment_status', 'paid');
                        });
                        if ($request->filled('date_from')) {
                            $q2->whereDate('created_at', '>=', $request->date_from);
                        }
                        if ($request->filled('date_to')) {
                            $q2->whereDate('created_at', '<=', $request->date_to);
                        }
                    });
                }
            ], 'price');

        // اعمال فیلترها
        if ($request->filled('category_id')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('categories.id', $request->category_id);
            });
        }

        if ($request->filled('product_id')) {
            $query->where('id', $request->product_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                    ->orWhere('sku', 'like', "%{$request->search}%")
                    ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('min_sold')) {
            $query->having('total_sold', '>=', $request->min_sold);
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->filled('only_with_sales')) {
            $query->having('total_sold', '>', 0);
        }

        if ($request->filled('only_without_sales')) {
            $query->having('total_sold', '=', 0);
        }

        // مرتب‌سازی
        if ($request->filled('sort_by')) {
            $sortField = $request->sort_by;
            $sortOrder = $request->sort_order ?? 'desc';

            if (in_array($sortField, ['total_sold', 'total_revenue', 'price', 'stock', 'created_at'])) {
                $query->orderBy($sortField, $sortOrder);
            } else {
                $query->orderBy($sortField, $sortOrder);
            }
        } else {
            $query->orderBy('total_sold', 'desc');
        }

        $perPage = $request->filled('per_page') ? $request->per_page : 20;
        $products = $query->paginate($perPage);

        // اضافه کردن خلاصه آماری به پاسخ
        $summary = [
            'total_products' => $products->total(),
            'total_revenue' => $products->sum('total_revenue'),
            'total_items_sold' => $products->sum('total_sold'),
        ];

        return response()->json([
            'data' => $products->items(),
            'summary' => $summary,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'filters' => $request->all(),
        ]);
    }

    /**
     * User purchase report - how much each person bought
     */
    public function userPurchaseReport(Request $request)
    {
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        $query = User::query()
            ->with(['addresses', 'roles', 'wallet']);

        // فیلترها
        if ($request->filled('role_id')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('roles.id', $request->role_id);
            });
        }

        if ($request->filled('mobile')) {
            $query->where('mobile', 'like', "%{$request->mobile}%");
        }

        if ($request->filled('full_name')) {
            $query->where('full_name', 'like', "%{$request->full_name}%");
        }

        if ($request->filled('national_code')) {
            $query->where('national_code', 'like', "%{$request->national_code}%");
        }

        if ($request->filled('has_wallet')) {
            if ($request->has_wallet) {
                $query->has('wallet');
            } else {
                $query->doesntHave('wallet');
            }
        }

        // دریافت کاربران
        $users = $query->get();

        $reportData = $users->map(function ($user) use ($request, $validStatuses) {
            // کوئری سفارشات کاربر
            $ordersQuery = Order::where('user_id', $user->id)
                ->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });

            if ($request->filled('date_from')) {
                $ordersQuery->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $ordersQuery->whereDate('created_at', '<=', $request->date_to);
            }

            $orders = $ordersQuery->with(['items.product'])->get();

            // داده‌های خرید
            $purchaseData = [
                'total_orders' => $orders->count(),
                'total_items' => $orders->sum(function ($order) {
                    return $order->items->sum('quantity');
                }),
                'total_spent' => $orders->sum('total'),
                'average_order_value' => $orders->count() > 0
                    ? round($orders->sum('total') / $orders->count())
                    : 0,
                'first_purchase' => $orders->min('created_at'),
                'last_purchase' => $orders->max('created_at'),
                'orders' => $orders->map(function ($order) {
                    return [
                        'order_id' => $order->id,
                        'total' => $order->total,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'payment_method' => $order->payment_method,
                        'items' => $order->items->map(function ($item) {
                            return [
                                'product' => $item->product->title ?? 'محصول حذف شده',
                                'product_id' => $item->product_id,
                                'quantity' => $item->quantity,
                                'price' => $item->price,
                                'total' => $item->price * $item->quantity,
                            ];
                        }),
                        'created_at' => $order->created_at->format('Y-m-d H:i'),
                    ];
                }),
            ];

            // محصولات پرفروش این کاربر
            $topProducts = OrderItem::whereHas('order', function ($q) use ($user, $request, $validStatuses) {
                $q->where('user_id', $user->id)
                    ->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                if ($request->filled('date_from')) {
                    $q->whereDate('created_at', '>=', $request->date_from);
                }
                if ($request->filled('date_to')) {
                    $q->whereDate('created_at', '<=', $request->date_to);
                }
            })
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->select(
                    'products.id',
                    'products.title',
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as total_spent'),
                    DB::raw('COUNT(DISTINCT order_items.order_id) as order_count')
                )
                ->groupBy('products.id', 'products.title')
                ->orderBy('total_quantity', 'desc')
                ->limit(5)
                ->get();

            return [
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'mobile' => $user->mobile,
                    'national_code' => $user->national_code,
                    'birth_date' => $user->birth_date,
                    'roles' => $user->roles->pluck('name'),
                    'wallet_balance' => $user->wallet?->balance ?? 0,
                    'has_wallet' => $user->wallet ? true : false,
                ],
                'purchase_summary' => $purchaseData,
                'top_products' => $topProducts,
            ];
        });

        // خلاصه آماری کل
        $summary = [
            'total_users' => $users->count(),
            'total_orders' => $reportData->sum('purchase_summary.total_orders'),
            'total_spent' => $reportData->sum('purchase_summary.total_spent'),
            'total_items' => $reportData->sum('purchase_summary.total_items'),
            'average_spent_per_user' => $users->count() > 0
                ? round($reportData->sum('purchase_summary.total_spent') / $users->count())
                : 0,
        ];

        return response()->json([
            'data' => $reportData,
            'summary' => $summary,
            'filters' => $request->all(),
        ]);
    }

    /**
     * Product entry/exit (inventory movement) report
     */
    public function inventoryMovementReport(Request $request)
    {
        $productId = $request->product_id;

        if (!$productId) {
            return response()->json(['error' => 'شناسه محصول الزامی است'], 400);
        }

        $product = Product::with(['categories', 'variants.values.attribute'])->find($productId);

        if (!$product) {
            return response()->json(['error' => 'محصول یافت نشد'], 404);
        }

        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        // دریافت تمام آیتم‌های سفارش برای این محصول
        $orderItemsQuery = OrderItem::where('product_id', $productId)
            ->with(['order.user', 'variant.values.attribute']);

        if ($request->filled('date_from')) {
            $orderItemsQuery->whereHas('order', function ($q) use ($request) {
                $q->whereDate('created_at', '>=', $request->date_from);
            });
        }

        if ($request->filled('date_to')) {
            $orderItemsQuery->whereHas('order', function ($q) use ($request) {
                $q->whereDate('created_at', '<=', $request->date_to);
            });
        }

        $orderItems = $orderItemsQuery->orderBy('created_at', 'desc')->get();

        // داده‌های روزانه برای نمودار
        $dailyMovements = OrderItem::where('product_id', $productId)
            ->whereHas('order', function ($q) use ($request, $validStatuses) {
                $q->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });
                if ($request->filled('date_from')) {
                    $q->whereDate('created_at', '>=', $request->date_from);
                }
                if ($request->filled('date_to')) {
                    $q->whereDate('created_at', '<=', $request->date_to);
                }
            })
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->select(
                DB::raw('DATE(orders.created_at) as date'),
                DB::raw('SUM(order_items.quantity) as outgoing'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as orders_count'),
                DB::raw('SUM(order_items.price * order_items.quantity) as revenue')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $report = [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'current_stock' => $product->stock,
                'price' => $product->price,
                'main_image' => $product->main_image,
                'status' => $product->status,
                'categories' => $product->categories->pluck('name'),
                'variants' => $product->variants->map(function ($variant) {
                    return [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'price' => $variant->price,
                        'stock' => $variant->stock,
                        'attributes' => $variant->values->map(function ($value) {
                            return $value->attribute->name . ': ' . $value->value;
                        })->join(' - '),
                    ];
                }),
            ],
            'movements' => $orderItems->map(function ($item) {
                return [
                    'date' => $item->created_at->format('Y-m-d H:i'),
                    'order_id' => $item->order_id,
                    'customer' => $item->order->user->full_name ?? 'کاربر مهمان',
                    'customer_mobile' => $item->order->user->mobile ?? '-',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->price * $item->quantity,
                    'variant' => $item->variant ? $item->variant->values->map(function ($value) {
                        return $value->attribute->name . ': ' . $value->value;
                    })->join(' - ') : null,
                    'order_status' => $item->order->status,
                    'payment_status' => $item->order->payment_status,
                    'payment_method' => $item->order->payment_method,
                ];
            }),
            'daily_movements' => $dailyMovements,
            'summary' => [
                'total_outgoing' => $orderItems->sum('quantity'),
                'total_revenue' => $orderItems->sum(function ($item) {
                    return $item->price * $item->quantity;
                }),
                'total_orders' => $orderItems->groupBy('order_id')->count(),
                'current_stock' => $product->stock,
                'total_customers' => $orderItems->groupBy('order.user_id')->count(),
            ],
            'filters' => $request->all(),
        ];

        return response()->json($report);
    }

    /**
     * Get product sales summary (quick stats)
     */
    public function productSalesSummary(Request $request)
    {
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        $query = Product::whereHas('orderItems', function ($q) use ($request, $validStatuses) {
            $q->whereHas('order', function ($q2) use ($request, $validStatuses) {
                $q2->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });
                if ($request->filled('date_from')) {
                    $q2->whereDate('created_at', '>=', $request->date_from);
                }
                if ($request->filled('date_to')) {
                    $q2->whereDate('created_at', '<=', $request->date_to);
                }
            });
        });

        if ($request->filled('category_id')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('categories.id', $request->category_id);
            });
        }

        $salesData = $query->join('order_items', 'products.id', '=', 'order_items.product_id')
            ->select(
                DB::raw('COUNT(DISTINCT products.id) as products_with_sales'),
                DB::raw('SUM(order_items.quantity) as total_items_sold'),
                DB::raw('SUM(order_items.price * order_items.quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as total_orders')
            )
            ->first();

        $totalProducts = Product::count();
        $productsWithoutSales = $totalProducts - ($salesData->products_with_sales ?? 0);

        return response()->json([
            'total_products' => $totalProducts,
            'products_with_sales' => $salesData->products_with_sales ?? 0,
            'products_without_sales' => $productsWithoutSales,
            'total_items_sold' => $salesData->total_items_sold ?? 0,
            'total_revenue' => $salesData->total_revenue ?? 0,
            'total_orders' => $salesData->total_orders ?? 0,
            'filters' => $request->all(),
        ]);
    }

    /**
     * Get top selling products
     */
    public function topSellingProducts(Request $request)
    {
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
        $limit = $request->filled('limit') ? $request->limit : 10;

        $query = OrderItem::whereHas('order', function ($q) use ($request, $validStatuses) {
            $q->where(function ($query) use ($validStatuses) {
                $query->whereIn('status', $validStatuses)
                    ->orWhere('payment_status', 'paid');
            });
            if ($request->filled('date_from')) {
                $q->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $q->whereDate('created_at', '<=', $request->date_to);
            }
        });

        if ($request->filled('category_id')) {
            $query->whereHas('product.categories', function ($q) use ($request) {
                $q->where('categories.id', $request->category_id);
            });
        }

        $products = $query->join('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.title',
                'products.main_image',
                'products.price',
                'products.stock',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.price * order_items.quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as order_count'),
                DB::raw('AVG(order_items.price) as average_price')
            )
            ->groupBy('products.id', 'products.title', 'products.main_image', 'products.price', 'products.stock')
            ->orderBy('total_revenue', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($products);
    }
}
