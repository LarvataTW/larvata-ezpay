<?php

namespace Larvata\Ezpay;

use App\Enums\OrderInvoiceType;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use mysql_xdevapi\Warning;

/**
 * 執行查詢發票作業
 */
class SearchInvoiceService
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

    private string $orderNumber;
    private int $totalAmt;
    private string $invoiceNumber;
    private string $randomNum;

    /**
     * @param string $orderNumber 訂單編號
     * @param int $totalAmt 總金額
     * @param string $invoiceNumber 發票號碼
     * @param string $randomNum 發票防偽隨機碼
     */
    public function __construct($orderNumber = '', $totalAmt = 0, $invoiceNumber = '', $randomNum = null)
    {
        $this->orderNumber = $orderNumber;
        $this->totalAmt = $totalAmt;
        $this->invoiceNumber = $invoiceNumber;
        $this->randomNum = $randomNum;

        $this->host = config('ezpay.host');
        $this->merchantId = config('ezpay.merchant_id');
        $this->key = config('ezpay.key');
        $this->iv = config('ezpay.iv');
    }

    /**
     * 執行查詢發票作業
     */
    public function call()
    {
        $this->result = [];

        try {
            $this->initResult();
            $this->SearchInvoice();
        } catch (Exception $e) {
            $errorMessage = '[發票][Ezpay] 查詢發票作業失敗（invoiceNumber = ' . $this->invoiceNumber . '）：' . $e->getMessage();

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
            'message' => '查詢發票作業失敗（invoiceNumber = ' . $this->invoiceNumber . '）'
        ];
    }

    /**
     * 呼叫查詢發票 API
     */
    private function searchInvoice()
    {
        $this->api = '/Api/invoice_search';

        $this->makePayload();
        $this->send_request();
        $this->afterActions();
    }

    private function makePayload()
    {
        $postData = http_build_query(
            [
                "RespondType" => 'JSON',
                "Version" => '1.3',
                "TimeStamp" => time(),
                "MerchantOrderNo" => $this->invoiceNumber,
                "TotalAmt" => $this->invalidReason,
                "InvoiceNumber" => $this->invoiceNumber,
                "RandomNum" => $this->randomNum ?? rand(1000, 9999)
            ]
        );

        $postData = trim(bin2hex(openssl_encrypt($this->addPadding($postData),
                                                 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv)));

        $this->payload = [
            "MerchantID_" => $this->merchantId,
            "PostData_" => $postData
        ];

        logger()->info('[發票][Ezpay] 發送查詢發票（' . $this->invoiceNumber . '）的發票請求 payload：' . json_encode($this->payload));
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
                'message' => '發送查詢發票請求成功',
                'data' => $result
            ];

            logger()->info("[發票][Ezpay] 發送查詢發票請求成功（" . $this->invoiceNumber . "）");
        } else {
            $this->result = [
                'success' => false,
                'message' => '[發票][Ezpay] 查詢 ' . $this->invoiceNumber . ' 發票發生錯誤（' . $this->responseBodyJson['Status'] . ':' . $this->responseBodyJson['Message'] . '）'
            ];

            logger()->info("[發票][Ezpay] 發送查詢發票請求失敗（" . $this->invoiceNumber . "）：" . $this->responseBodyJson['Result']);
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
