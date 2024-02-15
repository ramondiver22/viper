<?php

namespace App\Traits\Gateways;

use App\Models\AffiliateHistory;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\SuitPayPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\NewDepositNotification;
use App\Notifications\NewWithdrawalNotification;
use App\Traits\Affiliates\AffiliateHistoryTrait;
use Carbon\Carbon;
use GuzzleHttp\Client;
use http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

trait SuitpayTrait
{
    use AffiliateHistoryTrait;

    /**
     * @var $uri
     * @var $clienteId
     * @var $clienteSecret
     */
    protected static string $uri;
    protected static string $clienteId;
    protected static string $clienteSecret;

    /**
     * Generate Credentials
     * Metodo para gerar credenciais
     * @return void
     */
    private static function generateCredentials()
    {
        $setting = Gateway::first();
        if(!empty($setting)) {
            self::$uri = $setting->suitpay_uri;
            self::$clienteId = $setting->suitpay_cliente_id;
            self::$clienteSecret = $setting->suitpay_cliente_secret;
        }
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

        self::generateCredentials();

        $response = Http::withHeaders([
            'ci' => self::$clienteId,
            'cs' => self::$clienteSecret
        ])->post(self::$uri.'gateway/request-qrcode', [
            "requestNumber" => time(),
            "dueDate" => Carbon::now()->addDay(),
            "amount" => \Helper::amountPrepare($request->amount),
            "shippingAmount" => 0.0,
            "usernameCheckout" => "checkout",
            "callbackUrl" => url('/suitpay/callback'),
            "client" => [
                "name" => auth()->user()->name,
                "document" =>\Helper::soNumero($request->cpf),
                "phoneNumber" => \Helper::soNumero(auth()->user()->phone),
                "email" => auth()->user()->email
            ]
        ]);

        if($response->successful()) {
            $responseData = $response->json();

            self::generateTransaction($responseData['idTransaction'], \Helper::amountPrepare($request->amount)); /// gerando historico
            self::generateDeposit($responseData['idTransaction'], \Helper::amountPrepare($request->amount)); /// gerando deposito

            return [
                'status' => true,
                'idTransaction' => $responseData['idTransaction'],
                'qrcode' => $responseData['paymentCode']
            ];
        }

        return [
            'status' => false,
        ];
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
        self::generateCredentials();

        $response = Http::withHeaders([
            'ci' => self::$clienteId,
            'cs' => self::$clienteSecret
        ])->post(self::$uri.'gateway/consult-status-transaction', [
            "typeTransaction" => "PIX",
            "idTransaction" => $request->idTransaction,
        ]);
        if($response->successful()) {
            $responseData = $response->json();

            if($responseData == "PAID_OUT" || $responseData == "PAYMENT_ACCEPT") {
                if(self::finalizePayment($request->idTransaction)) {
                    return response()->json(['status' => 'PAID']);
                }

                return response()->json(['status' => $responseData], 400);
            }

            return response()->json(['status' => $responseData], 400);
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
                        return self::updateAffiliate($transaction->payment_id, $transaction->user_id, $transaction->price);
                    }
                    return false;
                }
                return false;
            }
            return false;
        }
        $transactionActive = Transaction::where('payment_id', $idTransaction)->where('status', 1)->first();
        if(!empty($transactionActive)) {
            return true;
        }


        return false;
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
     * @param $request
     * @return \Illuminate\Http\JsonResponse|void
     */
    public static function pixCashOut(array $array): bool
    {
        self::generateCredentials();

        $response = Http::withHeaders([
            'ci' => self::$clienteId,
            'cs' => self::$clienteSecret
        ])->post(self::$uri.'gateway/pix-payment', [
            "key" => $array['pix_key'],
            "typeKey" => $array['pix_type'],
            "value" => $array['amount'],
            'callbackUrl' => url('/suitpay/payment'),
        ]);

        if($response->successful()) {
            $responseData = $response->json();

            if($responseData['response'] == 'OK') {
                $suitPayPayment = SuitPayPayment::lockForUpdate()->find($array['payment_id']);
                if(!empty($suitPayPayment)) {
                    if($suitPayPayment->update(['status' => 1, 'payment_id' => $responseData['idTransaction']])) {
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
