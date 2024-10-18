<?php

namespace App\Http\Controllers\Api;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Deposit;
use App\Models\GatewayCurrency;
use App\Models\Order;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Models\WithdrawMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    public function list($type = null)
    {
        $types = (array) gs('wallet_types');
        if (!array_key_exists($type, $types)) {
            $notify[] = 'Invalid URL';
            return response()->json([
                'remark'  => 'invalid_url',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        list('type_value' => $typeValue) = (array) $types[$type];

        $wallets = $this->walletQuery()->groupBy('wallets.id')->where('wallets.wallet_type', $typeValue)->orderBy('balance', 'desc')->apiQuery();

        $estimatedBalance = Wallet::where('user_id', auth()->id())->join('currencies', 'wallets.currency_id', 'currencies.id');
        $type == 'spot' ? $estimatedBalance->spot() : $estimatedBalance->funding();
        $estimatedBalance = $estimatedBalance->sum(DB::raw('currencies.rate * wallets.balance'));

        $notify[] = 'Wallet list';
        return response()->json([
            'remark'  => 'wallet_list',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'wallets'           => $wallets,
                'estimated_balance' => $estimatedBalance,
            ],
        ]);
    }

    public function view($type, $curSymbol)
    {
        $types = (array) gs('wallet_types');

        if (!array_key_exists($type, $types)) {
            $notify[] = 'Invalid URL';
            return response()->json([
                'remark'  => 'invalid_url',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        list('name' => $walletType) = (array) $types[$type];

        $currency = Currency::where('symbol', $curSymbol)->first();
        if (!$currency) {
            $notify[] = 'Currency not found';
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $wallet = $this->walletQuery()->where('wallets.currency_id', $currency->id)->$type()->first();

        if (!$wallet) {
            $notify[] = 'Wallet not found';
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $user = auth()->user();

        $trxQuery     = Transaction::where('wallet_id', $wallet->id)->with('wallet.currency');
        $transactions = (clone $trxQuery)->latest('id')->apiQuery();
        $orderQuery   = Order::where('user_id', $user->id);
        $orderQuery   = currencyWiseOrderQuery($orderQuery, $currency);

        $widget['total_order']     = (clone $orderQuery)->count();
        $widget['open_order']      = (clone $orderQuery)->open()->count();
        $widget['completed_order'] = (clone $orderQuery)->completed()->count();
        $widget['canceled_order']  = (clone $orderQuery)->canceled()->count();

        $widget['total_deposit']     = Deposit::successful()->where('wallet_id', $wallet->id)->sum('amount');
        $widget['total_withdraw']    = Withdrawal::approved()->where('wallet_id', $wallet->id)->sum('amount');
        $widget['total_transaction'] = (clone $trxQuery)->count();

        $gateways = GatewayCurrency::where('currency', $curSymbol)->whereHas('method', function ($gate) {
            $gate->active();
        })->with('method')->get();

        $withdrawMethods       = WithdrawMethod::active()->where('currency', $curSymbol)->get();
        $widget['total_trade'] = Trade::where('trader_id', $user->id)->whereHas('order', function ($q) use ($currency) {
            $q = currencyWiseOrderQuery($q, $currency);
        })->count();

        $notify[] = 'Wallet View';
        return response()->json([
            'remark'  => 'wallet_view',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'wallet'           => $wallet,
                'widget'           => $widget,
                'transactions'     => $transactions,
                'gateways'         => $gateways,
                'withdraw_methods' => $withdrawMethods,
                'currency'         => $currency,
                'wallet_type'      => $walletType,
            ],
        ]);
    }

    public function transfer(Request $request)
    {
        $walletTypes = gs('wallet_types');

        $validator = Validator::make($request->all(), [
            'transfer_amount' => 'required|numeric|gte:0',
            'username'        => 'required',
            'currency'        => 'required',
            'wallet_type'     => 'required|in:' . implode(',', array_keys((array) $walletTypes)),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $validator->errors()->all()],
            ]);
        }

        $from = auth()->user();
        $to   = User::active()->where('username', $request->username)->first();
        if (!$to) {
            $notify[] = 'Receiver not found';
            return response()->json([
                'remark'  => 'receiver_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }
        $currency = Currency::active()->where('id', $request->currency)->first();

        if (!$currency) {
            $notify[] = 'Currency not found';
            return response()->json([
                'remark'  => 'currency_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $getAmount = getAmount($request->transfer_amount);

        if ($to->id == $from->id) {
            $notify[] = 'You can\'t transfer to your own wallet';
            return response()->json([
                'remark'  => 'eligible_transfer_own_account',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $walletType = $request->wallet_type;

        if (!checkWalletConfiguration($walletType, 'transfer_other_user', $walletTypes)) {
            $notify[] = "Transfer to $walletType wallet currently disabled.";
            return response()->json([
                'remark'  => 'transfer_currently_disabled',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $fromWallet = Wallet::where('user_id', $from->id)->where('currency_id', $currency->id)->$walletType()->first();

        if (!$fromWallet) {
            $notify[] = 'Receiver wallet not found';
            return response()->json([
                'remark'  => 'receiver_wallet_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $toWallet = Wallet::where('user_id', $to->id)->where('currency_id', $currency->id)->$walletType()->first();

        if (!$toWallet) {
            $notify[] = 'Sender wallet not found';
            return response()->json([
                'remark'  => 'sender_wallet_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $amount        = $request->transfer_amount;
        $chargePercent = gs('other_user_transfer_charge');
        $chargeAmount  = ($amount / 100) * $chargePercent;
        $totalAmount   = $amount + $chargeAmount;

        if ($totalAmount > $fromWallet->balance) {
            $notify[] = 'You do not have sufficient balance for transfer.';
            return response()->json([
                'remark'  => 'insufficient_balance',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $trx     = getTrx();
        $details = "transfer $getAmount $currency->symbol to $to->username";
        $transaction = $this->createTransferTrx($trx, $from, $fromWallet, $amount, "-", $details);

        notify($from, 'TRANSFER_MONEY', [
            'amount'      => showAmount($amount,currencyFormat:false),
            'charge'      => showAmount($chargeAmount,currencyFormat:false),
            'trx'         => $trx,
            'currency'    => @$currency->symbol,
            'to_username' => $to->username,
        ]);

        $details     = "charge for transfer $getAmount $currency->symbol to $to->username";
        $this->createTransferTrx($trx, $from, $fromWallet, $chargeAmount, "-", $details);
        $this->createTransferTrx($trx, $to, $toWallet, $amount, "+", "received $getAmount $currency->symbol from  $from->username");

        notify($to, 'RECEIVED_MONEY', [
            'amount'        => showAmount($amount,currencyFormat:false),
            'charge'        => showAmount($chargeAmount,currencyFormat:false),
            'trx'           => $trx,
            'currency'      => @$currency->symbol,
            'from_username' => $from->username,
        ]);

        $notify[] = "$getAmount $currency->symbol transfer successfully";
        return response()->json([
            'remark'  => 'transfer_succeed',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'transaction'   => $transaction,
                'to_wallet'     => $toWallet,
                'amount'        => $amount,
                'charge_amount' => $chargeAmount,
            ],
        ]);
    }

    public function transferToWallet(Request $request)
    {
        $walletTypes         = gs('wallet_types');
        $walletTypesToString = implode(',', array_keys((array) $walletTypes));

        $validator = Validator::make($request->all(), [
            'transfer_amount' => 'required|numeric|gte:0',
            'currency'        => 'required',
            'from_wallet'     => 'required|in:' . $walletTypesToString,
            'to_wallet'       => 'required|different:from_wallet|in:' . $walletTypesToString,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $validator->errors()->all()],
            ]);
        }

        $fromWalletType = $request->from_wallet;
        $toWalletType   = $request->to_wallet;
        $user           = auth()->user();

        $currency = Currency::where('id', $request->currency)->active()->first();

        if (!$currency) {
            $notify[] = 'Currency not found';
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $fromWallet = Wallet::where('user_id', $user->id)->where('currency_id', $currency->id)->$fromWalletType()->first();
        if (!$fromWallet) {
            $notify[] = 'Receiver wallet not found';
            return response()->json([
                'remark'  => 'receiver_wallet_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $toWallet = Wallet::where('user_id', $user->id)->where('currency_id', $currency->id)->$toWalletType()->first();

        if (!$toWallet) {
            $notify[] = 'Sender wallet not found';
            return response()->json([
                'remark'  => 'sender_wallet_not_found',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        if (!checkWalletConfiguration($toWalletType, 'transfer_other_wallet', $walletTypes)) {
            $notify[] = "Transfer to $toWalletType wallet currently disabled.";
            return response()->json([
                'remark'  => 'transfer_currently_disabled',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);

        }

        $amount = $request->transfer_amount;
        if ($amount > $fromWallet->balance) {
            $notify[] = 'You do not have sufficient balance for transfer.';
            return response()->json([
                'remark'  => 'insufficient_balance',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $trx        = getTrx();
        $detailsOne = "Transfer " . getAmount($amount) . " " . $currency->symbol . " from the " . ucfirst($fromWalletType) . " wallet to the " . ucfirst($toWalletType) . 'Wallet';
        $detailsTwo = "Received " . getAmount($amount) . " " . $currency->symbol . " from the " . ucfirst($fromWalletType) . " Wallet";

        $transaction = $this->createTransferTrx($trx, $user, $fromWallet, $amount, "-", $detailsOne);
        $this->createTransferTrx($trx, $user, $toWallet, $amount, "+", $detailsTwo);

        $notify[] = 'Transferred successfully';
        return response()->json([
            'remark'  => 'transfer_succeed',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'transaction' => $transaction,
                'to_wallet'   => $toWallet,
                'amount'      => $amount,
            ],
        ]);
    }

    private function createTransferTrx($trx, $user, $wallet, $amount, $type, $details)
    {
        if ($type == '+') {
            $wallet->balance += $amount;
        } else {
            $wallet->balance -= $amount;
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
        $transaction->trx          = $trx;
        $transaction->remark       = 'transfer';
        $transaction->save();
        return $transaction;
    }

    private function walletQuery()
    {
        return Wallet::with('currency')
            ->where('wallets.user_id', auth()->id())
            ->select('wallets.*')
            ->leftJoin('orders', function ($join) {
                $join->on('wallets.currency_id', '=', \DB::raw('CASE WHEN orders.order_side = ' . Status::BUY_SIDE_ORDER . ' THEN orders.market_currency_id ELSE orders.coin_id END'))
                    ->where('orders.user_id', auth()->id())->where('orders.Status', Status::ORDER_OPEN);
            })
            ->selectRaw('CASE WHEN wallets.wallet_type =  ' . Status::WALLET_TYPE_FUNDING . ' THEN 0 ELSE SUM(CASE WHEN orders.order_side = ? THEN ((orders.amount-orders.filled_amount)*orders.rate) ELSE (orders.amount-orders.filled_amount) END) END as in_order', [Status::BUY_SIDE_ORDER]);
    }
}
