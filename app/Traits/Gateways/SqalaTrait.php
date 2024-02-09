<?php

namespace App\Traits\Gateways;

use App\Models\AffiliateHistory;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Notifications\NewDepositNotification;
use App\Traits\Affiliates\AffiliateHistoryTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

trait SqalaTrait
{
    use AffiliateHistoryTrait;

    protected static $uri = 'https://api.sqala.tech/core/v1/';

    /**
     * Get Authentication
     * @return bool
     */
    private static function getAuthentication(): bool
    {
        $gateway = Gateway::first();
        if(!empty($gateway)) {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($gateway->sqala_app_id . ':' . $gateway->sqala_access_token),
                'Content-Type' => 'application/json',
            ])
            ->post(self::$uri. 'access-tokens', [
                'refreshToken' => $gateway->sqala_access_token,
            ]);


            if($response->successful()) {
                $data = $response->json();
                $gateway->update(['sqala_access_token' => $data['token']]);
                return $data['token'];
            }

            return false;
        }

        return false;
    }

    /**
     * Request QRCODE
     * Metodo para solicitar uma QRCODE PIX
     * @return array
     */
    public static function requestQrcode($request)
    {
        $setting = \Helper::getSetting();
        $rules = [
            'amount' => ['required', 'max:'.$setting->min_deposit, 'max:'.$setting->max_deposit],
            'cpf'    => ['required', 'max:255'],
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return [
                'status' => false,
                'errors' => $validator->errors()
            ];
        }

        if($token = self::getAuthentication()) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->post(self::$uri.'pix-qrcode-payments', [
                'amount' => \Helper::amountPrepare($request->amount)
            ]);

            if($response->successful()) {
                $responseData = $response->json();

                self::generateTransaction($responseData['id'], \Helper::amountPrepare($request->amount)); /// gerando historico
                self::generateDeposit($responseData['id'], \Helper::amountPrepare($request->amount)); /// gerando deposito

                return [
                    'status' => true,
                    'idTransaction' => $responseData['id'],
                    'qrcode' => $responseData['payload']
                ];
            }
            return [
                'status' => false,
            ];
        }
        return [
            'status' => false,
        ];
    }

    /**
     * @param $idTransaction
     * @param $amount
     * @return void
     */
    private static function generateDeposit($idTransaction, $amount)
    {
        Deposit::create([
            'payment_id' => $idTransaction,
            'user_id' => auth()->user()->id,
            'amount' => $amount,
            'type' => 'Pix',
            'status' => 0
        ]);
    }

    /**
     * @param $idTransaction
     * @param $amount
     * @return void
     */
    private static function generateTransaction($idTransaction, $amount)
    {
        $setting = \Helper::getSetting();

        Transaction::create([
            'payment_id' => $idTransaction,
            'user_id' => auth()->user()->id,
            'payment_method' => 'pix',
            'price' => $amount,
            'currency' => $setting->currency_code,
            'status' => 0
        ]);
    }


    /**
     * Consult Status Transaction
     * Consultar o status da transação
     *
     * @param $request
     * @return \Illuminate\Http\JsonResponse
     */
    public static function consultStatusTransaction($request)
    {
        if($token = self::getAuthentication()) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'accept' => 'application/json'
            ])->get(self::$uri.'pix-qrcode-payments/' . $request->idTransaction);

            if($response->successful()) {
                $responseData = $response->json();

                if($responseData['status'] == "PROCESSED") {
                    if(self::finalizePayment($request->idTransaction)) {
                        return response()->json(['status' => 'PAID']);
                    }

                    return response()->json(['status' => $responseData], 400);
                }

                return response()->json(['status' => $responseData], 400);
            }
        }
    }


    /**
     * @param $idTransaction
     * @return bool
     */
    public static function finalizePayment($idTransaction) : bool
    {
        $transaction = Transaction::where('payment_id', $idTransaction)->where('status', 0)->first();
        $setting = \Helper::getSetting();

        if(!empty($transaction)) {
            $user = User::find($transaction->user_id);

            /// verifica se vem de um convite
            if(!empty($user) && !empty($user->inviter)) {
                $afiliado =  User::find($user->inviter);
                if(!empty($afiliado)) {

                }
            }

            $wallet = Wallet::where('user_id', $transaction->user_id)->first();
            if(!empty($wallet)) {
                /// verifica se é o primeiro deposito
                $checkTransactions = Transaction::where('user_id', $transaction->user_id)->count();
                if($checkTransactions <= 1) {
                    /// pagar o bonus
                    $bonus = \Helper::porcentagem_xn($setting->initial_bonus, $transaction->price);
                    $wallet->increment('balance_bonus', $bonus);
                }

                if($wallet->increment('balance', $transaction->price)) {
                    if($transaction->update(['status' => 1])) {
                        self::updateAffiliate($transaction->payment_id, $transaction->user_id, $transaction->price);
                        return false;
                    }
                    return false;
                }
                return false;
            }
            return false;
        }
        return false;
    }


    /**
     * Update Affiliate
     *
     * @param $idTransaction
     * @param $userId
     * @param $price
     * @return bool|void
     */
    public static function updateAffiliate($idTransaction, $userId, $price)
    {
        $deposit = Deposit::with(['user'])->where('payment_id', $idTransaction)->where('status', 0)->first();
        $user = User::find($userId);

        if(!empty($deposit)) {

            /// verificar se existe sponsor
            $affHistories = AffiliateHistory::where('user_id', $userId)
                ->where('deposited', 0)
                ->where('status', 0)
                ->get();

            if(count($affHistories) > 0) {
                foreach($affHistories as $affHistory) {
                    if(!empty($affHistory)) {

                        /// atualiza os valores depositado
                        $affHistory->update(['deposited' => 1, 'deposited_amount' => $price]);
                    }
                }

                /// fazer o deposito em cpa
                $affHistoryCPA = AffiliateHistory::where('user_id', $userId)
                    ->where('commission_type', 'cpa')
                    ->where('deposited', 1)
                    ->where('status', 0)
                    ->lockForUpdate()
                    ->first();

                if(!empty($affHistoryCPA)) {

                    /// verifcia se já pode receber o cpa
                    $sponsorCpa = User::find($affHistoryCPA->inviter);
                    if(!empty($sponsorCpa)) {
                        if($affHistoryCPA->deposited_amount >= $sponsorCpa->affiliate_baseline) {
                            $walletCpa = Wallet::where('user_id', $affHistoryCPA->inviter)->lockForUpdate()->first();
                            if(!empty($walletCpa)) {

                                /// paga o valor de CPA
                                $walletCpa->increment('refer_rewards', $sponsorCpa->affiliate_cpa); /// coloca a comissão do cpa
                                $affHistoryCPA->update(['status' => 1, 'commission_paid' => $sponsorCpa->affiliate_cpa]); /// desativa cpa
                            }
                        }
                    }
                }

                /// notificar todos admin
                if($deposit->update(['status' => 1])) {
                    $admins = User::where('role_id', 0)->get();
                    foreach ($admins as $admin) {
                        $admin->notify(new NewDepositNotification($deposit->user->name, $price));
                    }

                    return true;
                }
                return false;
            }else{
                $affHistories = AffiliateHistory::where('user_id', $userId)->first();
                if(empty($affHistories)) {
                    /// criando novo affiliate history
                    if(self::saveAffiliateHistory($user)) {
                        self::updateAffiliate($idTransaction, $userId, $price);
                    }
                }
            }
        }
    }


    /**
     * Make Payment
     *
     * @param array $array
     * @return bool
     */
    public static function MakePayment(array $array): bool
    {
        if($token = self::getAuthentication()) {
            $pixKey     = $array['pix_key'];
            $amount     = $array['amount'];

            $parameters = [
                'amount' => floatval(\Helper::amountPrepare($amount)),
                "method" => "PIX",
                "pixKey" => $pixKey,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])->post(self::$uri.'recipients/DEFAULT/withdrawal', $parameters);

            if ($response->successful()) {
                $responseData = $response->json();

                if($responseData['status'] === 'PROCESSED') {
                    $withdrawal = Withdrawal::find($array['payment_id']);
                    if(!empty($withdrawal)) {
                        $withdrawal->update([
                            'proof' => $responseData['id'],
                            'status' => 1,
                        ]);
                        return true;
                    }

                    return false;
                }
                return false;
            }
            return false;
        }
        return false;
    }
}
