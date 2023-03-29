<?php

namespace Larvata\Ezpay;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 執行開立發票作業
 */
class CreateInvoiceService
{
    private string $host;
    private string $merchantId;
    private string $key;
    private string $iv;
    private array $payload;
    private string $api;
    private $response;
    private $responseBodyJson;

    private string $buyerName;
    private string $orderNumber;
    private int $taxRate;
    private string $taxType;
    private string $email;
    private string $itemName;
    private $itemCount;
    private $itemUnit;
    private $itemPrice;
    private $uniformNumber;
    private $companyTitle;
    private $carrier;

    private int $amt; // 未稅金額
    private int $vat; // 稅額
    private int $amtIncludingVat; // 含稅金額

    private array $result; // 執行結果

    /**
     * @param string $buyerName 買受人名稱（營業人名稱、統編、會員編號）
     * @param string $orderNumber 訂單編號
     * @param int $taxRate 稅率，預設是 5，百分比
     * @param string $taxType 內含稅（includedTax）/ 外加稅（excludedTax）
     * @param string $email email
     * @param string $itemName 商品名稱，多商品以 | 分隔
     * @param string $itemCount 商品數量，多商品以 | 分隔
     * @param string $itemUnit 商品單位，多商品以 | 分隔
     * @param string $itemPrice 商品單價，多商品以 | 分隔
     * @param string $uniformNumber 統編
     * @param string $companyTitle 公司抬頭
     * @param string $carrier 載具
     */
    public function __construct($buyerName, $orderNumber, $taxRate = 5, $taxType, $email, $itemName, $itemCount, $itemUnit, $itemPrice, $uniformNumber, $companyTitle, $carrier)
    {
        $this->buyerName = $buyerName;
        $this->orderNumber = $orderNumber;
        $this->taxRate = $taxRate; // tax rate percentage（％）
        $this->taxType = $taxType;
        $this->email = $email;
        $this->itemName = $itemName;
        $this->itemCount = $itemCount;
        $this->itemUnit = $itemUnit;
        $this->itemPrice = $itemPrice;
        $this->uniformNumber = $uniformNumber;
        $this->companyTitle = $companyTitle;
        $this->carrier = $carrier;

        $this->host = config('ezpay.host');
        $this->merchantId = config('ezpay.merchant_id');
        $this->key = config('ezpay.key');
        $this->iv = config('ezpay.iv');
    }

    /**
     * 執行開立發票作業
     */
    public function call(): array
    {
        try {
            $this->initResult();
            $this->createInvoice();
        } catch(ConnectionException $e) {
            logger()->error('[發票][Ezpay] 開立發票作業失敗（orderNumber = ' . $this->orderNumber . '）：' . '呼叫 Ezpay API 發生錯誤：' . $e->getMessage());

            $this->result['message'] = "呼叫 Ezpay API 發生錯誤：" . $e->getMessage();
        } catch (Exception $e) {
            logger()->error('[發票][Ezpay] 開立發票作業失敗（orderNumber = ' . $this->orderNumber . '）：' . $e->getMessage());

            $this->result['message'] = $e->getMessage();
        } finally {
            return $this->result;
        }
    }

    private function initResult()
    {
        $this->result = [
            'success' => false,
            'message' => '開立發票作業失敗（orderNumber = ' . $this->orderNumber . '）'
        ];
    }

    /**
     * 開立發票
     */
    private function createInvoice()
    {
        $this->api = '/Api/invoice_issue';

        $this->calculateRelatedAmt();
        $this->makePayload();
        $this->sendRequest();
        $this->afterActions();
    }

    private function sendRequest()
    {
        $this->response = Http::timeout(30)->asForm()
            ->withHeaders([
                              'Content-Type' => 'application/x-www-form-urlencoded'
                          ])->post($this->host.$this->api, $this->payload);

        $this->responseBodyJson = json_decode($this->response->body(), TRUE);
    }

    private function afterActions()
    {
        logger()->info('[發票][Ezpay] 發送建立訂單（orderNumber: ' . $this->orderNumber . '）的發票請求 payload：' . json_encode($this->payload));
        if($this->responseBodyJson['Status'] === 'SUCCESS') {
            $result = json_decode($this->responseBodyJson['Result'], TRUE);

            $this->result = [
                'success' => true,
                'message' => '發送建立發票請求成功',
                'data' => $result
            ];

            logger()->info("[發票][Ezpay] 發送建立發票請求成功（orderNumber: " . $this->orderNumber . "）：" . $this->responseBodyJson['Result']);
        } else {
            $this->result = [
                'success' => false,
                'message' => $this->responseBodyJson['Message']
            ];

            logger()->info("[發票][Ezpay] 發送建立發票請求失敗（orderNumber: " . $this->orderNumber . "）：" . $this->responseBodyJson['Message']);
        }
    }

    /**
     * 設定載具類別
     * @return string
     */
    private function carrierType(): string
    {
        if (isset($this->uniformNumber)) {
            // 公司統編
            return "";
        } else if (isset($this->carrier)) {
            // 0=手機條碼載具
            return "0";
        } else {
            // 2=ezPay 電子發票載具
            return "2";
        }
    }

    /**
     * 發票載具
     * @return string
     */
    private function carrier(): string
    {
        if (isset($this->uniformNumber)) {
            // 公司統編
            return "";
        } else if (isset($this->carrier)) {
            // 0=手機條碼載具
            return "0";
        } else {
            // 2=ezPay 電子發票載具
            return rawurlencode($this->buyerName);
        }
    }

    /**
     * 是否索取紙本發票
     * @return string
     */
    private function printFlag(): string
    {
        if (isset($this->uniformNumber)) {
            // 公司統編
            return "Y";
        } else {
            return "N";
        }
    }

    private function makePayload()
    {
        $postData = http_build_query([
                                         "RespondType" => 'JSON',
                                         "Version" => '1.5',
                                         "TimeStamp" => time(),
                                         "MerchantOrderNo" => $this->orderNumber ?? '',
                                         "Status" => "1",
                                         "Category" => isset($this->uniformNumber) ? "B2B" : "B2C",
                                         "BuyerName" => $this->companyTitle ?? $this->buyerName ?? '',
                                         "BuyerUBN" => $this->uniformNumber ?? '',
                                         "BuyerEmail" => $this->email ?? '',
                                         "CarrierType" => $this->carrierType(),
                                         "CarrierNum" => $this->carrier(),
                                         "PrintFlag" => $this->printFlag(),
                                         "TaxType" => '1',
                                         "TaxRate" => $this->taxRate,
                                         "Amt" => $this->amt,
                                         "TaxAmt" => $this->vat,
                                         "TotalAmt" => $this->amtIncludingVat,
                                         "ItemName" => $this->itemName,
                                         "ItemCount" => $this->itemCount,
                                         "ItemUnit" => $this->itemUnit,
                                         "ItemPrice" => $this->itemPrice,
                                         "ItemAmt" => $this->itemAmt()
                                     ]);

        $postData = trim(bin2hex(openssl_encrypt($this->addPadding($postData),
                                                 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv)));
        $this->payload = [
            "MerchantID_" => $this->merchantId,
            "PostData_" => $postData
        ];
    }

    private function addPadding($string, $blocksize = 32)
    {
        $len = strlen($string);
        $pad = $blocksize - ($len % $blocksize);
        $string .= str_repeat(chr($pad), $pad);
        return $string;
    }

    // 處理小計資訊
    private function itemAmt()
    {
        if(str_contains($this->itemPrice, "|")) {
            $itemPrices = explode("|", $this->itemPrice);
            $itemCounts = explode("|", $this->itemCount);
            $itemAmt = [];
            foreach ($itemPrices as $index => $itemPrice) {
                $itemAmt[] = $itemPrice * $itemCounts[$index];
            }
            return implode("|", $itemAmt);
        } else {
            return $this->itemPrice * $this->itemCount;
        }
    }

    // 計算總和
    private function amt()
    {
        $amt = 0;
        if(str_contains($this->itemPrice, "|")) {
            $itemPrices = explode("|", $this->itemPrice);
            $itemCounts = explode("|", $this->itemCount);
            foreach ($itemPrices as $index => $itemPrice) {
                $amt += $itemPrice * $itemCounts[$index];
            }
        } else {
            $amt = $this->itemPrice * $this->itemCount;
        }

        return $amt;
    }

    // 計算相關金額：未稅金額、稅額、含稅金額
    private function calculateRelatedAmt()
    {
        $total = $this->amt(); // 小計
        if ($this->taxRate !== 0) { // 是否需要計算稅額
            if ($this->taxType === 'excludedTax') { // 外加稅
                $this->amt = $total;
                $this->vat = ceil($total * $this->taxRate * 0.01);
                $this->amtIncludingVat = $total + $this->vat;
            } else { // 內含稅
                $this->amtIncludingVat = $total;
                $this->vat = ceil($this->amtIncludingVat * $this->taxRate * 0.01 / (1 + $this->taxRate * 0.01));
                $this->amt = $this->amtIncludingVat - $this->vat;
            }
        } else { // 免稅或零稅率
            $this->vat = 0;
            $this->amt = $total;
            $this->amtIncludingVat = $total + $this->vat;
        }
    }
}
