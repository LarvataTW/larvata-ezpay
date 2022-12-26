<?php

namespace Larvata\Ezpay;

use App\Enums\OrderInvoiceType;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * 執行開立訂單作業
 */
class CreateInvoiceService
{
    private $orders;
    private string $host;
    private string $merchantId;
    private string $key;
    private string $iv;
    private string $payload;
    private string $api;
    private $response;
    private $responseBodyJson;

    /**
     * @param array $orderIds 訂單編號
     */
    public function __construct($orderIds, $taxRate = 5)
    {
        $this->orderIds = $orderIds;
        $this->taxRate = $taxRate; // tax rate percentage（％）

        $this->host = config('ezpay.host');
        $this->merchantId = config('ezpay.merchant_id');
        $this->key = config('ezpay.key');
        $this->iv = config('ezpay.iv');
        $this->orderClassName = config('ezpay.order_class_name');
    }

    /**
     * 執行開立發票作業
     */
    public function call()
    {
        $this->loadOrders();
        foreach ($this->orders as $order)
        {
            try {
                 $this->createInvoiceBy($order);
            } catch (Exception $e) {
                logger()->error('[發票][Ezpay] 開立發票作業失敗（order_id = ' . $order->id . '）：' . $e->getMessage());
            }
        }
    }

    /**
     * 讀取可開立發票的訂單資料
     */
    private function loadOrders()
    {
        // 依據傳入訂單編號查詢對應的訂單資料
        $this->orders = $this->orderScope()->get();
    }

    /**
     * 對訂單開立發票
     */
    private function createInvoiceBy($order)
    {
        $this->api = '/Api/invoice_issue';
        $this->makePayload($order);

        $this->response = Http::timeout(30)->asForm()
            ->withHeaders([
                              'Content-Type' => 'application/x-www-form-urlencoded'
                          ])->post($this->host.$this->api, $this->payload);

        $this->responseBodyJson = json_decode($this->response->body(), TRUE);

        $this->afterActions($order);
    }

    private function afterActions($order)
    {
        logger()->info('[發票][Ezpay] 發送建立訂單（id: ' . $order->id . '）的發票請求 payload：' . json_encode($this->payload));
        if($this->responseBodyJson['Status'] === 'SUCCESS') {
            $result = json_decode($this->responseBodyJson['Result'], TRUE);
            $invoiceTransNo = $result['InvoiceTransNo'];
            $invoiceNumber = $result['InvoiceNumber'];
            $invoiceCreatedAt = $result['CreateTime'];
            $order->update([
                               "invoice_number" => $invoiceNumber,
                               "invoice_trans_no" => $invoiceTransNo,
                               "invoice_created_at" => Carbon::create($invoiceCreatedAt)
                           ]);
            logger()->info("[發票][Ezpay] 發送建立發票請求成功：" . $this->responseBodyJson['Result']);
        } else {
            logger()->info("[發票][Ezpay] 發送建立發票請求失敗：" . $this->responseBodyJson['Message']);
        }
    }

    /**
     * 設定載具類別
     * @param $order
     * @return string|void
     */
    private function carrierType($order)
    {
        $invoiceType = $order->invoice_type ?? OrderInvoiceType::ElectricityInvoice;
        switch(OrderInvoiceType::fromValue($invoiceType))
        {
            case OrderInvoiceType::UniformNumber():
                return "";
            case OrderInvoiceType::ElectricityInvoice():
                // 2=ezPay 電子發票載具
                return "2";
            case OrderInvoiceType::Carrier():
                // 0=手機條碼載具
                return "0";
        }
    }

    // 發票載具
    private function carrier($order)
    {
        $invoiceType = $order->invoice_type ?? OrderInvoiceType::ElectricityInvoice;
        switch(OrderInvoiceType::fromValue($invoiceType))
        {
            case OrderInvoiceType::UniformNumber():
                return '';
            case OrderInvoiceType::ElectricityInvoice():
                return rawurlencode($order->member_id);
            case OrderInvoiceType::Carrier():
                return $order->carrier;
        }
    }

    /**
     * 是否索取紙本發票
     * @param $order
     * @return string|void
     */
    private function printFlag($order)
    {
        $invoiceType = $order->invoice_type ?? OrderInvoiceType::ElectricityInvoice;
        switch(OrderInvoiceType::fromValue($invoiceType))
        {
            case OrderInvoiceType::UniformNumber():
                return "Y";
            case OrderInvoiceType::ElectricityInvoice():
            case OrderInvoiceType::Carrier():
                return "N";
                break;
        }
    }

    /**
     * 計算未稅額
     * @param $order
     */
    private function amt($order)
    {
        return (int)round($order->fee/(1 + $this->taxRate * 0.01));
    }

    private function makePayload($order)
    {
        $postData = http_build_query([
            "RespondType" => 'JSON',
            "Version" => '1.5',
            "TimeStamp" => time(),
            "MerchantOrderNo" => $order->order_number,
            "Status" => "1",
            "Category" => isset($order->uniform_number) ? "B2B" : "B2C",
            "BuyerName" => (string) ($order->company_title ?? $order->member->name ?? $order->member_id ?? ''),
            "BuyerUBN" => $order->uniform_number ?? '',
            "BuyerEmail" => $order->email ?? '',
            "CarrierType" => $this->carrierType($order),
            "CarrierNum" => $this->carrier($order),
            "PrintFlag" => $this->printFlag($order),
            "TaxType" => '1',
            "TaxRate" => 5,
            "Amt" => $this->amt($order),
            "TaxAmt" => $order->fee - $this->amt($order),
            "TotalAmt" => $order->fee,
            "ItemName" => $order->plan->name ?? $order->coupon->name ?? '',
            "ItemCount" => 1,
            "ItemUnit" => '式',
            "ItemPrice" => $order->fee,
            "ItemAmt" => $order->fee
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

    private function orderScope()
    {
        return $this->orderClassName::query()->select('orders.*');
    }
}
