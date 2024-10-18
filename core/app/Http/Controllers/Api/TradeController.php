<?php

namespace App\Http\Controllers\Api;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\CoinPair;
use App\Models\FavoritePair;
use App\Models\GatewayCurrency;
use App\Models\Market;
use App\Models\Order;
use App\Models\Trade;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TradeController extends Controller
{
    public function trade($symbol = null)
    {
        $pair = CoinPair::active()->activeMarket()->activeCoin()->with('market', 'coin', 'marketData');

        if ($symbol) {
            $pair = $pair->where('symbol', $symbol)->first();
        } else {
            $pair = $pair->where('is_default', Status::YES)->first();
        }

        if (!$pair) {
            $notify[] = 'No pair found';
            return response()->json([
                'remark'  => 'pair_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $markets              = Market::with('currency:id,name,symbol')->active()->get();
        $userId               = auth()->guard('sanctum')->id() ?? 0;
        $coinWallet           = Wallet::with('currency')->where('user_id', $userId)->where('currency_id', $pair->coin->id)->spot()->first();
        $marketCurrencyWallet = Wallet::with('currency')->where('user_id', $userId)->where('currency_id', $pair->market->currency->id)->spot()->first();
        $gateways             = GatewayCurrency::where(function ($q) use ($pair) {
            $q->where('currency', @$pair->coin->symbol)->orWhere('currency', $pair->market->currency->symbol);
        })->whereHas('method', function ($gate) {
            $gate->where('status', Status::ENABLE);
        })->with('method:id,code,crypto')->get();
        $isFavorite = FavoritePair::where('pair_id', $pair->id)->where('user_id', auth('sanctum')->id())->exists();

        $notify[] = 'Trade Page';
        return response()->json([
            'remark'  => 'trade_page',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'pair'                   => $pair,
                'is_favorite'            => $isFavorite,
                'markets'                => $markets,
                'coin_wallet'            => $coinWallet,
                'market_currency_wallet' => $marketCurrencyWallet,
                'gateways'               => $gateways,
            ],
        ]);
    }

    public function history($symbol)
    {
        $pair = $this->findPair($symbol);

        if (!$pair) {
            $notify[] = 'Coin Pair not found';
            return response()->json([
                'remark'  => 'coin_pair_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $trades = Trade::where('pair_id', $pair->id)->orderBy('id', 'desc')->take(50)->get();

        $notify[] = 'Trade History';
        return response()->json([
            'remark'  => 'trade_history',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'trades' => $trades,
            ],
        ]);
    }

    public function orderList(Request $request, $symbol = null)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:all,open,canceled,completed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $validator->errors()->all()],
            ]);
        }

        $query = Order::with('pair')->where('user_id', auth()->guard('sanctum')->id());

        if ($request->status && $request->status != 'all') {
            $scope = $request->status;
            $query->$scope();
        }

        if ($request->symbol) {
            $query->whereHas('pair', function ($pair) use ($request) {
                $pair->where('symbol', $request->symbol);
            });
        }

        $orders = $query->orderBy('id', 'desc')->apiQuery();

        $notify[] = 'Order list';
        return response()->json([
            'remark'  => 'order_list',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'orders' => $orders,
            ],
        ]);
    }

    public function orderBook($symbol = null)
    {
        $pair = $this->findPair($symbol);

        if (!$pair) {
            $notify[] = 'Coin Pair not found';
            return response()->json([
                'remark'  => 'coin_pair_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $query = Order::open()->where('orders.pair_id', $pair->id)
            ->select('orders.*')
            ->leftJoin('trades', 'orders.id', 'trades.order_id')
            ->selectRaw("SUM(orders.amount) as total_amount")
            ->selectRaw("COUNT(DISTINCT orders.id) as total_order")
            ->selectRaw("COUNT(DISTINCT trades.id) as total_trade")
            ->selectRaw('MAX(CASE WHEN orders.user_id = ? THEN 1 ELSE 0 END)  AS has_my_order', [auth()->guard('sanctum')->id()])
            ->groupBy('orders.rate')
            ->orderBy('orders.rate', 'DESC');

        $sellSideOrders = (clone $query)->sellSideOrder()->take(15)->get();
        $buySideOrders  = (clone $query)->buySideOrder()->take(15)->get();

        $notify[] = 'Order Book';
        return response()->json([
            'remark'  => 'order_book',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'sell_side_orders' => @$sellSideOrders ?? [],
                'buy_side_orders'  => @$buySideOrders ?? [],
            ],
        ]);
    }

    private function findPair($symbol = null)
    {
        $pair = CoinPair::active()->activeMarket()->activeCoin();
        if ($symbol) {
            $pair = $pair->where('symbol', $symbol)->first();
        } else {
            $pair = $pair->where('is_default', Status::YES)->first();
        }
        return $pair;
    }

    public function pairs()
    {
        $query = CoinPair::activeMarket()->activeCoin()->with('coin:name,id,symbol', 'market:id,name,currency_id', 'market.currency:id,symbol', 'marketData');

        if (request()->market_id) {
            $query->where('market_id', request()->market_id);
        }

        if (request()->search) {
            $query->where('symbol', 'Like', "%" . request()->search . "%");
        }

        $pairs          = $query->apiQuery();
        $favoritePairId = FavoritePair::where('user_id', auth()->guard('sanctum')->id() ?? 0)->pluck('pair_id')->toArray();

        $notify[] = 'Pairs';
        return response()->json([
            'remark'  => 'pairs',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'pairs'          => $pairs,
                'favoritePairId' => $favoritePairId,
            ],
        ]);

    }

    public function currency()
    {
        $markets = Market::with('currency:id,name,symbol')->active()->get();

        $notify[] = 'Currencies';
        return response()->json([
            'remark'  => 'currencies',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'markets' => $markets,
            ],
        ]);

    }

}
