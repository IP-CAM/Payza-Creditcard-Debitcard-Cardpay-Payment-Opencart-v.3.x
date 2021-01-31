<?php

use Cardpay\api\AuthApiClient;
use Cardpay\api\FileTokensStorageApi;
use Cardpay\api\PaymentsApi;
use Cardpay\Configuration;
use Cardpay\HeaderSelector;
use Cardpay\model\PaymentRequest;
use Cardpay\model\PaymentRequestCustomer;
use Cardpay\model\PaymentRequestMerchantOrder;
use Cardpay\model\PaymentRequestPaymentData;
use Cardpay\model\Request;
use GuzzleHttp\Client;

class ControllerExtensionPaymentPayza1 extends Controller
{
    const cardpayApiUrl = 'https://sandbox.cardpay.com';
    const terminalCode = '18397';
    const password = 'FpK2cy143POj';

    public function __construct($registry)
    {
        parent::__construct($registry);
        require_once('system/storage/vendor/cardpay/vendor/autoload.php');
    }

    public function index()
    {
//		$data['button_confirm'] = $this->language->get('button_confirm');
//
//		$this->load->model('checkout/order');
//
//		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $orderId = $this->session->data['order_id'];
        $redirectUrl = $this->createPayment($orderId, self::terminalCode, self::password);

        $data['action'] = $redirectUrl;

//		$data['ap_merchant'] = $this->config->get('payment_payza1_merchant');
//		$data['ap_amount'] = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
//		$data['ap_currency'] = $order_info['currency_code'];
//		$data['ap_purchasetype'] = 'Item';
//		$data['ap_itemname'] = $this->config->get('config_name') . ' - #' . $this->session->data['order_id'];
//		$data['ap_itemcode'] = $this->session->data['order_id'];
//		$data['ap_returnurl'] = $this->url->link('checkout/success');
//		$data['ap_cancelurl'] = $this->url->link('checkout/checkout', '', true);

        return $this->load->view('extension/payment/payza1', $data);
    }

    public function callback()
    {
        if (isset($this->request->post['ap_securitycode']) && ($this->request->post['ap_securitycode'] == $this->config->get('payment_payza1_security'))) {
            $this->load->model('checkout/order');

            $this->model_checkout_order->addOrderHistory($this->request->post['ap_itemcode'], $this->config->get('payment_payza1_order_status_id'));
        }
    }

    public function getConfiguration($terminalCode, $password)
    {
        $fileTokensStorageApi = new FileTokensStorageApi(self::cardpayApiUrl, $terminalCode);
        $authApiClient = new AuthApiClient(self::cardpayApiUrl, $terminalCode, $password, $fileTokensStorageApi);

        $apiTokens = $authApiClient->obtainApiTokens();

        $accessToken = $apiTokens->getAccessToken();
        $tokenType = $apiTokens->getTokenType();

        $configuration = new Configuration(self::cardpayApiUrl);
        $configuration->setApiKeyPrefix('Authorization', $tokenType)
            ->setApiKey('Authorization', $accessToken);

        return $configuration;
    }

    private function createPayment($orderId, $terminalCode, $password)
    {
        $order_info = $this->model_checkout_order->getOrder($orderId);
        $customerEmail = $order_info['email'];
        $orderDescription = 'Order ' . $orderId . ' (' . $order_info['invoice_prefix'] . '-' . $order_info['invoice_no'] . ') for user ' . $customerEmail;
        $orderAmount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $orderCurrency = $this->session->data['currency'];

        if (null == $this->cardPayConfig) {
            $this->cardPayConfig = $this->getConfiguration($terminalCode, $password);
        }
        if (null == $this->client) {
            $this->client = new Client();
        }
        if (null == $this->headerSelector) {
            $this->headerSelector = new HeaderSelector();
        }

        $request = new Request([
            'id' => microtime(true),
            'time' => new DateTime()
        ]);

        $merchantOrder = new PaymentRequestMerchantOrder([
            'id' => $orderId,
            'description' => $orderDescription
        ]);

        $paymentData = new PaymentRequestPaymentData([
            'amount' => $orderAmount
        ]);

        $paymentData['currency'] = $orderCurrency;
        $paymentData['trans_type'] = '01'; //TRANS_TYPE_GOODS_SERVICE_PURCHASE

        $customer = new PaymentRequestCustomer([
            'email' => $customerEmail,
            'phone' => $order_info['telephone']
        ]);

        $paymentRequestData = [
            'request' => $request,
            'merchant_order' => $merchantOrder,
            'payment_method' => 'BANKCARD',
            'payment_data' => $paymentData,
            'customer' => $customer
        ];

        if (null == $this->paymentsApi) {
            $this->paymentsApi = new PaymentsApi(self::cardpayApiUrl, $this->client, $this->cardPayConfig, $this->headerSelector);
        }

        $paymentRequest = new PaymentRequest($paymentRequestData);
        $paymentCreationResponse = $this->paymentsApi->createPayment($paymentRequest);

        return $paymentCreationResponse->getRedirectUrl();
    }
}