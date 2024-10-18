<?php

namespace App\Http\Controllers;

use App\Constants\Status;
use App\Lib\CurlRequest;
use App\Lib\TradeManager;
use App\Models\AdminNotification;
use App\Models\CronJob;
use App\Models\CronJobLog;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\Carbon;
use Exception;

class CronController extends Controller
{
    public function cron()
    {
        $general            = gs();
        $general->last_cron = now();
        $general->save();

        $crons = CronJob::with('schedule');

        if (request()->alias) {
            $crons->where('alias', request()->alias);
        } else {
            $crons->where('next_run', '<', now())->where('is_running', Status::YES);
        }
        $crons = $crons->get();
        foreach ($crons as $cron) {
            $cronLog              = new CronJobLog();
            $cronLog->cron_job_id = $cron->id;
            $cronLog->start_at    = now();
            if ($cron->is_default) {
                $controller = new $cron->action[0];
                try {
                    $method = $cron->action[1];
                    $controller->$method();
                } catch (\Exception $e) {
                    $cronLog->error = $e->getMessage();
                }
            } else {
                try {
                    CurlRequest::curlContent($cron->url);
                } catch (\Exception $e) {
                    $cronLog->error = $e->getMessage();
                }
            }
            $cron->last_run = now();
            $cron->next_run = now()->addSeconds((int)$cron->schedule->interval);
            $cron->save();

            $cronLog->end_at = $cron->last_run;

            $startTime         = Carbon::parse($cronLog->start_at);
            $endTime           = Carbon::parse($cronLog->end_at);
            $diffInSeconds     = $startTime->diffInSeconds($endTime);
            $cronLog->duration = $diffInSeconds;
            $cronLog->save();
        }
        if (request()->target == 'all') {
            $notify[] = ['success', 'Cron executed successfully'];
            return back()->withNotify($notify);
        }
        if (request()->alias) {
            $notify[] = ['success', keyToTitle(request()->alias) . ' executed successfully'];
            return back()->withNotify($notify);
        }
    }

    public function crypto()
    {
        try {
            return defaultCurrencyDataProvider()->updateCryptoPrice();
        } catch (Exception $ex) {
            throw new \Exception($ex->getMessage());
        }
    }

    public function market()
    {
        try {
            return defaultCurrencyDataProvider()->updateMarkets();
        } catch (Exception $ex) {
            throw new \Exception($ex->getMessage());
        }
    }

    public function trade()
    {
        try {
            $trade = new TradeManager();
            return $trade->trade();
        } catch (Exception $ex) {
            throw new \Exception($ex->getMessage());
        }
    }
    public function stopLimitOrder()
    {
        try {
            $orders = Order::where('is_draft', Status::YES)->where('status', Status::ORDER_PENDING)->get();
            foreach ($orders as $order) {
                $pair = $order->pair;
                if (!$pair) continue;
                $amount      = @$order->amount;
                $rate        = @$order->rate;
                $marketPrice = @$pair->marketData->price;
                if ($marketPrice <= 0 || $rate <= 0 || $amount <= 0) continue;
                $totalAmount    = $amount * $rate;
                $coin           = @$pair->coin;
                $marketCurrency = @$pair->market->currency;
                $user           = @$order->user;

                if (!$user || !$marketCurrency || !$coin) continue;

                if ($order->order_side ==  Status::BUY_SIDE_ORDER) {
                    if ($marketPrice >= $order->stop_rate) {
                        $userMarketCurrencyWallet = Wallet::where('user_id', $user->id)->where('currency_id', $marketCurrency->id)->spot()->first();
                        $charge                   = ($totalAmount / 100) * $pair->percent_charge_for_buy;
                        if (($charge + $totalAmount) > $userMarketCurrencyWallet->balance) continue;
                        $orderSide = "Buy";
                    } else {
                        continue;
                    }
                }

                if ($order->order_side ==  Status::SELL_SIDE_ORDER) {
                    if ($marketPrice <= $order->stop_rate) {
                        $userCoinWallet = Wallet::where('user_id', $user->id)->where('currency_id', $coin->id)->spot()->first();
                        $charge         = ($totalAmount / 100) * $pair->percent_charge_for_sell;
                        if ($order->amount > $userCoinWallet->balance) continue;
                        $orderSide = "Sell";
                    } else {
                        continue;
                    }
                }

                $order->is_draft   = Status::NO;
                $order->status     = Status::ORDER_OPEN;
                $order->order_type = Status::ORDER_TYPE_LIMIT;
                $order->save();

                if ($order->order_side ==  Status::BUY_SIDE_ORDER) {
                    $details = "Open order for buy coin on " . $pair->symbol . " pair. [From stop limit order]";
                    $this->createTrx($userMarketCurrencyWallet, 'order_buy', $totalAmount, $charge, $details, $user);
                } else {
                    $details = "Open order for sell coin on " . $pair->symbol . " pair. [From stop limit order]";
                    $this->createTrx($userCoinWallet, 'order_sell', $amount, 0, $details, $user);
                }

                $adminNotification            = new AdminNotification();
                $adminNotification->user_id   = $user->id;
                $adminNotification->title     = $user->username . $details;
                $adminNotification->click_url = urlPath('admin.order.history');
                $adminNotification->save();

                notify($user, 'ORDER_OPEN', [
                    'pair'                   => $pair->symbol,
                    'amount'                 => showAmount($order->amount, currencyFormat: false),
                    'total'                  => showAmount($order->total, currencyFormat: false),
                    'rate'                   => showAmount($order->rate, currencyFormat: false),
                    'price'                  => showAmount($order->price, currencyFormat: false),
                    'coin_symbol'            => @$coin->symbol,
                    'order_side'             => $orderSide,
                    'market_currency_symbol' => @$marketCurrency->symbol,
                    'market'                 => $pair->market->name
                ]);
            }
        } catch (Exception $ex) {
            throw new \Exception($ex->getMessage());
        }
    }

    private function createTrx($wallet, $remark, $amount, $charge, $details, $user, $type = "-")
    {
        if ($type == '-') {
            $wallet->balance -= $amount;
        } else {
            $wallet->balance += $amount;
        }

        $wallet->save();

        $transaction               = new Transaction();
        $transaction->user_id      = $user->id;
        $transaction->wallet_id    = $wallet->id;
        $transaction->amount       = $amount;
        $transaction->post_balance = $wallet->balance;
        $transaction->charge       = 0;
        $transaction->trx_type     = $type;
        $transaction->details      = $details;
        $transaction->trx          = getTrx();
        $transaction->remark       = $remark;
        $transaction->save();

        if (getAmount($charge) <= 0)  return $wallet->balance;

        if ($type == '-') {
            $wallet->balance -= $charge;
        } else {
            $wallet->balance += $charge;
        }

        $wallet->save();

        $transaction               = new Transaction();
        $transaction->user_id      = $user->id;
        $transaction->wallet_id    = $wallet->id;
        $transaction->amount       = $charge;
        $transaction->post_balance = $wallet->balance;
        $transaction->charge       = 0;
        $transaction->trx_type     = $type;
        $transaction->details      = "Charge for " . $details;
        $transaction->trx          = getTrx();
        $transaction->remark       = "charge_" . $remark;
        $transaction->save();

        return $wallet->balance;
    }
}
