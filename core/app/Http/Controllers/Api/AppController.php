<?php

namespace App\Http\Controllers\Api;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\CoinPair;
use App\Models\Currency;
use App\Models\Frontend;
use App\Models\Language;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AppController extends Controller
{
    public function generalSetting()
    {

        if (request()->header('custom_string') != 'vinance*123') {
            $notify[] = 'Invalid String';
            return response()->json([
                'remark'  => 'invalid_string',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $notify[] = 'General setting data';

        return response()->json([
            'remark'  => 'general_setting',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'general_setting'       => gs(),
                'social_login_redirect' => route('user.social.login.callback', '')
            ],
        ]);
    }

    public function getCountries()
    {
        $countryData = json_decode(file_get_contents(resource_path('views/partials/country.json')));
        $notify[] = 'Country List';
        foreach ($countryData as $k => $country) {
            $countries[] = [
                'country' => $country->country,
                'dial_code' => $country->dial_code,
                'country_code' => $k,
            ];
        }
        return response()->json([
            'remark' => 'country_data',
            'status' => 'success',
            'message' => ['success' => $notify],
            'data' => [
                'countries' => $countries,
            ],
        ]);
    }

    public function language($code)
    {
        $languages     = Language::get();
        $languageCodes = $languages->pluck('code')->toArray();

        if (!in_array($code, $languageCodes)) {
            $notify[] = 'Invalid code given';
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $notify]
            ]);
        }

        $jsonFile = file_get_contents(resource_path('lang/' . $code . '.json'));

        $notify[] = 'Language';
        return response()->json([
            'remark'  => 'language',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'languages'  => $languages,
                'file'       => json_decode($jsonFile) ?? [],
                'image_path' => getFilePath('language')
            ],
        ]);
    }

    public function onboarding()
    {
        $onboardings = Frontend::where('data_keys', 'app_onboarding.element')->get();
        $path        = 'assets/images/frontend/app_onboarding';

        $notify[] = 'Onboarding screen';
        return response()->json([
            'remark'  => 'onboarding_screen',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'onboardings' => $onboardings,
                'path'        => $path
            ],
        ]);
    }

    public function blogs()
    {
        $blogs = Frontend::where('data_keys', 'blog.element')->apiQuery();
        $path  = 'assets/images/frontend/blog';

        $notify[] = 'Blogs';
        return response()->json([
            'remark'  => 'blogs',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'blogs' => $blogs,
                'path'  => $path
            ]
        ]);
    }


    public function blogDetails($id)
    {
        $blog = Frontend::where('data_keys', 'blog.element')->find($id);
        $path = 'assets/images/frontend/blog';


        if (!$blog) {
            $notify[] = 'Blog not found';
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $notify[] = 'Blog Details';
        return response()->json([
            'remark'  => 'blog_details',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'blog' => $blog,
                'path' => $path
            ]
        ]);
    }


    public function faqs()
    {
        $faqs = Frontend::where('data_keys', 'faq.element')->apiQuery();

        $notify[] = 'FAQs';
        return response()->json([
            'remark'  => 'faqs',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'faqs' => $faqs
            ]
        ]);
    }

    public function policyPages()
    {
        $policies = getContent('policy_pages.element', orderById: true);
        $notify[] = 'All policies';
        return response()->json([
            'remark'  => 'policy_data',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'policies' => $policies,
            ],
        ]);
    }



    public function marketOverview()
    {
        
        $topExchangesCoins = Order::whereHas('coin', function ($q) {
            $q->active()->crypto();
        })->where('status', '!=', Status::ORDER_CANCELED)
            ->selectRaw('*,SUM(filled_amount) as total_exchange_amount')
            ->groupBy('coin_id')
            ->orderBy('total_exchange_amount', 'desc')
            ->take(4)
            ->with('coin', 'coin.marketData')
            ->get();

        $highLightedCoins = Currency::active()
            ->crypto()
            ->where('highlighted_coin', Status::YES)
            ->with('marketData')
            ->rankOrdering()
            ->take(4)
            ->get();

        $newCoins = Currency::active()
            ->crypto()
            ->rankOrdering()
            ->with('marketData')
            ->take(4)
            ->get();

        $notify[] = 'Market Overview';
        return response()->json([
            'remark'  => 'market_overview',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'top_exchanges_coins' => $topExchangesCoins,
                'high_lighted_coins'  => $highLightedCoins,
                'new_coins'           => $newCoins,
            ],
        ]);
    }

    public function marketList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:all,crypto,fiat',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $validator->errors()->all()],
            ]);
        }

        $query = CoinPair::searchable(['symbol'])->select('id', 'market_id', 'coin_id', 'symbol');

        if ($request->type != 'all') {
            $query->whereHas('market', function ($q) use ($request) {
                $q->whereHas('currency', function ($c) use ($request) {
                    if ($request->type == 'crypto') {
                        return $c->crypto();
                    }
                    $c->fiat();
                });
            });
        }

        $query = $query->with('market:id,name,currency_id', 'coin:id,name,symbol,image', 'market.currency:id,name,symbol,image', 'marketData')
            ->withCount('trade as total_trade')
            ->orderBy('total_trade', 'desc');

        $total = (clone $query)->count();
        $pairs = (clone $query)->paginate(getPaginate());

        $notify[] = 'Market list';
        return response()->json([
            'remark'  => 'market_list',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'pairs' => $pairs,
                'total' => $total,
            ],
        ]);
    }

    public function cryptoList(Request $request)
    {
        $query = Currency::active()->crypto()->with('marketData')->rankOrdering()->searchable(['name', 'symbol']);

        $total      = (clone $query)->count();
        $currencies = (clone $query)->skip($request->skip ?? 0)
            ->take($request->limit ?? 20)
            ->get();

        $notify[] = 'Crypto list';
        return response()->json([
            'remark'  => 'crypto_list',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'currencies' => $currencies,
                'total'      => $total,
            ],
        ]);
    }

    public function currencies()
    {
        $currencies   = Currency::active()->rankOrdering()->get();

        $notify[] = 'Currencies';
        return response()->json([
            'remark'  => 'currencies',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'currencies' => $currencies,
            ],
        ]);
    }
}
