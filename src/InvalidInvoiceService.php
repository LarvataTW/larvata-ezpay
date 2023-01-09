<?php

namespace Larvata\Ezpay;

use App\Enums\OrderInvoiceType;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * 執行作廢發票作業
 */
class InvalidInvoiceService
{
    private string $host;
    private string $merchantId;
    private string $key;
    private string $iv;
    private array $payload;
    private string $api;
    private $response;
    private $responseBodyJson;
    private array $result;

    private string $invoiceNumber;
    private string $invalidReason;

    /**
     * @param string $invoiceNumber 發票號碼
     * @param string $invalidReason 作廢原因
     */
    public function __construct($invoiceNumber, $invalidReason)
    {
        $this->invoiceNumber = $invoiceNumber;
        $this->invalidReason = $invalidReason;

        $this->host = config('ezpay.host');
        $this->merchantId = config('ezpay.merchant_id');
        $this->key = config('ezpay.key');
        $this->iv = config('ezpay.iv');
    }

    /**
     * 執行作廢發票作業
     */
    public function call()
    {
        $this->result = [];

        try {
            $this->initResult();
            $this->invalidInvoice();
        } catch (Exception $e) {
            $errorMessage = '[發票][Ezpay] 作廢發票作業失敗（invoiceNumber = ' . $this->invoiceNumber . '）：' . $e->getMessage();

            logger()->error($errorMessage);

            $this->result['success'] = false;
            $this->result['message'] = $errorMessage;
        }

        return $this->result;
    }

    private function initResult()
    {
        $this->result = [
            'success' => false,
            'message' => '作廢發票作業失敗（invoiceNumber = ' . $this->invoiceNumber . '）'
        ];
    }

    /**
     * 呼叫作廢發票 API
     */
    private function invalidInvoice()
    {
        $this->api = '/Api/invoice_invalid';

        $this->makePayload();
        $this->send_request();
        $this->afterActions();
    }

    private function makePayload()
    {
        $postData = http_build_query([
                                         "RespondType" => 'JSON',
                                         "Version" => '1.0',
                                         "TimeStamp" => time(),
                                         "InvoiceNumber" => $this->invoiceNumber,
                                         "InvalidReason" => $this->invalidReason,
                                     ]);

        $postData = trim(bin2hex(openssl_encrypt($this->addPadding($postData),
                                                 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv)));

        $this->payload = [
            "MerchantID_" => $this->merchantId,
            "PostData_" => $postData
        ];

        logger()->info('[發票][Ezpay] 發送作廢發票（' . $this->invoiceNumber . '）的發票請求 payload：' . json_encode($this->payload));
    }

    private function send_request()
    {
        $this->response = Http::timeout(30)->asForm()
            ->withHeaders([
                              'Content-Type' => 'application/x-www-form-urlencoded'
                          ])->post($this->host.$this->api, $this->payload);

        $this->responseBodyJson = json_decode($this->response->body(), TRUE);
    }

    private function afterActions()
    {
        if($this->responseBodyJson['Status'] === 'SUCCESS') {
            $result = json_decode($this->responseBodyJson['Result'], TRUE);

            $this->result = [
                'success' => true,
                'message' => '發送作廢發票請求成功',
                'data' => $result
            ];

            logger()->info("[發票][Ezpay] 發送作廢發票請求成功（" . $this->invoiceNumber . "）");
        } else {
            $this->result = [
                'success' => false,
                'message' => '[發票][Ezpay] 作廢 ' . $this->invoiceNumber . ' 發票發生錯誤（' . $this->responseBodyJson['Status'] . ':' . $this->responseBodyJson['Message'] . '）'
            ];

            logger()->info("[發票][Ezpay] 發送作廢發票請求失敗（" . $this->invoiceNumber . "）：" . $this->responseBodyJson['Result']);
        }
    }

    private function addPadding($string, $blocksize = 32)
    {
        $len = strlen($string);
        $pad = $blocksize - ($len % $blocksize);
        $string .= str_repeat(chr($pad), $pad);
        return $string;
    }
}
