<?php
class ControllerExtensionPaymentPointCheckOutPay extends Controller {
    public function index() {
        $this->load->language('extension/payment/pointcheckout_pay');
        return $this->load->view('extension/payment/pointcheckout_pay');
        
    }
    
    //Stage 1 Sending data to pointcheckout and redirect user to paymet page if success
    
    public function send() {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        
        $_BASE_URL='';
        if ($this->config->get('payment_pointcheckout_pay_test') == '2'){
            $_BASE_URL='https://pay.staging.pointcheckout.com';
        }elseif(!$this->config->get('payment_pointcheckout_pay_test')){
            $_BASE_URL='https://pay.pointcheckout.com';
        }else{
            $_BASE_URL='https://pay.test.pointcheckout.com';
        }
        
        $headers = array(
            'Content-Type: application/json',
            'Api-Key:'.$this->config->get('payment_pointcheckout_pay_key'),
            'Api-Secret:'.$this->config->get('payment_pointcheckout_pay_secret'),
        );
        
        $products= $this->model_checkout_order->getOrderProducts($this->session->data['order_id']);
        $items = array();
        $i = 0;
        foreach ($products as $product){
            $item = (object) array(
                'name'=> $product['name'],
                'sku' => $product['product_id'],
                'quantity' => $product['quantity'],
                'total' => $product['total']);
            $items[$i++] = $item;
        }
        
        $storeOrder = array();
        $storeOrder['referenceId'] = $order_info['order_id'];
        $storeOrder['items'] = array_values($items);
        //collecting totals
        $order_data = array();
        
        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0;
        
        // Because __call can not keep var references so we put them into an array.
        $total_data = array(
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        );
        
        $this->load->model('setting/extension');
        
        $sort_order = array();
        
        $results = $this->model_setting_extension->getExtensions('total');
        
        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }
        
        array_multisort($sort_order, SORT_ASC, $results);
        
        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);
                
                // We have to put the totals in an array so that they pass by reference.
                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }
        
        $sort_order = array();
        
        foreach ($totals as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }
        
        array_multisort($sort_order, SORT_ASC, $totals);
        $order_data['totals'] = $totals;
        //looping totals and store data in our storeOrder
        foreach ($order_data['totals'] as $total) {
            switch( $total['code']){
                case 'sub_total':
                    $storeOrder['subtotal'] = $total['value'];
                    break;
                case 'shipping':
                    $storeOrder['shipping'] = $total['value'];
                    break;
                case 'tax':
                    if (isset ($storeOrder['tax'])){
                        $storeOrder['tax']+=$total['value'];
                    }else{
                        $storeOrder['tax'] = $total['value'];
                    }
                    break;
                case 'discount':
                    $storeOrder['discount'] = $total['value'];
                    break;
                case 'total':
                    $storeOrder['grandtotal'] = $total['value'];
                    break;
            }
        }
        $storeOrder['currency'] = $order_info['currency_code'];
        //prepare customer Information
        $customer = array();
        $customer['firstname'] = $order_info['firstname'];
        $customer['lastname'] = $order_info['lastname'];
        $customer['email'] = $order_info['email'];
        $customer['phone'] = $order_info['telephone'];
        
        $billingAddress = array();
        $billingAddress['name'] = $order_info['payment_firstname'].' '.$order_info['payment_lastname'];
        $billingAddress['address1'] = $order_info['payment_address_1'];
        $billingAddress['address2'] = $order_info['payment_address_2'];
        $billingAddress['city'] = $order_info['payment_city'];
        $billingAddress['state'] = $order_info['payment_city'];
        $billingAddress['country'] = $order_info['payment_country'];
        
        $shippingAddress = array();
        $shippingAddress['name'] = $order_info['shipping_firstname'].' '.$order_info['shipping_lastname'];
        $shippingAddress['address1'] = $order_info['shipping_address_1'];
        $shippingAddress['address2'] = $order_info['shipping_address_2'];
        $shippingAddress['city'] = $order_info['shipping_city'];
        $shippingAddress['state'] = $order_info['shipping_city'];
        $shippingAddress['country'] = $order_info['shipping_country'];
        
        $customer['billingAddress'] = $billingAddress;
        $customer['shippingAddress'] = $shippingAddress;
        
        $storeOrder['customer'] = $customer;
        //convert storeOrder array to json format object
        $storeOrder = json_encode($storeOrder);
        //open http connection
        $curl = curl_init($_BASE_URL.'/api/v1.0/checkout');
        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $storeOrder);
        //sending request
        $response = curl_exec($curl);
        //close connection
        curl_close($curl);
        //log error if response is failure
        if (!$response) {
            $this->log->write('Pointcheckout payment failed: ' . curl_error($curl) . '(' . curl_errno($curl) . ')');
        }
        
        $response_info = json_decode($response);
        //prepare response to pointcheckout payment tag ajax request
        $json = array();
        if (($response_info->success == 'true')) {
            $message = '';
            if (isset($response_info->result)) {
                $resultData = $response_info->result;
                if (isset($resultData->checkoutId)){
                    $message .= 'checkout Id :' . $resultData->checkoutId . "\n";
                    $this->session->data['checkoutId']=$resultData->checkoutId;
                }
                if (isset($resultData->merchentId)) {
                    $message .= 'merchent Id :' . $resultData->merchentId . "\n";
                }
                if (isset($resultData->merchentName)){
                    $message .= 'merchent Name :' . $resultData->merchentName . "\n";
                }
                if (isset($resultData->referenceId)){
                    $message .= 'reference Id :' . $resultData->referenceId . "\n";
                }
                if (isset($resultData->currency)){
                    $message .= 'currency :' . $resultData->currency . "\n";
                }
                if (isset($resultData->grandtotal)){
                    $message .= 'grandtotal :' . $resultData->grandtotal . "\n";
                }
                if (isset($resultData->status)){
                    $message .= 'status :' . $resultData->status. "\n";
                }
                $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('payment_pointcheckout_pay_order_status_id'), $message, false);
            }
            $json['success'] = $_BASE_URL.'/checkout/'.$resultData->checkoutKey;//$this->url->link('checkout/success');
        } else {
            $json['error'] = $response_info->error ;
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
    
    //Stage 2 Finalize Payment after user return back from payment page either success or failure
    
    public function confirm() {
        
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        
        $_BASE_URL='';
        if ($this->config->get('payment_pointcheckout_pay_test') == '2'){
            $_BASE_URL='https://pay.staging.pointcheckout.com';
        }elseif(!$this->config->get('payment_pointcheckout_pay_test')){
            $_BASE_URL='https://pay.pointcheckout.com';
        }else{
            $_BASE_URL='https://pay.test.pointcheckout.com';
        }
        
        $headers = array(
            'Content-Type: application/json',
            'Api-Key:'.$this->config->get('payment_pointcheckout_pay_key'),
            'Api-Secret:'.$this->config->get('payment_pointcheckout_pay_secret'),
        );
        $curl = curl_init($_BASE_URL.'/api/v1.0/checkout/'.$this->session->data['checkoutId']);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($curl);
        echo $response;
        curl_close($curl);
        
        if (!$response) {
            $this->log->write('DoDirectPayment failed: ' . curl_error($curl) . '(' . curl_errno($curl) . ')');
        }
        
        $response_info = json_decode($response);
        //check response and redirect user to either success or failure page
        if (($response_info->success == 'true' && $response_info->result->status =='PAID')) {
            $successurl = $this->url->link('checkout/success');
            ob_start();
            header('Location: '.$successurl);
            ob_end_flush();
            die();
        }else{
            $failureurl = $this->url->link('checkout/failure');
            ob_start();
            header('Location: '.$failureurl);
            ob_end_flush();
            die();
        }
        
    }
    
}