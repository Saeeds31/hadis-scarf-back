<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Orders\Models\Order;

// use Modules\Wallet\Database\Factories\WalletTransactionFactory;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'description',
        'order_id',
        'balance_after',
        'gateway_transaction_id'
    ];
    protected $casts = [
        'amount' => 'integer',
    ];
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function gatewayTransaction()
    {
        return $this->belongsTo(GatewayTransaction::class);
    }
}
