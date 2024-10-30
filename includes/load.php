<?php

use Billplz\EDD\API;
use Billplz\EDD\WPConnect;

// registers the gateway
function billplz_edd_register_gateway($gateways)
{
    $display_name = edd_get_option('billplz_display_name', false);

    if (!$display_name) {
        $display_name = __('Billplz', 'eddbillplzplugin');
    }
    $gateways['billplz'] = array('admin_label' => 'Billplz', 'checkout_label' => $display_name);
    return $gateways;
}
add_filter('edd_payment_gateways', 'billplz_edd_register_gateway');

function pw_edd_billplz_cc_form()
{
    // register the action to remove default CC form
    return;
}
add_action('edd_billplz_cc_form', 'pw_edd_billplz_cc_form');

function billplz_process_payment($purchase_data)
{
    if (! wp_verify_nonce($purchase_data['gateway_nonce'], 'edd-gateway')) {
        wp_die(__('Nonce verification has failed', 'easy-digital-downloads'), __('Error', 'easy-digital-downloads'), array( 'response' => 403 ));
    }

    // payment processing happens here
    global $edd_options;
    $purchase_summary = edd_get_purchase_summary($purchase_data);

    /**********************************
    * setup the payment details
    **********************************/

    $payment = array(
        'price' => $purchase_data['price'],
        'date' => $purchase_data['date'],
        'user_email' => $purchase_data['user_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency' => $edd_options['currency'],
        'downloads' => $purchase_data['downloads'],
        'cart_details' => $purchase_data['cart_details'],
        'user_info' => $purchase_data['user_info'],
        'status' => 'pending'
    );

    $parameter = array(
        'collection_id' => edd_get_option('billplz_collection_id', false),
        'email'=> $payment['user_email'],
        'mobile'=>'',
        'name'=> $payment['user_info']['first_name'] . ' ' . $payment['user_info']['last_name'],
        'amount'=> strval($payment['price'] * 100),
        'callback_url'=> billplz_edd_listener_url(),
        'description'=> mb_substr($purchase_summary, 0, 200)
    );

    // record the pending payment
    $payment_id = edd_insert_payment($payment);

    $optional = array(
        'redirect_url' => billplz_edd_listener_url(),
        'reference_2_label' => 'ID',
        'reference_2' => $payment_id
    );

    $api_key = edd_get_option('billplz_api_key', false);

    $connnect = (new WPConnect($api_key))->detectMode();
    $billplz = new API($connnect);
    list($rheader, $rbody) = $billplz->toArray($billplz->createBill($parameter, $optional));

    if ($rheader !== 200) {
        wp_die(__('Something went wrong! ' . print_r($rbody, true), 'eddbillplzplugin'));
    }

    if (! add_post_meta($payment_id, 'billplz_id', $rbody['id'], true)) {
        update_post_meta($payment_id, 'billplz_id', $rbody['id']);
    }

    if (! add_post_meta($payment_id, 'billplz_api_key', $api_key, true)) {
        update_post_meta($payment_id, 'billplz_api_key', $api_key);
    }

    if (! add_post_meta($payment_id, 'billplz_paid', 'false', true)) {
        update_post_meta($payment_id, 'billplz_paid', 'false');
    }

    // Redirect to Billplz
    wp_redirect($rbody['url']);
    exit;
}
add_action('edd_gateway_billplz', 'billplz_process_payment');

function billplz_edd_listener_url()
{
    $passphrase = get_option('billplz_edd_listener', false);
    if (!$passphrase) {
        $passphrase = md5(site_url() . time());
        update_option('billplz_edd_listener', $passphrase);
    }
    return add_query_arg('billplz_edd_action', $passphrase, site_url('/'));
}

// adds the settings to the Payment Gateways section
function billplz_edd_add_settings($settings)
{
    $billplz_gateway_settings = array(
        array(
            'id' => 'billplz_settings',
            'name' => '<strong>' . __('Billplz Payment Gateway Settings', 'eddbillplzplugin') . '</strong>',
            'desc' => __('Configure Billplz Payment Gateway Settings', 'eddbillplzplugin'),
            'type' => 'header'
        ),
        array(
            'id' => 'billplz_display_name',
            'name' => __('Billplz Display Name', 'eddbillplzplugin'),
            'desc' => __('Display name on user checkout.', 'eddbillplzplugin'),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id' => 'billplz_api_key',
            'name' => __('API Secret Key', 'eddbillplzplugin'),
            'desc' => __('Get Your API Key at Billplz >> Account Settings', 'eddbillplzplugin'),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id' => 'billplz_collection_id',
            'name' => __('Collection ID', 'eddbillplzplugin'),
            'desc' => __('Get Your Collection ID at Billplz >> Billing', 'eddbillplzplugin'),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id' => 'billplz_x_signature',
            'name' => __('X Signature Key', 'eddbillplzplugin'),
            'desc' => __('Get Your X Signature Key at Billplz >> Account Settings', 'eddbillplzplugin'),
            'type' => 'text',
            'size' => 'regular'
        ),
    );

    return array_merge($settings, $billplz_gateway_settings);
}
add_filter('edd_settings_gateways', 'billplz_edd_add_settings');

function edd_listen_for_billplz_ipn()
{
    if (!isset($_GET['billplz_edd_action'])) {
        return;
    }
    $passphrase = get_option('billplz_edd_listener', false);
    if (!$passphrase) {
        return;
    }
    if ($_GET['billplz_edd_action'] != $passphrase) {
        return;
    }

    $api_key = edd_get_option('billplz_api_key', false);
    $x_signature = edd_get_option('billplz_x_signature', false);

    try {
        $data = WPConnect::getXSignature($x_signature);
        if ($data['type'] === 'callback') {
            edd_debug_log('Billplz IPN endpoint loaded & verified successfully');
        }
    } catch (\Exception $e) {
        edd_record_gateway_error(__('IPN Error', 'easy-digital-downloads'), sprintf(__('Invalid IPN verification response. IPN data: %s', 'easy-digital-downloads'), $e->getMessage()));
        edd_debug_log('Invalid IPN verification response. IPN data: ' . $e->getMessage());
        exit($e->getMessage());
    }

    $connnect = (new WPConnect($api_key))->detectMode();
    $billplz = new API($connnect);
    list($rheader, $rbody) = $billplz->toArray($billplz->getBill($data['id']));

    
    $payment_id = absint($rbody['reference_2']);
    $payment = new EDD_Payment($payment_id);
    if ($payment->gateway != 'billplz') {
        return; // this isn't a Billplz IPN
    }

    if ('myr' != strtolower($payment->currency)) {
        edd_record_gateway_error(__('IPN Error', 'easy-digital-downloads'), sprintf(__('Invalid currency setup. IPN data: %s', 'easy-digital-downloads'), json_encode($data)), $payment_id);
        edd_debug_log('Invalid currency setup. IPN data: ' . print_r($data, true));
        edd_update_payment_status($payment_id, 'failed');
        edd_insert_payment_note($payment_id, __('Payment failed due to invalid currency setup.', 'easy-digital-downloads'));
        return;
    }

    if ($rbody['paid']) {
        if (get_post_status($payment_id) == 'publish') {
            // Do nothing
        } else {
            // Retrieve the total purchase amount (before Billplz)
            $payment_amount = edd_get_payment_amount($payment_id);

            if (number_format((float) ($rbody['amount']/100), 2) < number_format((float) $payment_amount, 2)) {
                // The prices don't match
                edd_record_gateway_error(__('IPN Error', 'easy-digital-downloads'), sprintf(__('Invalid payment amount in IPN response. IPN data: %s', 'easy-digital-downloads'), json_encode($rbody)), $payment_id);
                edd_debug_log('Invalid payment amount in IPN response. IPN data: ' . printf($data, true));
                edd_update_payment_status($payment_id, 'failed');
                edd_insert_payment_note($payment_id, __('Payment failed due to invalid amount in Billplz IPN.', 'easy-digital-downloads'));
                return;
            }

            edd_insert_payment_note($payment_id, sprintf(__('Billplz Bill ID: %s', 'easy-digital-downloads'), $data['id']));
            edd_set_payment_transaction_id($payment_id, $data['id']);
            edd_update_payment_status($payment_id, 'publish');
            update_post_meta($payment_id, 'billplz_paid', 'true');
        }
    }

    if ($data['type'] === 'redirect' && $rbody['paid']) {
        edd_send_to_success_page();
    } elseif ($data['type'] === 'redirect' && !$rbody['paid']) {
        //edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
        header('Location: '.edd_get_failed_transaction_uri('?payment-id=' . $payment_id));
    }

    exit('Successful Callback');
}

add_action('init', 'edd_listen_for_billplz_ipn');

function edd_billplz_delete_bill($post_id)
{
    $post_type = get_post_type($post_id);

    if ($post_type !== 'edd_payment') {
        return;
    }

    /* Allow hack to disable bill deletion */
    if (defined('BEDD_DISABLE_DELETE') && BEDD_DISABLE_DELETE) {
        return;
    }
    $bill_id = get_post_meta($post_id, 'billplz_id', true);
    $api_key = get_post_meta($post_id, 'billplz_api_key', true);
    $status  = get_post_meta($post_id, 'billplz_paid', true);

    if (empty($bill_id) || empty($api_key) || empty($status)) {
        return;
    }

    if ($status === 'true') {
        delete_post_meta($post_id, 'billplz_id');
        delete_post_meta($post_id, 'billplz_api_key');
        delete_post_meta($post_id, 'billplz_paid');
        return;
    }

    $connnect = (new WPConnect($api_key))->detectMode();
    $billplz = new API($connnect);
    list($rheader, $rbody) = $billplz->toArray($billplz->deleteBill($bill_id));

    if ($rheader !== 200) {
        list($rheader, $rbody) = $billplz->toArray($billplz->getBill($bill_id));
        if (!$rbody['paid']) {
            wp_die(__('Bill cannot be deleted. Message: ' . print_r($rbody, true), 'eddbillplzplugin'));
        }
    }
}

/*
 * Delete Bills before deleting pending payment
 */
add_action('before_delete_post', 'edd_billplz_delete_bill', 10, 1);
