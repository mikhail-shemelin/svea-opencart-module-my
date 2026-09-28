<?php

require_once(DIR_APPLICATION . '../svea/config/configInclude.php');

use \Svea\WebPay\Helper\PaymentPlanHelper\PaymentPlanCalculator;

class ModelExtensionPaymentsveapartpayment extends Model
{
    private $paymentString = "payment_";

    public function setVersionStrings()
    {
        if (VERSION < 3.0) {
            $this->paymentString = "";
        }
    }

    public function getMethod($address, $total)
    {
        $this->setVersionStrings();

        $this->load->language('extension/payment/svea_partpayment');

        $countryCode = $address['iso_code_2'] ?? '';

        if ($this->config->get($this->paymentString . 'svea_partpayment_status')) {
            $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get($this->paymentString . 'svea_partpayment_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

            if ($this->config->get($this->paymentString . 'svea_partpayment_min_amount_$countryCode') > $this->currency->getValue($this->session->data['currency']) * $total) {
                $status = false;
            } elseif (!$this->config->get($this->paymentString . 'svea_partpayment_geo_zone_id')) {
                $status = true;
            } elseif ($query->num_rows) {
                $status = true;
            } else {
                $status = false;
            }
        } else {
            $status = false;
        }

        $method_data = array();

        if ($status) {
            $method_data = array(
                'code'       => 'svea_partpayment',
                'title'      => $this->language->get('text_title') . ' ' . $this->config->get($this->paymentString . 'svea_partpayment_payment_description'),
                'terms'      => '',
                'sort_order' => $this->config->get($this->paymentString . 'svea_partpayment_sort_order')
            );
        }

        return $method_data;
    }

    public function getPaymentPlanParams($countryCode)
    {
        $this->setVersionStrings();

        $table_name = DB_PREFIX . "svea_wp_campaigns";

        $query = "SELECT `campaignCode`,`description`,`paymentPlanType`,`contractLengthInMonths`,
                `monthlyAnnuityFactor`,`initialFee`, `notificationFee`,`interestRatePercent`,
                `numberOfInterestFreeMonths`,`numberOfPaymentFreeMonths`,`fromAmount`,`toAmount`
                FROM `" . $table_name . "`
                WHERE `timestamp`=(SELECT MAX(timestamp) FROM `" . $table_name . "` WHERE `countryCode` = '" . $countryCode . "' )
                AND `countryCode` = '" . $countryCode . "'
                ORDER BY `monthlyAnnuityFactor` ASC";

        $query = $this->db->query($query);

        if ($query->num_rows) {
            $rows = array();

            foreach ($query->rows as $row) {
                $rows[] = (object)$row;
            }

            $svea['campaignCodes'] = $rows;

            return (object)$svea;
        }
    }

    /**
     * Fetches amount-specific Swedish payment-plan terms and keeps Svea's
     * EffectiveInterest together with SDK monthly and total calculations.
     */
    public function getDynamicPaymentPlans($amount, $currency, $countryCode)
    {
        $this->setVersionStrings();

        $amount = (float)$amount;
        $currency = strtoupper((string)$currency);
        $countryCode = strtoupper((string)$countryCode);

        if ($countryCode !== 'SE' || $currency !== 'SEK' || !is_finite($amount) || $amount <= 0) {
            return array('status' => 'unavailable', 'campaigns' => array());
        }

        $testmodeKey = $this->paymentString . 'svea_partpayment_testmode_' . $countryCode;

        if ($this->config->get($testmodeKey) === null) {
            return array('status' => 'error', 'campaigns' => array());
        }

        $config = $this->config->get($testmodeKey) == '1'
            ? new OpencartSveaConfigTest($this->config, $this->paymentString . 'svea_partpayment')
            : new OpencartSveaConfig($this->config, $this->paymentString . 'svea_partpayment');

        try {
            $endpoint = preg_replace('/\\?WSDL$/i', '', $config->getEndPoint('PAYMENTPLAN'));
            $requestXml = $this->buildPaymentPlanRequestXml(
                $config->getUsername('PAYMENTPLAN', $countryCode),
                $config->getPassword('PAYMENTPLAN', $countryCode),
                $config->getClientNumber('PAYMENTPLAN', $countryCode),
                $amount
            );
            $responseXml = $this->requestPaymentPlans($endpoint, $requestXml);
            $campaigns = $this->parsePaymentPlanResponse($responseXml, $amount);

            if (!$campaigns) {
                return array('status' => 'unavailable', 'campaigns' => array());
            }

            usort($campaigns, function ($left, $right) {
                if ($left['monthlyAnnuityFactor'] == $right['monthlyAnnuityFactor']) {
                    return strcmp((string)$left['campaignCode'], (string)$right['campaignCode']);
                }

                return ($left['monthlyAnnuityFactor'] < $right['monthlyAnnuityFactor']) ? -1 : 1;
            });

            return array(
                'status' => 'success',
                'amount' => $amount,
                'currency' => $currency,
                'campaigns' => $campaigns
            );
        } catch (Exception $exception) {
            $this->log->write('Svea: Amount-specific payment-plan calculation failed: ' . get_class($exception));

            return array('status' => 'error', 'campaigns' => array());
        }
    }

    private function buildPaymentPlanRequestXml($username, $password, $clientNumber, $amount)
    {
        if ($username === '' || $password === '' || $clientNumber === '') {
            throw new RuntimeException('Missing payment-plan credentials');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $envelope = $document->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soap:Envelope');
        $document->appendChild($envelope);
        $body = $document->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soap:Body');
        $envelope->appendChild($body);
        $operation = $document->createElementNS('https://webservices.sveaekonomi.se/webpay', 'GetPaymentPlanParamsEu');
        $body->appendChild($operation);
        $request = $document->createElement('request');
        $operation->appendChild($request);
        $auth = $document->createElement('Auth');
        $request->appendChild($auth);
        $this->appendTextElement($document, $auth, 'Username', (string)$username);
        $this->appendTextElement($document, $auth, 'Password', (string)$password);
        $this->appendTextElement($document, $auth, 'ClientNumber', (string)$clientNumber);
        $this->appendTextElement($document, $request, 'Amount', number_format($amount, 2, '.', ''));

        return $document->saveXML();
    }

    private function appendTextElement($document, $parent, $name, $value)
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }

    private function requestPaymentPlans($endpoint, $requestXml)
    {
        $curl = curl_init($endpoint);

        if ($curl === false) {
            throw new RuntimeException('Unable to initialise payment-plan request');
        }

        curl_setopt_array($curl, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestXml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "https://webservices.sveaekonomi.se/webpay/GetPaymentPlanParamsEu"'
            )
        ));

        $response = curl_exec($curl);
        $curlError = curl_errno($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($curlError || $response === false || $httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('Payment-plan request failed');
        }

        return $response;
    }

    private function parsePaymentPlanResponse($responseXml, $amount)
    {
        $previousEntityLoader = null;

        if (function_exists('libxml_disable_entity_loader')) {
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }

        $previousErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($responseXml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if ($previousEntityLoader !== null) {
            libxml_disable_entity_loader($previousEntityLoader);
        }

        if (!$loaded) {
            throw new RuntimeException('Invalid payment-plan response');
        }

        $xpath = new DOMXPath($document);

        if ($xpath->query('//*[local-name()="Fault"]')->length) {
            throw new RuntimeException('Payment-plan SOAP fault');
        }

        $result = $xpath->query('//*[local-name()="GetPaymentPlanParamsEuResult"]')->item(0);

        if (!$result) {
            throw new RuntimeException('Missing payment-plan result');
        }

        $accepted = strtolower($this->getDirectChildValue($result, 'Accepted'));
        $resultCode = $this->getDirectChildValue($result, 'ResultCode');

        if ($accepted !== 'true' || $resultCode !== '0') {
            throw new RuntimeException('Payment-plan response rejected');
        }

        $requiredNumericFields = array(
            'CampaignCode',
            'ContractLengthInMonths',
            'MonthlyAnnuityFactor',
            'InitialFee',
            'NotificationFee',
            'InterestRatePercent',
            'NumberOfInterestFreeMonths',
            'NumberOfPaymentFreeMonths',
            'FromAmount',
            'ToAmount',
            'EffectiveInterest'
        );
        $wholeMonthFields = array(
            'ContractLengthInMonths',
            'NumberOfInterestFreeMonths',
            'NumberOfPaymentFreeMonths'
        );
        $campaigns = array();

        foreach ($xpath->query('.//*[local-name()="CampaignCodeInfo"]', $result) as $campaignNode) {
            $raw = array();
            $valid = true;

            foreach ($requiredNumericFields as $field) {
                $value = $this->getDirectChildValue($campaignNode, $field);

                if ($value === '' || !is_numeric($value) || !is_finite((float)$value)) {
                    $valid = false;
                    break;
                }

                $raw[$field] = $value;
            }

            if ($valid) {
                foreach ($wholeMonthFields as $field) {
                    if ((float)$raw[$field] !== floor((float)$raw[$field])) {
                        $valid = false;
                        break;
                    }
                }
            }

            $paymentPlanType = $this->getDirectChildValue($campaignNode, 'PaymentPlanType');
            $description = $this->getDirectChildValue($campaignNode, 'Description');

            if (!$valid
                || $description === ''
                || !in_array($paymentPlanType, array('InterestFree', 'Standard', 'InterestAndAmortizationFree'), true)) {
                continue;
            }

            if ((float)$raw['CampaignCode'] <= 0
                || (int)$raw['ContractLengthInMonths'] <= 0
                || (float)$raw['MonthlyAnnuityFactor'] < 0
                || (float)$raw['InitialFee'] < 0
                || (float)$raw['NotificationFee'] < 0
                || (float)$raw['InterestRatePercent'] < 0
                || (int)$raw['NumberOfInterestFreeMonths'] < 0
                || (int)$raw['NumberOfPaymentFreeMonths'] < 0
                || (float)$raw['FromAmount'] < 0
                || (float)$raw['ToAmount'] < (float)$raw['FromAmount']
                || (float)$raw['EffectiveInterest'] < 0
                || $amount < (float)$raw['FromAmount']
                || $amount > (float)$raw['ToAmount']
                || (in_array($paymentPlanType, array('InterestFree', 'Standard'), true)
                    && (int)$raw['NumberOfPaymentFreeMonths'] >= (int)$raw['ContractLengthInMonths'])
                || ($paymentPlanType === 'Standard' && (float)$raw['InterestRatePercent'] <= 0)) {
                continue;
            }

            $campaign = array(
                'campaignCode' => $raw['CampaignCode'],
                'description' => $description,
                'paymentPlanType' => $paymentPlanType,
                'contractLengthInMonths' => (int)$raw['ContractLengthInMonths'],
                'monthlyAnnuityFactor' => (float)$raw['MonthlyAnnuityFactor'],
                'initialFee' => (float)$raw['InitialFee'],
                'notificationFee' => (float)$raw['NotificationFee'],
                'interestRatePercent' => (float)$raw['InterestRatePercent'],
                'numberOfInterestFreeMonths' => (int)$raw['NumberOfInterestFreeMonths'],
                'numberOfPaymentFreeMonths' => (int)$raw['NumberOfPaymentFreeMonths'],
                'fromAmount' => (float)$raw['FromAmount'],
                'toAmount' => (float)$raw['ToAmount'],
                'effectiveInterestRate' => (float)$raw['EffectiveInterest']
            );

            $monthlyAmount = PaymentPlanCalculator::getMonthlyAmountToPay($amount, $campaign, 0);
            $totalAmount = PaymentPlanCalculator::getTotalAmountToPay($amount, $campaign, 0);

            if (!is_finite((float)$monthlyAmount)
                || !is_finite((float)$totalAmount)
                || (float)$monthlyAmount < 0
                || (float)$totalAmount < 0) {
                continue;
            }

            $campaign['monthlyAmountToPay'] = $monthlyAmount;
            $campaign['totalAmountToPay'] = $totalAmount;
            $campaigns[] = $campaign;
        }

        return $campaigns;
    }

    private function getDirectChildValue(DOMNode $parent, $localName)
    {
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    public function getProductPriceMode()
    {
        $this->setVersionStrings();

        return $this->config->get($this->paymentString . 'svea_partpayment_product_price');
    }
}
