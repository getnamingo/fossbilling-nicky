<?php
/**
 * Nicky payment gateway module for FOSSBilling (https://fossbilling.org/)
 *
 * Written in 2025 by Iliya Bazlyankov (https://atrio.dev/)
 *
 * Includes code from FOSSBilling default modules
 * Copyright 2022-2024 FOSSBilling (https://www.fossbilling.org)
 * Copyright 2011-2021 BoxBilling, Inc.
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

class Payment_Adapter_Nicky implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    private $_api_url = 'https://api-public.pay.nicky.me/';
    public $curlRequester;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if (!isset($this->config['auth_token'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Nicky', ':missing' => 'Auth Key'], 4001);
        }
        $this->curlRequester = new CurlRequester();
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'description' => 'You authenticate to the Nicky API by providing your API key in the request. You can manage your API keys from your account.',
            'logo' => [
                'logo' => '/Nicky/Nicky.svg',
                'height' => '45px',
                'width' => '45px',
            ],
            'form' => [
                'auth_token' => [
                    'password', [
                        'label' => 'Auth Token:',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        return $this->_generateForm($invoiceModel);
    }

    public function getInvoiceTitle(Model_Invoice $invoice)
    {
        $invoiceItems = $this->di['db']->getAll('SELECT title from invoice_item WHERE invoice_id = :invoice_id', [':invoice_id' => $invoice->id]);

        $params = [
            ':id' => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title'],
        ];
        $title = __trans('Payment for invoice :serie:id [:title]', $params);
        if ((is_countable($invoiceItems) ? count($invoiceItems) : 0) > 1) {
            $title = __trans('Payment for invoice :serie:id', $params);
        }

        return $title;
    }

    public function logError($e, Model_Transaction $tx)
    {
        $body = $e->getJsonBody();
        $err = $body['error'];
        $tx->txn_status = $err['type'];
        $tx->error = $err['message'];
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        if (DEBUG) {
            error_log(json_encode($e->getJsonBody()));
        }

        throw new Exception($tx->error);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        // Fetch the notes field to retrieve the shortId
        $invoice = $this->di['db']->findOne('Invoice', 'id = :id', [':id' => $data['get']['invoice_id']]);
        if (!$invoice || empty($invoice->notes)) {
            throw new FOSSBilling\Exception('No shortId found for the given invoice_id.');
        }

        // Extract shortId from the notes field
        preg_match('/shortId: (\S+)/', $invoice->notes, $matches);
        if (!isset($matches[1])) {
            throw new FOSSBilling\Exception('Invalid or missing shortId in the invoice notes.');
        }

        $shortId = $matches[1];

        // Build the URL for the API request
        $url = $this->_api_url . 'api/public/PaymentRequestPublicApi/get-by-short-id?shortId=' . urlencode($shortId);

        // Make the API GET request
        $headers = [
            'x-api-key: ' . $this->config['auth_token'],
            'Content-Type: application/json',
        ];

        try {
            $response = $this->curlRequester->make_curl_get_request($url, $headers);
            $responseData = json_decode($response, true);
        } catch (Exception $e) {
            error_log('Error making API call to check invoice status: ' . $e->getMessage());
            throw new FOSSBilling\Exception('Error making API call to check invoice status: ' . $e->getMessage());
        }

        // Extract required data
        $shortId = $responseData['bill']['shortId'];
        $invoiceReference = $responseData['bill']['invoiceReference'];
        $description = $responseData['bill']['description'];
        $createdDate = $responseData['createdDate'];
        $status = $responseData['status'];
        $cancelUrl = $responseData['cancelUrl'];

        if ($status === "None" || $status === "PaymentValidationRequired" || $status === "PaymentPending") {
            // Status is pending, output the HTML with JavaScript to refresh
            echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Nicky Payment Status</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    background-color: #f4f4f9;
                    color: #333;
                    margin: 0;
                    padding: 20px;
                    text-align: center;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: #fff;
                    padding: 20px;
                    border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                }
                .status {
                    padding: 15px;
                    margin: 20px 0;
                    font-size: 18px;
                    font-weight: bold;
                    border-radius: 5px;
                }
                .pending {
                    background-color: #fff3cd;
                    color: #856404;
                }
                button {
                    margin-top: 20px;
                    padding: 10px 20px;
                    font-size: 16px;
                    border: none;
                    border-radius: 5px;
                    background-color: #007bff;
                    color: white;
                    cursor: pointer;
                }
            </style>
            <script>
                function refreshPage() {
                    location.reload();
                }

                document.addEventListener("DOMContentLoaded", function() {
                    const status = "$status";
                    if (status === "PaymentPending") {
                        setTimeout(refreshPage, 900000); // Auto-refresh every 15 minutes
                    }
                });
            </script>
        </head>
        <body>
            <div class="container">
                <h1>Nicky Payment Status</h1>
                <p><strong>Short ID:</strong> $shortId</p>
                <p><strong>Invoice Reference:</strong> $invoiceReference</p>
                <p><strong>Description:</strong> $description</p>
                <p><strong>Created Date:</strong> $createdDate</p>
                <div class="status pending">
                    Status: $status
                </div>
                <p>
                    <strong>Payment Delay Notice:</strong> Cryptocurrency payments can take additional time to confirm. 
                    Please copy and save this link to check the status later:
                </p>
                <p>
                    <a href="{$_SERVER['REQUEST_URI']}" target="_blank" style="word-break: break-all;">
                        https://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}
                    </a>
                </p>
                <button onclick="refreshPage()">Refresh Now</button>
            </div>
        </body>
        </html>
        HTML;
            exit;
        } elseif ($status === "Canceled") {
            // Status is error, redirect to cancel URL
            header("Location: $cancelUrl");
            exit;
        } else {
            $tx = $this->di['db']->getExistingModelById('Transaction', $id);
            $tx->invoice_id = $data['get']['invoice_id'];
            $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);

            if ($responseData['status'] === 'Finished' && $tx->status !== 'processed') {
                $tx->txn_status = $responseData['status'];
                $tx->txn_id = $responseData['id'];
                $tx->amount = $responseData['amountNative'];
                $tx->currency = $invoice->currency;
                $tx->type = 'Payment';

                $bd = [
                    'amount' => $tx->amount,
                    'description' => 'Nicky transaction ' . $responseData['id'],
                    'type' => 'transaction',
                    'rel_id' => $tx->id,
                ];

                // Instance the services we need
                $clientService = $this->di['mod_service']('client');
                $invoiceService = $this->di['mod_service']('Invoice');

                // Update the account funds
                $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
                $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

                // Now pay the invoice / batch pay if there's no invoice associated with the transaction
                if ($tx->invoice_id) {
                    $invoiceService->payInvoiceWithCredits($invoice);
                } else {
                    $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
                }
            } else {
                $this->logError('Payment failed or status is missing.', $tx);
                throw new FOSSBilling\Exception('There was an error when processing the transaction');
            }

            $paymentStatus = match ($responseData['status']) {
                // API Status                        => FOSSBilling Status
                'None'                              => 'pending',      // No information yet, default to pending
                'PaymentPending'                    => 'pending',      // Payment is still in progress
                'PaymentValidationRequired'         => 'pending',      // Payment requires manual validation
                'Canceled'                          => 'failed',       // Payment was canceled, mark as failed
                'Finished'                          => 'succeeded',    // Payment was successfully completed
                default                             => 'pending',      // Fallback for unknown statuses
            };

            $tx->status = $paymentStatus;
            $tx->updated_at = date('Y-m-d H:i:s');
            $tx->ip = gethostbyname(gethostname());
            $this->di['db']->store($tx);
        }

    }

    protected function _generateForm(Model_Invoice $invoice): string
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $params = array();
        
        $blockchainAssetId = match ($invoice->currency) {
            'USD' => 'USD.USD',
            'EUR' => 'EUR.EUR',
            default => throw new Exception('Unsupported currency: ' . $invoice->currency),
        };
        $baseUrl = rtrim($this->di['tools']->url('/'), '/');

        // Prepare the JSON payload
        $payload = [
            "blockchainAssetId" => $blockchainAssetId,
            "amountExpectedNative" => $invoiceService->getTotalWithTax($invoice),
            "billDetails" => [
                "invoiceReference" => (string)$invoice->id,
                "description" => preg_replace('/^(Payment for invoice \S+).*/', '$1', $this->getInvoiceTitle($invoice))
            ],
            "requester" => [
                "email" => $invoice->buyer_email,
                "name" => trim($invoice->buyer_first_name . ' ' . $invoice->buyer_last_name)
            ],
            "sendNotification" => true,
            "successUrl" => $this->config['notify_url'],
            "cancelUrl" => $this->di['tools']->url('invoice/'),
        ];

        $url = $this->_api_url . 'api/public/PaymentRequestPublicApi/create';
        $headers = [
            'x-api-key: ' . $this->config['auth_token'],
            'Content-Type: application/json'
        ];

        // Make the API request
        $response = $this->curlRequester->make_curl_request($url, json_encode($payload), 10, $headers);
        $httpCode = $this->curlRequester->get_response_code();

        if ($httpCode !== 200) {
            error_log('Response Body: ' . $response);
            $debugInfo = $this->curlRequester->get_last_request_details();
            throw new Exception('API request failed with HTTP code: ' . $httpCode . '. Debug: ' . json_encode($debugInfo));
        }

        $responseData = json_decode($response, true);

        if (!isset($responseData['bill']['shortId'])) {
            throw new Exception('Invalid API response: shortId is missing.');
        }

        // Generate the payment URL using shortId
        $shortId = $responseData['bill']['shortId'];
        $paymentUrl = "https://pay.nicky.me/home?paymentId=" . $shortId;

        $this->di['db']->exec(
            'UPDATE invoice SET notes = :notes WHERE id = :invoice_id',
            [
                ':notes' => 'shortId: ' . $shortId,
                ':invoice_id' => $invoice->id,
            ]
        );

        // Return a form with a button redirecting to the payment URL
        $form = sprintf('
            <a href="%s" class="btn btn-success btn-lg" role="button" style="text-decoration: none;">
                Proceed to Payment
            </a>
            ',
            htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8')
        );

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Nicky"');
        $bindings = [
            ':auth_token' => $this->config['auth_token'],
            ':amount' => $params['amount'],
            ':currency' => $invoice->currency,
            ':description' => $params['description'],
            ':buyer_email' => $invoice->buyer_email,
            ':buyer_name' => trim($invoice->buyer_first_name . ' ' . $invoice->buyer_last_name),
            ':callbackUrl' => $payGatewayService->getCallbackUrl($payGateway, $invoice),
            ':redirectUrl' => $this->di['tools']->url('invoice/' . $invoice->hash),
            ':invoice_hash' => $invoice->hash,
        ];

        return strtr($form, $bindings);
    }

}

class CurlRequester
{
    private ?int $responseCode = null;
    private ?string $lastUrl = null;
    private ?array $lastHeaders = null;
    private ?string $lastPostfields = null;
    private ?string $lastError = null;

    /**
     * Make cURL request with debugging information
     * @param string $url
     * @param string $postfields
     * @param int $timeout
     * @param array $headers
     * @return string|null
     */
    public function make_curl_request(string $url, string $postfields, int $timeout = 5, array $headers = [])
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastPostfields = $postfields;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec($ch);
        $this->responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($server_output === false) {
            $this->lastError = curl_error($ch);
            $server_output = json_encode(['error' => 'Invalid URL or connection timeout', 'curl_error' => $this->lastError]);
        }

        curl_close($ch);
        return $server_output;
    }

    /**
     * Make a cURL GET request with debugging information
     * 
     * @param string $url The endpoint URL to send the GET request to.
     * @param array $headers Optional array of HTTP headers to include in the request.
     * @param int $timeout Optional timeout in seconds for the connection and request (default: 5).
     * 
     * @return string The response body from the GET request as a JSON string or error message.
     * 
     * @throws Exception If cURL fails to execute the request or encounters an error.
     */
    public function make_curl_get_request(string $url, array $headers = [], int $timeout = 5): string
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec($ch);
        $this->responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($server_output === false) {
            $this->lastError = curl_error($ch);
            $server_output = json_encode(['error' => 'Invalid URL or connection timeout', 'curl_error' => $this->lastError]);
        }

        curl_close($ch);
        return $server_output;
    }

    /**
     * Get last HTTP response code
     * @return int|null
     */
    public function get_response_code(): ?int
    {
        return $this->responseCode;
    }

    /**
     * Get the last request details
     * @return array
     */
    public function get_last_request_details(): array
    {
        return [
            'url' => $this->lastUrl,
            'headers' => $this->lastHeaders,
            'postfields' => $this->lastPostfields,
            'error' => $this->lastError,
        ];
    }
}