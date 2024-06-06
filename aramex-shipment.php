<?php
/*
Plugin Name: Aramex Shipment Integration
Description: Custom integration for Aramex shipment and invoice management.
Version: 1.0
Author: Bakry Abdelsalam
*/

add_action('add_meta_boxes', 'aramex_add_invoice_meta_box');

function aramex_add_invoice_meta_box() {
    add_meta_box(
        'aramex_invoice_meta_box',
        __('Aramex Invoice', 'woocommerce'),
        'aramex_invoice_meta_box_callback',
        'shop_order',
        'side',
        'default'
    );
}

function aramex_invoice_meta_box_callback($post) {
    $shipment_label_url = get_post_meta($post->ID, 'alameed_shipment_label', true);

    if ($shipment_label_url) {
        echo '<a href="' . esc_url($shipment_label_url) . '" target="_blank" class="button button-primary">' . __('Download Aramex Invoice', 'woocommerce') . '</a>';
    } else {
        echo '<p>' . __('No invoice available.', 'woocommerce') . '</p>';
    }
}

function aramex_create_shipment($order_id) {
    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // Fetch credentials securely
    $username = 'accounting@laftah.com';
    $password = 'Laftah1984@@1984';
    $account_number = '60499952';
    $account_pin = '332432';
    $account_entity = 'JED';
    $account_country_code = 'SA';

    $wsdl_url = 'https://ws.aramex.net/ShippingAPI.V2/Shipping/Service_1_0.svc?wsdl';
    $client = new SoapClient($wsdl_url);

    $params = array(
        'ClientInfo' => array(
            'UserName' => $username,
            'Password' => $password,
            'AccountNumber' => $account_number,
            'AccountPin' => $account_pin,
            'AccountEntity' => $account_entity,
            'AccountCountryCode' => $account_country_code,
            'Version' => 'v1.0',
        ),
        'Shipments' => array(
            array(
                'Shipper' => array(
                    'Reference1' => $order->get_id(),
                    'AccountNumber' => $account_number,
                    'PartyAddress' => array(
                        'Line1' => 'الطائف - الصناعية - حي نخب',
                        'City' => 'Taif',
                        'PostCode' => '26516',
                        'CountryCode' => 'SA',
                    ),
                    'Contact' => array(
                        'PersonName' => 'متجر لفتة للعبايات',
                        'CompanyName' => 'Laftah',
                        'PhoneNumber1' => '+966504392962',
                        'CellPhone' => '+966504392962',
                        'EmailAddress' => 'salesstore@laftah.com',
                    ),
                ),
                'Consignee' => array(
                    'Reference1' => $order->get_id(),
                    'PartyAddress' => array(
                        'Line1' => $order->get_shipping_address_1(),
                        'Line2' => $order->get_shipping_address_2(),
                        'City' => $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
                        'StateOrProvinceCode' => $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state(),
                        'PostCode' => $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
                        'CountryCode' => $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country(),
                    ),
                    'Contact' => array(
                        'PersonName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                        'CompanyName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                        'PhoneNumber1' => $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone(),
                        'CellPhone' => $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone(),
                    ),
                ),
                'Reference1' => $order->get_id(),
                'ShippingDateTime' => date('Y-m-d\TH:i:s'),
                'DueDate' => date('Y-m-d\TH:i:s', strtotime('+2 weeks')),
                'Details' => array(
                    'NumberOfPieces' => 1,
                    'ActualWeight' => array(
                        'Value' => '0.9',
                        'Unit' => 'kg',
                    ),
                    'ProductGroup' => $order->get_shipping_country() == 'SA' ? 'DOM' : 'EXP',
                    'ProductType' => 'ONP',
                    'PaymentType' => 'P',
                    'Services' => $order->get_payment_method() == 'cod' ? 'CODS' : '',
                    'CashOnDeliveryAmount' => array(
                        'Value' => $order->get_payment_method() == 'cod' ? $order->get_total() : 0,
                        'CurrencyCode' => $order->get_payment_method() == 'cod' ? 'SAR' : '',
                    ),
                    'DescriptionOfGoods' => implode(', ', array_map(function($item) {
                        return $item->get_name() . ' عدد:' . $item->get_quantity();
                    }, $order->get_items())),
                    'GoodsOriginCountry' => 'SA',
                    'Items' => array(),
                ),
            ),
        ),
        'Transaction' => array(
            'Reference1' => '',
        ),
        'LabelInfo' => array(
            'ReportID' => 9729,
            'ReportType' => 'URL',
        ),
    );

    try {
        $response = $client->CreateShipments($params);
        if (!$response->HasErrors) {
            $processed_shipment = $response->Shipments->ProcessedShipment[0];
            $shipment_number = $processed_shipment->ID;
            $url = $processed_shipment->ShipmentLabel->LabelURL;

            update_wc_order_status($order, $shipment_number, $url);
        } else {
            // Handle errors
            error_log('Aramex API Error: ' . print_r($response->Notifications, true));
        }
    } catch (Exception $e) {
        // Handle exception
        error_log('Aramex API Exception: ' . $e->getMessage());
    }
}

// Update order status and add metadata
function update_wc_order_status($order, $shipment_number, $url) {
    $order_id = $order->get_id();
    $status = $order->get_status();

    if ($status == 'under-process') {
        $new_status = 'on-hold';
    } else {
        $new_status = $status;
    }

    $order->update_status($new_status);
    $order->update_meta_data('alameed_delivered', 'false');
    $order->update_meta_data('alameed_shipment_label', $url); // Save the shipment label URL
    $order->update_meta_data('ced_aramex_awno', $shipment_number);
    $order->save();
}

// Hook into WooCommerce order status changed action to create Aramex shipment
add_action('woocommerce_order_status_changed', 'create_aramex_shipment_on_status_change', 10, 4);

function create_aramex_shipment_on_status_change($order_id, $old_status, $new_status, $order) {
    if ($new_status === 'processing') {
        aramex_create_shipment($order_id);
    }
}

// Add refund handling with YITH Refund plugin
add_action('yith_ywrac_request_refund_status_updated', 'aramex_handle_refund', 10, 2);

function aramex_handle_refund($request_id, $new_status) {
    if ($new_status === 'approved') {
        $order_id = get_post_meta($request_id, '_ywrac_order_id', true);
        $order = wc_get_order($order_id);
        if ($order) {
            // Implement your refund logic here
            aramex_process_refund($order_id);
        }
    }
}

function aramex_process_refund($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // Hardcoded credentials
    $username = 'accounting@laftah.com';
    $password = 'Laftah1984@@1984';
    $account_number = '60499952';
    $account_pin = '332432';
    $account_entity = 'JED';
    $account_country_code = 'SA';

    $wsdl_url = 'https://ws.aramex.net/ShippingAPI.V2/Shipping/Service_1_0.svc?wsdl';
    $client = new SoapClient($wsdl_url);

    $params = array(
        'ClientInfo' => array(
            'UserName' => $username,
            'Password' => $password,
            'AccountNumber' => $account_number,
            'AccountPin' => $account_pin,
            'AccountEntity' => $account_entity,
            'AccountCountryCode' => $account_country_code,
            'Version' => 'v1.0',
        ),
        'Shipments' => array(
            array(
                'Shipper' => array(
                    'Reference1' => $order->get_id(),
                    'AccountNumber' => $account_number,
                    'PartyAddress' => array(
                        'Line1' => 'الطائف - الصناعية - حي نخب',
                        'City' => 'Taif',
                        'PostCode' => '26516',
                        'CountryCode' => 'SA',
                    ),
                    'Contact' => array(
                        'PersonName' => 'متجر لفتة للعبايات',
                        'CompanyName' => 'Laftah',
                        'PhoneNumber1' => '+966504392962',
                        'CellPhone' => '+966504392962',
                        'EmailAddress' => 'salesstore@laftah.com',
                    ),
                ),
                'Consignee' => array(
                    'Reference1' => $order->get_id(),
                    'PartyAddress' => array(
                        'Line1' => $order->get_shipping_address_1(),
                        'Line2' => $order->get_shipping_address_2(),
                        'City' => $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
                        'StateOrProvinceCode' => $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state(),
                        'PostCode' => $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
                        'CountryCode' => $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country(),
                    ),
                    'Contact' => array(
                        'PersonName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                        'CompanyName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                        'PhoneNumber1' => $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone(),
                        'CellPhone' => $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone(),
                    ),
                ),
                'Reference1' => $order->get_id(),
                'ShippingDateTime' => date('Y-m-d\TH:i:s'),
                'DueDate' => date('Y-m-d\TH:i:s', strtotime('+2 weeks')),
                'Details' => array(
                    'NumberOfPieces' => 1,
                    'ActualWeight' => array(
                        'Value' => '0.9',
                        'Unit' => 'kg',
                    ),
                    'ProductGroup' => $order->get_shipping_country() == 'SA' ? 'DOM' : 'EXP',
                    'ProductType' => 'ONP',
                    'PaymentType' => 'P',
                    'Services' => $order->get_payment_method() == 'cod' ? 'CODS' : '',
                    'CashOnDeliveryAmount' => array(
                        'Value' => $order->get_payment_method() == 'cod' ? $order->get_total() : 0,
                        'CurrencyCode' => $order->get_payment_method() == 'cod' ? 'SAR' : '',
                    ),
                    'DescriptionOfGoods' => implode(', ', array_map(function($item) {
                        return $item->get_name() . ' عدد:' . $item->get_quantity();
                    }, $order->get_items())),
                    'GoodsOriginCountry' => 'SA',
                    'Items' => array(),
                ),
            ),
        ),
        'Transaction' => array(
            'Reference1' => '',
        ),
        'LabelInfo' => array(
            'ReportID' => 9729,
            'ReportType' => 'URL',
        ),
    );

    try {
        $response = $client->CreateShipments($params);
        error_log('Aramex API response: ' . print_r($response, true));
        if (!$response->HasErrors) {
            $processed_shipment = $response->Shipments->ProcessedShipment[0];
            $shipment_number = $processed_shipment->ID;
            $url = $processed_shipment->ShipmentLabel->LabelURL;
            update_wc_order_status($order, $shipment_number, $url);
        } else {
            // Handle errors
            error_log('Aramex API Error: ' . print_r($response->Notifications, true));
        }
    } catch (Exception $e) {
        // Handle exception
        error_log('Aramex API Exception: ' . $e->getMessage());
    }
    

function create_aramex_shipment_on_status_change($order_id, $old_status, $new_status, $order) {
    if ($new_status === 'processing') {
        error_log('Order status changed to processing for order ID: ' . $order_id);
        aramex_create_shipment($order_id);
    }
}



