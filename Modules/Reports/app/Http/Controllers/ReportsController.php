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

class ReportsController extends Controller
{
    /**
     * Comprehensive Product Report with In/Out/Stock
     */
    public function productInventoryReport(Request $request)
    {
        $query = Product::query()
            ->with(['categories', 'variants']);

        // Filters
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

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Get products with their inventory data
        $products = $query->get();

        $reportData = $products->map(function ($product) use ($request) {
            // Base query for order items
            $orderItemsQuery = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) {
                    $q->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                        ->orWhere('payment_status', 'paid');
                });

            // Apply date filters to order items
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

            // Calculate sales data
            $salesData = $orderItemsQuery->select(
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(price * quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_id) as total_orders')
            )->first();

            // Get recent sales with buyer info
            $recentSales = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($request) {
                    $q->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                        ->orWhere('payment_status', 'paid');

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
                        'quantity' => $item->quantity,
                        'price_per_unit' => $item->price,
                        'total_price' => $item->price * $item->quantity,
                        'sold_at' => $item->order->created_at->format('Y-m-d H:i'),
                        'order_status' => $item->order->status,
                    ];
                });

            // Calculate total incoming (purchases/restocks) - assuming you have a purchase model
            // If you don't have purchase model, you can add a field in product or variant
            $totalIncoming = $product->stock + ($salesData->total_quantity ?? 0); // This is logic based on current stock

            // Per person sales report (top customers for this product)
            $perPersonSales = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($request) {
                    $q->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                        ->orWhere('payment_status', 'paid');

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
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as total_spent'),
                    DB::raw('COUNT(DISTINCT orders.id) as order_count')
                )
                ->groupBy('users.id', 'users.full_name')
                ->orderBy('total_quantity', 'desc')
                ->limit(10)
                ->get();

            // Chart data for this product (sales over time)
            $chartData = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($request) {
                    $q->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                        ->orWhere('payment_status', 'paid');

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

            return [
                'product' => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'price' => $product->price,
                    'stock' => $product->stock,
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
                    'total_incoming' => $totalIncoming, // From purchases/restocks
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

        // Summary statistics for all products in the report
        $summary = [
            'total_products' => $products->count(),
            'total_stock' => $products->sum('stock'),
            'total_revenue' => $reportData->sum('sales_summary.total_revenue'),
            'total_items_sold' => $reportData->sum('sales_summary.total_quantity_sold'),
        ];

        return response()->json([
            'data' => $reportData,
            'summary' => $summary,
            'filters' => $request->all(),
        ]);
    }

    /**
     * Detailed sales report by product
     */
    public function productDetailedReport(Request $request)
    {
        $query = Product::query()
            ->with(['categories', 'variants'])
            ->withCount([
                'orderItems as total_sold' => function ($q) use ($request) {
                    $q->whereHas('order', function ($q2) use ($request) {
                        $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                            ->orWhere('payment_status', 'paid');

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
                'orderItems as total_revenue' => function ($q) use ($request) {
                    $q->whereHas('order', function ($q2) use ($request) {
                        $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                            ->orWhere('payment_status', 'paid');

                        if ($request->filled('date_from')) {
                            $q2->whereDate('created_at', '>=', $request->date_from);
                        }
                        if ($request->filled('date_to')) {
                            $q2->whereDate('created_at', '<=', $request->date_to);
                        }
                    });
                }
            ], 'price');

        // Apply filters
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
            $query->where('title', 'like', "%{$request->search}%")
                ->orWhere('sku', 'like', "%{$request->search}%");
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

        if ($request->filled('sort_by')) {
            $sortField = $request->sort_by;
            $sortOrder = $request->sort_order ?? 'desc';

            if (in_array($sortField, ['total_sold', 'total_revenue', 'price', 'stock'])) {
                $query->orderBy($sortField, $sortOrder);
            } else {
                $query->orderBy($sortField, $sortOrder);
            }
        } else {
            $query->orderBy('total_sold', 'desc');
        }

        $products = $query->paginate($request->per_page ?? 20);

        return response()->json($products);
    }

    /**
     * User purchase report - how much each person bought in a date range
     */
    public function userPurchaseReport(Request $request)
    {
        $query = User::query()
            ->with(['addresses', 'roles', 'wallet']);

        // Filters
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

        // Get users with their purchase statistics
        $users = $query->get();

        $reportData = $users->map(function ($user) use ($request) {
            $ordersQuery = Order::where('user_id', $user->id)
                ->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                ->orWhere('payment_status', 'paid');

            if ($request->filled('date_from')) {
                $ordersQuery->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $ordersQuery->whereDate('created_at', '<=', $request->date_to);
            }

            $orders = $ordersQuery->with(['items.product'])->get();

            $purchaseData = [
                'total_orders' => $orders->count(),
                'total_items' => $orders->sum(function ($order) {
                    return $order->items->sum('quantity');
                }),
                'total_spent' => $orders->sum('total'),
                'average_order_value' => $orders->count() > 0 ? round($orders->sum('total') / $orders->count()) : 0,
                'first_purchase' => $orders->min('created_at'),
                'last_purchase' => $orders->max('created_at'),
                'orders' => $orders->map(function ($order) {
                    return [
                        'order_id' => $order->id,
                        'total' => $order->total,
                        'items' => $order->items->map(function ($item) {
                            return [
                                'product' => $item->product->title ?? 'محصول حذف شده',
                                'quantity' => $item->quantity,
                                'price' => $item->price,
                            ];
                        }),
                        'created_at' => $order->created_at->format('Y-m-d H:i'),
                    ];
                }),
            ];

            // Top purchased products by this user
            $topProducts = OrderItem::whereHas('order', function ($q) use ($user, $request) {
                $q->where('user_id', $user->id)
                    ->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                    ->orWhere('payment_status', 'paid');

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
                    DB::raw('SUM(order_items.price * order_items.quantity) as total_spent')
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
                    'roles' => $user->roles->pluck('name'),
                    'wallet_balance' => $user->wallet?->balance ?? 0,
                ],
                'purchase_summary' => $purchaseData,
                'top_products' => $topProducts,
            ];
        });

        // Summary statistics
        $summary = [
            'total_users' => $users->count(),
            'total_orders' => $reportData->sum('purchase_summary.total_orders'),
            'total_spent' => $reportData->sum('purchase_summary.total_spent'),
            'total_items' => $reportData->sum('purchase_summary.total_items'),
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
            return response()->json(['error' => 'Product ID is required'], 400);
        }

        $product = Product::with(['categories', 'variants'])->find($productId);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        // Get all order items for this product
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

        // Group by date for chart
        $dailyMovements = OrderItem::where('product_id', $productId)
            ->whereHas('order', function ($q) use ($request) {
                $q->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                    ->orWhere('payment_status', 'paid');

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

        // Calculate running stock balance
        $runningStock = [];
        $currentStock = $product->stock;

        // Since we don't have purchase entries, we'll use current stock and subtract sales
        // You should add a purchase/inventory table for accurate entry tracking

        $report = [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'current_stock' => $product->stock,
                'price' => $product->price,
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
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->price * $item->quantity,
                    'variant' => $item->variant ? $item->variant->values->map(function ($value) {
                        return $value->attribute->name . ': ' . $value->value;
                    })->join(' - ') : null,
                    'order_status' => $item->order->status,
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
                // 'total_incoming' => 0, // Add when you have purchase model
            ],
        ];

        return response()->json($report);
    }

    /**
     * Dashboard summary with charts
     */
    public function dashboardReport(Request $request)
    {
        // Sales overview
        $salesQuery = Order::query()
            ->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
            ->orWhere('payment_status', 'paid');

        if ($request->filled('date_from')) {
            $salesQuery->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $salesQuery->whereDate('created_at', '<=', $request->date_to);
        }

        // Daily sales chart data
        $dailySales = Order::whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
            ->orWhere('payment_status', 'paid')
            ->when($request->filled('date_from'), function ($q) use ($request) {
                return $q->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                return $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('COUNT(*) as total_orders')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top selling products
        $topProducts = OrderItem::whereHas('order', function ($q) use ($request) {
            $q->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                ->orWhere('payment_status', 'paid');

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
                DB::raw('SUM(order_items.price * order_items.quantity) as total_revenue')
            )
            ->groupBy('products.id', 'products.title')
            ->orderBy('total_revenue', 'desc')
            ->limit(10)
            ->get();

        // Sales by payment method
        $paymentMethods = Order::whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
            ->orWhere('payment_status', 'paid')
            ->when($request->filled('date_from'), function ($q) use ($request) {
                return $q->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                return $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->select(
                'payment_method',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(total) as total_amount')
            )
            ->groupBy('payment_method')
            ->get();

        // Sales by status
        $ordersByStatus = Order::when($request->filled('date_from'), function ($q) use ($request) {
            return $q->whereDate('created_at', '>=', $request->date_from);
        })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                return $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        // Summary statistics
        $summary = [
            'total_sales' => $salesQuery->sum('total'),
            'total_orders' => $salesQuery->count(),
            'average_order_value' => $salesQuery->count() > 0
                ? round($salesQuery->sum('total') / $salesQuery->count())
                : 0,
            'total_customers' => $salesQuery->distinct('user_id')->count('user_id'),
        ];

        return response()->json([
            'summary' => $summary,
            'daily_sales' => $dailySales,
            'top_products' => $topProducts,
            'payment_methods' => $paymentMethods,
            'orders_by_status' => $ordersByStatus,
        ]);
    }
}
