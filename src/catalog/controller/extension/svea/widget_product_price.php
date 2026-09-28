<?php

class ControllerExtensionSveaWidgetProductPrice extends Controller
{
    public function index($productInfo)
    {
        $this->load->model('localisation/country');
        $this->load->model('extension/payment/svea_partpayment');
        $this->load->language('extension/svea/product_price');

        $country = $this->model_localisation_country->getCountry($this->config->get('config_country_id'));
        $currency = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');

        if ($this->model_extension_payment_svea_partpayment->getProductPriceMode() !== '1'
            || empty($country)
            || $country['iso_code_2'] !== 'SE'
            || $currency !== 'SEK'
            || ($this->config->get('config_customer_price') && !$this->customer->isLogged())) {
            return '';
        }

        $this->document->addStyle('catalog/view/theme/default/stylesheet/svea/product_price.css');
        $this->document->addScript('catalog/view/theme/default/javascript/svea/product_price.js');

        $data = array(
            'product_id' => (int)$productInfo['product_id'],
            'currency' => $currency,
            'calculation_url' => html_entity_decode($this->url->link('extension/svea/widget_product_price/calculation', '', true), ENT_QUOTES, 'UTF-8'),
            'text_trigger' => $this->language->get('text_trigger'),
            'text_information' => $this->language->get('text_information'),
            'text_dialog_title' => $this->language->get('text_dialog_title'),
            'text_loading' => $this->language->get('text_loading'),
            'text_error' => $this->language->get('text_error'),
            'text_unavailable' => $this->language->get('text_unavailable'),
            'text_retry' => $this->language->get('text_retry'),
            'text_close' => $this->language->get('text_close')
        );

        return $this->load->view('extension/svea/widget_product_price', $data);
    }

    public function calculation()
    {
        // The session OCMOD sees this request-local flag and skips the shutdown write.
        $this->session->data['svea_product_calculation_read_only'] = true;

        $this->load->language('extension/svea/product_price');

        $response = array('status' => 'error');
        $productId = isset($this->request->post['product_id']) ? (int)$this->request->post['product_id'] : 0;
        $amountValue = isset($this->request->post['amount']) ? trim((string)$this->request->post['amount']) : '';
        $currency = isset($this->request->post['currency']) ? strtoupper(trim((string)$this->request->post['currency'])) : '';
        $sessionCurrency = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');

        $this->load->model('catalog/product');
        $this->load->model('localisation/country');
        $productInfo = $productId ? $this->model_catalog_product->getProduct($productId) : array();
        $country = $this->model_localisation_country->getCountry($this->config->get('config_country_id'));

        if (!$productInfo
            || !is_numeric($amountValue)
            || !is_finite((float)$amountValue)
            || (float)$amountValue <= 0
            || $currency !== 'SEK'
            || $sessionCurrency !== $currency
            || empty($country)
            || $country['iso_code_2'] !== 'SE'
            || ($this->config->get('config_customer_price') && !$this->customer->isLogged())) {
            $response['status'] = 'unavailable';
            $this->sendJson($response);
            return;
        }

        $this->load->model('extension/payment/svea_partpayment');
        $calculation = $this->model_extension_payment_svea_partpayment->getDynamicPaymentPlans(
            (float)$amountValue,
            $currency,
            $country['iso_code_2']
        );

        if ($calculation['status'] === 'success') {
            $campaign = $this->selectLowestMonthlyCampaign($calculation['campaigns']);

            if ($campaign) {
                $data = array(
                    'amount' => $this->formatNumber($calculation['amount'], 2),
                    'currency' => $calculation['currency'],
                    'campaign_description' => htmlspecialchars((string)$campaign['description'], ENT_QUOTES, 'UTF-8'),
                    'contract_length' => $campaign['contractLengthInMonths'],
                    'interest_rate' => $this->formatNumber($campaign['interestRatePercent'], 2),
                    'initial_fee' => $this->formatNumber($campaign['initialFee'], 0),
                    'notification_fee' => $this->formatNumber($campaign['notificationFee'], 0),
                    'monthly_amount' => $this->formatNumber($campaign['monthlyAmountToPay'], 0),
                    'total_amount' => $this->formatNumber($campaign['totalAmountToPay'], 0),
                    'effective_interest' => $this->formatNumber($campaign['effectiveInterestRate'], 2),
                    'text_example_title' => $this->language->get('text_example_title'),
                    'text_credit_amount' => $this->language->get('text_credit_amount'),
                    'text_campaign' => $this->language->get('text_campaign'),
                    'text_term' => $this->language->get('text_term'),
                    'text_months' => $this->language->get('text_months'),
                    'text_frequency' => $this->language->get('text_frequency'),
                    'text_monthly' => $this->language->get('text_monthly'),
                    'text_interest' => $this->language->get('text_interest'),
                    'text_variable' => $this->language->get('text_variable'),
                    'text_initial_fee' => $this->language->get('text_initial_fee'),
                    'text_notification_fee' => $this->language->get('text_notification_fee'),
                    'text_average_monthly' => $this->language->get('text_average_monthly'),
                    'text_total' => $this->language->get('text_total'),
                    'text_effective_interest' => $this->language->get('text_effective_interest'),
                    'credit_warning' => $this->load->view('extension/svea/credit_warning', array())
                );

                $response = array(
                    'status' => 'success',
                    'html' => $this->load->view('extension/svea/product_price_example', $data)
                );
            } else {
                $response['status'] = 'unavailable';
            }
        } elseif ($calculation['status'] === 'unavailable') {
            $response['status'] = 'unavailable';
        }

        $this->sendJson($response);
    }

    private function selectLowestMonthlyCampaign($campaigns)
    {
        $selected = null;

        foreach ($campaigns as $campaign) {
            if ($selected === null
                || $campaign['monthlyAmountToPay'] < $selected['monthlyAmountToPay']
                || ($campaign['monthlyAmountToPay'] == $selected['monthlyAmountToPay']
                    && ($campaign['monthlyAnnuityFactor'] < $selected['monthlyAnnuityFactor']
                        || ($campaign['monthlyAnnuityFactor'] == $selected['monthlyAnnuityFactor']
                            && strcmp((string)$campaign['campaignCode'], (string)$selected['campaignCode']) < 0)))) {
                $selected = $campaign;
            }
        }

        return $selected;
    }

    private function formatNumber($value, $maximumDecimals)
    {
        $decimals = $maximumDecimals;

        if ($maximumDecimals > 0 && floor((float)$value) == (float)$value) {
            $decimals = 0;
        }

        return number_format((float)$value, $decimals, ',', ' ');
    }

    private function sendJson($data)
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }
}
