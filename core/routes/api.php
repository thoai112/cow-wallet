<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::namespace('Api')->name('api.')->group(function () {
    
    Route::namespace('Auth')->group(function () {
        Route::controller('LoginController')->group(function () {
            Route::post('login', 'login');
            Route::post('check-token', 'checkToken');
            Route::post('social-login', 'socialLogin');
        });

        Route::namespace('Web3')->prefix('web3')->name('web3.')->group(function () {
            Route::controller("MetamaskController")->prefix('metamask-login')->group(function () {
                Route::any('message', 'message');
                Route::post('verify', 'verify');
            });
        });

        Route::post('register', 'RegisterController@register');

        Route::controller('ForgotPasswordController')->group(function () {
            Route::post('password/email', 'sendResetCodeEmail');
            Route::post('password/verify-code', 'verifyCode');
            Route::post('password/reset', 'reset');
        });
    });


    Route::controller('AppController')->group(function () {
        Route::get('general-setting', 'generalSetting');
        Route::get('get-countries', 'getCountries');
        Route::get('onboarding', 'onboarding');
        Route::get('language/{code}', 'language');
        Route::get('blogs', 'blogs');
        Route::get('blog/details/{id}', 'blogDetails');
        Route::get('faqs', 'faqs');
        Route::get('policy-pages', 'policyPages');

        Route::get('market-overview', 'marketOverview');
        Route::get('market-list', 'marketList');
        Route::get('crypto-list', 'cryptoList');
        Route::get('currencies', 'currencies');
    });

    Route::controller("TradeController")->prefix('trade')->group(function () {
        Route::get('order/book/{symbol?}', 'orderBook')->name('trade.order.book');
        Route::get('pairs', 'pairs')->name('trade.pairs');
        Route::get('pair/add-to-favorite', 'addToFavorite');
        Route::get('history/{symbol}', 'history')->name('trade.history');
        Route::get('order/list/{symbol?}', 'orderList')->name('trade.order.list');
        Route::get('currency', 'currency');
        Route::get('{symbol?}', 'trade')->name('trade');
    });


    Route::middleware('auth:sanctum')->group(function () {

        Route::post('user-data-submit', 'UserController@userDataSubmit');

        //authorization
        Route::middleware('registration.complete')->controller('AuthorizationController')->group(function () {
            Route::get('authorization', 'authorization');
            Route::get('resend-verify/{type}', 'sendVerifyCode');
            Route::post('verify-email', 'emailVerification');
            Route::post('verify-mobile', 'mobileVerification');
            Route::post('verify-g2fa', 'g2faVerification');
        });

        Route::middleware(['check.status'])->group(function () {

            Route::get('user-info', 'UserController@userInfo');
            
            Route::middleware('registration.complete')->group(function () {

                Route::controller('UserController')->group(function () {

                    Route::get('dashboard', 'dashboard');

                    Route::post('profile-setting', 'submitProfile');
                    Route::post('change-password', 'submitPassword');

                    //KYC
                    Route::get('kyc-form', 'kycForm');
                    Route::post('kyc-submit', 'kycSubmit');

                    //Report
                    Route::any('deposit/history', 'depositHistory');
                    Route::get('transactions', 'transactions');

                    Route::get('referrals', 'referrals');

                    Route::post('add-device-token', 'addDeviceToken');
                    Route::get('push-notifications', 'pushNotifications');
                    Route::post('push-notifications/read/{id}', 'pushNotificationsRead');

                    //2FA
                    Route::get('twofactor', 'show2faForm');
                    Route::post('twofactor/enable', 'create2fa');
                    Route::post('twofactor/disable', 'disable2fa');

                    Route::post('delete-account', 'deleteAccount');

                    Route::post('validate/password', 'validatePassword');
                    Route::get('pair/add/to/favorite/{pairSym}', 'addToFavorite')->name('add.pair.to.favorite');

                    Route::get('notifications', 'notifications');
                });

                Route::controller('OrderController')->group(function () {
                    Route::prefix('order')->group(function () {
                        Route::get('open', 'open');
                        Route::get('completed', 'completed');
                        Route::get('canceled', 'canceled');
                        Route::post('cancel/{id}', 'cancel');
                        Route::post('update/{id}', 'update');
                        Route::get('history', 'history');
                        Route::post('save/{symbol}', 'save')->name('save');
                    });
                    Route::get('trade-history', 'tradeHistory')->name('trade.history');
                });

                //wallet
                Route::controller('WalletController')->name('wallet.')->prefix('wallet')->group(function () {
                    Route::get('list/{type?}', 'list')->name('list');
                    Route::post('transfer', 'transfer')->name('transfer');
                    Route::post('transfer/to/wallet', 'transferToWallet')->name('transfer.to.other.wallet');
                    Route::get('{type}/{currencySymbol}', 'view')->name('view');
                });

                // Withdraw
                Route::controller('WithdrawController')->group(function () {
                    Route::middleware('kyc')->group(function () {
                        Route::get('withdraw-method', 'withdrawMethod');
                        Route::post('withdraw-request', 'withdrawStore');
                        Route::post('withdraw-request/confirm', 'withdrawSubmit');
                    });
                    Route::get('withdraw/history', 'withdrawLog');
                });

                // Payment
                Route::controller('PaymentController')->group(function () {
                    Route::get('deposit/methods', 'methods');
                    Route::post('deposit/insert', 'depositInsert');
                });

                Route::controller('TicketController')->prefix('ticket')->group(function () {
                    Route::get('/', 'supportTicket');
                    Route::post('create', 'storeSupportTicket');
                    Route::get('view/{ticket}', 'viewTicket');
                    Route::post('reply/{id}', 'replyTicket');
                    Route::post('close/{id}', 'closeTicket');
                    Route::get('download/{attachment_id}', 'ticketDownload');
                });
            });
        });

        Route::get('logout', 'Auth\LoginController@logout');
    });
});
