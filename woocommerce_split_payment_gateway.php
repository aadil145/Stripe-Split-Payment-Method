<?php
/**
 * Plugin Name: Split Stripe Payment
 * Description: Adds split payment option using two cards at WooCommerce checkout with Stripe.
 * Version: 1.0
 * Author: Sheroo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

// Check if WooCommerce Stripe Gateway is active
if ( ! in_array( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

class WC_Split_Stripe_Payment {

    public function __construct() {
        // Add UI elements
        add_action('after_stripe_first_card', [$this, 'add_split_payment_ui']);
        // add_action('woocommerce_checkout_before_terms_and_conditions', [$this, 'add_split_payment_ui']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Handle checkout data
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_split_payment_data'], 10, 2);
        
        // Process the second payment AFTER order has been created and status set
        add_action('woocommerce_thankyou', [$this, 'process_second_payment'], 5);
        
        // Filter the checkout amount for the first payment
        add_filter('woocommerce_calculated_total', [$this, 'adjust_payment_amount'], 999, 2);

        // Add split payment display to order details and thank you page
        add_action('woocommerce_order_details_after_order_table', [$this, 'display_split_payment_details']);
    }

    public function enqueue_scripts() {
        if (is_checkout()) {
            // Get WooCommerce Stripe Gateway instance
            $stripe_gateway = WC()->payment_gateways()->payment_gateways()['stripe'];
            
            // Check if Stripe gateway is enabled and configured
            if (!$stripe_gateway || !$stripe_gateway->enabled) {
                return;
            }

            // Get test/live mode status and appropriate keys
            $testmode = 'yes' === $stripe_gateway->get_option('testmode');
            $publishable_key = $testmode ? $stripe_gateway->get_option('test_publishable_key') : $stripe_gateway->get_option('publishable_key');
            
            // Only proceed if we have a valid publishable key
            if (empty($publishable_key)) {
                return;
            }

            // Get cart total
            $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;

            wp_enqueue_script('split-stripe-payment', plugin_dir_url(__FILE__) . 'split-payment.js', ['jquery', 'stripe'], null, true);
            
            // Pass Stripe settings to our script
            wp_localize_script('split-stripe-payment', 'splitStripeVars', [
                'stripePublicKey' => $publishable_key,
                'isTestMode' => $testmode,
                'currencySymbol' => get_woocommerce_currency_symbol(),
                'currency' => get_woocommerce_currency(),
                'cartTotal' => $cart_total
            ]);

            // Enqueue the split payment CSS file
            wp_enqueue_style('split-stripe-payment-css', plugin_dir_url(__FILE__) . 'split-payment.css', [], null);
        }
    }

    public function add_split_payment_ui() {
        // Get WooCommerce currency symbol
		//<label for="first-payment-amount" class="card-label">Amount for first card</label>
		//<label for="second-payment-amount" class="card-label">Amount for second card</label>
		
		
        $currency_symbol = get_woocommerce_currency_symbol();
       echo '<div class="form-row split-payment-amount" style="display: none;">
        
        <div class="amount-input-wrapper">
            <span class="currency-symbol">' . esc_html($currency_symbol) . '</span>
            <input type="number" 
                   id="first-payment-amount" 
                   name="first_payment_amount" 
                   step="0.01" 
                   min="0" 
                   placeholder="0.00"
                   class="form-controll wc-split-input">
        </div>
        <small class="split-payment-help form-text text-muted">Maximum amount: <span id="max-split-amount">0.00</span></small>
    </div>';
        echo '<div id="split-payment-section" class="wc-split-payments card">
            <div class="split-payment-checkbox p-3">
                <label class="checkbox-container d-block" style=" width:100%">
                    <a href="#" id="enable-split-payment-toggle" class="split-payment-toggle-link" style="font-size:14px;">
                        Split payment with a second card <span class="toggle-arrow">&#x25BE;</span>
                    </a>
                </label>
            </div>
            <div id="second-card-details" class="split-payment-details" style="display:none;">
                <div class="split-payment-header">
                    <h4 class="mb-4">Second Card Payment Details</h4>
                </div>
                
                <div class=" split-payment-card">
                    <div class="row">
                        
                        <div class="col-12">
                            <div class="form-roww">
                                <div class="col-12 mb-3 px-0 d-flex flex-column">
                                    <label class="card-label">Card Number<span class="required">*</span></label>
                                    <div id="second-card-number" class="split-stripe-element"></div>
                                </div>
                            </div>
                            <div class="form-roww">
                                <div class="card-details-row">
                                    <div class="col-6 d-flex flex-column">
                                        <label class="card-label">Expiry Date <span class="required">*</span></label>
                                        <div id="second-card-expiry" class="split-stripe-element"></div>
                                    </div>
                                    <div class="col-6 d-flex flex-column">
                                        <label class="card-label">Card Code (CVC) <span class="required">*</span></label>
                                        <div id="second-card-cvc" class="split-stripe-element"></div>
                                    </div>
                                </div>
                            </div>
                            <div id="second-card-errors" class="split-payment-error alert alert-danger mt-2" role="alert" style="display: none;"></div>
                        </div>
                    </div>
                </div>
                <div class="form-row split-payment-amount">
                    
                    <div class="amount-input-wrapper">
                        <span class="currency-symbol">' . esc_html($currency_symbol) . '</span>
                        <input type="number" 
                               id="second-payment-amount" 
                               name="second_payment_amount" 
                               step="0.01" 
                               min="0" 
                               placeholder="0.00"
                               class="form-controll wc-split-input">
                    </div>
                    <small class="split-payment-help form-text text-muted">Maximum amount: <span id="max-split-amount2">0.00</span></small>
                </div>
            </div>
        </div>';
    }

    /**
     * Save split payment data to order
     */
    public function save_split_payment_data($order_id, $posted_data) {
        $order = wc_get_order($order_id);
        
        // Fetch from $_POST, not $posted_data
        $enable_split_payment = isset($_POST['enable_split_payment']) ? 'yes' : 'no'; // Convert to yes/no
        $second_card_token = isset($_POST['second_card_token']) ? sanitize_text_field($_POST['second_card_token']) : '';
        $second_payment_amount = isset($_POST['second_payment_amount']) ? floatval($_POST['second_payment_amount']) : 0;

        if (!$order) {
            error_log('Split Payment Error: Order not found - ' . $order_id);
            return;
        }

        // Get the original cart total from session, saved before the filter modified it
        $cart_total = WC()->session->get('split_payment_original_total');
        // If session is somehow empty, fall back to order total (might be adjusted)
        if (empty($cart_total)) {
            $cart_total = $order->get_total();
            $order->add_order_note('WARNING: Original total not found in session, using order total.');
        }
        
        // Always save these values for debugging
        $order->update_meta_data('_enable_split_payment', $enable_split_payment);
        $order->update_meta_data('_second_card_token', $second_card_token);
        $order->update_meta_data('_second_payment_amount', $second_payment_amount);
        $order->update_meta_data('_cart_total_at_split', $cart_total);
        
        // Only proceed if split payment is enabled and we have a token
        if ($enable_split_payment === 'yes' && !empty($second_card_token)) {
            // Validate second payment amount
            if ($second_payment_amount <= 0) {
                $order->add_order_note('ERROR: Second payment amount must be greater than 0');
                return;
            }
            
            if ($second_payment_amount >= $cart_total) {
                $order->add_order_note('ERROR: Second payment amount (' . $second_payment_amount . ') must be less than cart total (' . $cart_total . ')');
                return;
            }
            
            // Save all required meta data
            $order->update_meta_data('_original_order_total', $cart_total);
            $order->update_meta_data('_split_payment_enabled', 'yes');
            
            // Update the order with the proper full amount
            //$order->set_total($cart_total);
            $order->save();
            
            // Add order note
            $order->add_order_note('Order placed with split payment. Second payment will be processed on order completion.');
            
            // Set order status to pending until second payment completes
            $order->update_status('pending', 'Waiting for second payment to complete');
        }

        // Save all changes
        $order->save();
    }
    
    /**
     * Adjust the order total amount for the first payment
     */
    public function adjust_payment_amount($total, $cart) {
        error_log('Split Payment Debug: adjust_payment_amount called with total = ' . $total);
        error_log('Split Payment Debug: $_POST[enable_split_payment] = ' . (isset($_POST['enable_split_payment']) ? $_POST['enable_split_payment'] : 'not set'));
        error_log('Split Payment Debug: $_POST[payment_method] = ' . (isset($_POST['payment_method']) ? $_POST['payment_method'] : 'not set'));
        // Store the original amount in session for later use
        WC()->session->set('split_payment_original_total', $total);
        // Only modify if split payment is enabled and the payment method is stripe
        if (isset($_POST['enable_split_payment']) && $_POST['enable_split_payment'] === 'yes' && isset($_POST['payment_method']) && $_POST['payment_method'] === 'stripe') {
            
           
            // Get the second payment amount from POST
            $second_payment_amount = isset($_POST['second_payment_amount']) ? floatval($_POST['second_payment_amount']) : 0;
            
            // Validate the amount
            if ($second_payment_amount <= 0 || $second_payment_amount >= $total) {
                error_log('Split Payment sTotal: ' . $total);
                return $total; // Return full amount if invalid
            }
            
            // Return the first payment amount (total minus second payment)
            error_log('Split Payment XTotal: ' . floatval($total - $second_payment_amount));
            return floatval($total - $second_payment_amount);
        }
        error_log('Split Payment Errora: ' . $total);
        return $total;
    }

    /**
     * Process the second payment on thank you page
     */
    public function process_second_payment($order_id) {
        // Get the order
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Split Payment Error: Order not found for second payment - ' . $order_id);
            return;
        }

        // Check if this order used split payment - check both flags for compatibility
        $split_enabled = $order->get_meta('_enable_split_payment') === 'yes' || $order->get_meta('_split_payment_enabled') === 'yes';
        if (!$split_enabled) {
            return;
        }

        // Check if we already processed the second payment
        if ($order->get_meta('_second_payment_processed') === 'yes') {
            return;
        }

        // Get the data we need
        $second_token = $order->get_meta('_second_card_token');
        $second_payment_amount = floatval($order->get_meta('_second_payment_amount'));
        $original_total = floatval($order->get_meta('_cart_total_at_split'));
        $first_payment_amount = $original_total - $second_payment_amount;

        // Set the order total back to the original total
        $order->set_total($original_total);
        $order->save(); // Save the order with the correct total

        // Log the payment attempt
        error_log('Processing second payment for order ' . $order_id . ' - Amount: ' . $second_payment_amount);

        // Validate all required data
        if (empty($second_token)) {
            $error_msg = 'Missing second card token';
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            $order->save();
            return;
        }

        if ($second_payment_amount <= 0) {
            $error_msg = 'Invalid second payment amount - must be greater than 0';
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            $order->save();
            return;
        }

        if ($second_payment_amount >= $original_total) {
            $error_msg = 'Second payment amount exceeds total - Amount: ' . $second_payment_amount . ', Total: ' . $original_total;
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            $order->save();
            return;
        }

        // Convert amount to cents for Stripe
        $second_amount = floatval($second_payment_amount * 100);

        // Load Stripe API
        require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

        // Get Stripe Gateway for API key
        $stripe_gateway = WC()->payment_gateways()->payment_gateways()['stripe'];
        if (!$stripe_gateway) {
            $error_msg = 'Stripe gateway not found';
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            $order->save();
            return;
        }

        $secret_key = $stripe_gateway->testmode ? $stripe_gateway->get_option('test_secret_key') : $stripe_gateway->get_option('secret_key');
        if (empty($secret_key)) {
            $error_msg = 'Stripe API key not configured';
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            $order->save();
            return;
        }
        
        \Stripe\Stripe::setApiKey($secret_key);

        try {
            // Create a PaymentIntent for the second card payment
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => $second_amount,
                'currency' => strtolower(get_woocommerce_currency()),
                'payment_method' => $second_token,
                'confirm' => true,
                'description' => 'Split Payment - Second Card - Order ' . $order_id,
                'metadata' => [
                    'order_id' => $order_id,
                    'split_payment' => 'second_card'
                ],
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never'
                ]
            ]);

            // Mark second payment as processed
            $order->update_meta_data('_second_payment_processed', 'yes');
            $order->update_meta_data('_second_payment_id', $payment_intent->id);
            $order->update_meta_data('_split_payment_status', 'completed');

            // Add note about the successful split payment
            $card_details = isset($payment_intent->payment_method_details->card) ? 
                          ($payment_intent->payment_method_details->card->brand . ' ****' . $payment_intent->payment_method_details->card->last4) : 
                          'N/A';
            $order->add_order_note('Split payment completed successfully for ' . wc_price($second_payment_amount, array('currency' => $order->get_currency())) . ' via ' . $card_details . '. Payment ID: ' . $payment_intent->id);

            // Set order to processing now that both payments are complete
            $order->update_status('processing', 'Split payment complete: Both payments processed successfully');
            
            error_log('Second payment processed successfully for order ' . $order_id . ' - Payment ID: ' . $payment_intent->id);
        } catch (\Stripe\Exception\CardException $e) {
            // Card was declined
            $error_msg = 'Card was declined: ' . $e->getMessage();
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            
            // Set order to on-hold to indicate manual review needed
            if ($order->get_status() !== 'failed') {
                $order->update_status('on-hold', 'Split payment incomplete: Second payment failed - Card declined');
            }
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters were supplied to Stripe's API
            $error_msg = 'Invalid request to Stripe: ' . $e->getMessage();
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            
            if ($order->get_status() !== 'failed') {
                $order->update_status('on-hold', 'Split payment incomplete: Second payment failed - Invalid request');
            }
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication with Stripe's API failed
            $error_msg = 'Stripe authentication failed: ' . $e->getMessage();
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            
            if ($order->get_status() !== 'failed') {
                $order->update_status('on-hold', 'Split payment incomplete: Second payment failed - Authentication error');
            }
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            // Network communication with Stripe failed
            $error_msg = 'Network error with Stripe: ' . $e->getMessage();
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            
            if ($order->get_status() !== 'failed') {
                $order->update_status('on-hold', 'Split payment incomplete: Second payment failed - Network error');
            }
        } catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            $error_msg = 'Unexpected error: ' . $e->getMessage();
            error_log('Split Payment Error: ' . $error_msg . ' - Order: ' . $order_id);
            $order->add_order_note('ERROR: ' . $error_msg);
            $order->update_meta_data('_split_payment_status', 'incomplete');
            $order->update_meta_data('_split_payment_error', $error_msg);
            
            if ($order->get_status() !== 'failed') {
                $order->update_status('on-hold', 'Split payment incomplete: Second payment failed - Unexpected error');
            }
        }

        // Save all changes
        $order->save();
    }

    /**
     * Display split payment details in order details and thank you page
     */
    public function display_split_payment_details($order_id) {
        $order = wc_get_order($order_id);
        
        // On the thank you page, re-fetch the order to ensure latest meta data is available
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) return;

        // Check if this is a split payment order
        $split_enabled = $order->get_meta('_enable_split_payment') === 'yes' || $order->get_meta('_split_payment_enabled') === 'yes';
        if (!$split_enabled) return;

        // Get payment details
        $second_payment_amount = floatval($order->get_meta('_second_payment_amount'));
        $second_payment_id = $order->get_meta('_second_payment_id');
        $original_total = floatval($order->get_meta('_cart_total_at_split'));
        $first_payment_amount = $original_total - $second_payment_amount;

        // Get payment status
        $second_payment_processed = $order->get_meta('_second_payment_processed') === 'yes';
        $split_payment_status = $order->get_meta('_split_payment_status');

        // Display the split payment details
        echo '<div class="split-payment-details-section">';
        echo '<h2>' . __('Split Payment Details', 'woocommerce') . '</h2>';
        echo '<table class="woocommerce-table shop_table split-payment-details">';
        echo '<tbody>';
        
        // First Payment
        echo '<tr>';
        echo '<th>' . __('First Card Payment', 'woocommerce') . '</th>';
        echo '<td>' . wc_price($first_payment_amount) . '</td>';
        echo '</tr>';
        
        // Second Payment
        echo '<tr>';
        echo '<th>' . __('Second Card Payment', 'woocommerce') . '</th>';
        echo '<td>';
        echo wc_price($second_payment_amount);
        // if ($second_payment_processed && $second_payment_id) {
        //     echo '<br><small class="payment-id">' . __('Payment ID:', 'woocommerce') . ' ' . esc_html($second_payment_id) . '</small>';
        // }
        echo '</td>';
        echo '</tr>';
        
        // Total
        echo '<tr class="total">';
        echo '<th>' . __('Total', 'woocommerce') . '</th>';
        echo '<td>' . wc_price($original_total) . '</td>';
        echo '</tr>';
        
        echo '</tbody>';
        echo '</table>';

        // Add payment status if second payment is not processed
        // if (!$second_payment_processed) {
        //     echo '<div class="split-payment-status notice notice-warning">';
        //     echo '<p>' . __('Second payment is pending processing.', 'woocommerce') . '</p>';
        //     echo '</div>';
        // }
        
        echo '</div>';

        // Add some CSS for the split payment details
        echo '<style>
            .split-payment-details-section {
                margin: 2em 0;
                padding: 1em;
                background: #f8f8f8;
                border-radius: 4px;
            }
            .split-payment-details-section h2 {
                margin-bottom: 1em;
                font-size: 1.2em;
            }
            .split-payment-details {
                width: 100%;
                margin-bottom: 1em;
            }
            .split-payment-details th {
                text-align: left;
                padding: 0.5em;
            }
            .split-payment-details td {
                text-align: right;
                padding: 0.5em;
            }
            .split-payment-details tr.total {
                font-weight: bold;
                border-top: 1px solid #ddd;
            }
            .split-payment-details .payment-id {
                color: #666;
                font-size: 0.9em;
            }
            .split-payment-status {
                margin-top: 1em;
                padding: 1em;
            }
        </style>';
    }
}

new WC_Split_Stripe_Payment();

// Add split payment display to admin order details page in a separate section
add_action('woocommerce_admin_order_totals_after_total', 'split_payment_admin_display_details', 10, 1);

// Filter order item totals to show correct total for split payments
add_filter('woocommerce_get_order_item_totals', 'filter_split_payment_order_total_display', 10, 3);

function filter_split_payment_order_total_display($total_rows, $order, $tax_display) {
    // Check if this is a split payment order
    $split_enabled = $order->get_meta('_enable_split_payment') === 'yes' || $order->get_meta('_split_payment_enabled') === 'yes';

    if ($split_enabled) {
        // Get the original total from meta data
        $original_total = floatval($order->get_meta('_cart_total_at_split'));

        // Ensure the original total is valid and greater than 0
        if ($original_total > 0) {
            // Replace the order total row with the original total
            $total_rows['order_total'] = array(
                'label' => $total_rows['order_total']['label'],
                'value' => wc_price($original_total, array('currency' => $order->get_currency()))
            );
        }
    }

    return $total_rows;
}

function split_payment_admin_display_details($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    // Check if this is a split payment order
    $split_enabled = $order->get_meta('_enable_split_payment') === 'yes' || $order->get_meta('_split_payment_enabled') === 'yes';
    if (!$split_enabled) return;

    // Get payment details
    $second_payment_amount = floatval($order->get_meta('_second_payment_amount'));
    $second_payment_id = $order->get_meta('_second_payment_id');
    $original_total = floatval($order->get_meta('_cart_total_at_split'));
    $first_payment_amount = $original_total - $second_payment_amount;

    // Get payment status
    $second_payment_processed = $order->get_meta('_second_payment_processed') === 'yes';

    // Display the split payment details in a table format similar to order totals
    echo '<tr class="split-payment-admin-details">';
    echo '<td class="label">' . __('First Card Payment:', 'woocommerce') . '</td>';
    echo '<td width="1%"></td>'; // Spacer column for alignment
    echo '<td class="total">' . wc_price($first_payment_amount, array('currency' => $order->get_currency())) . '</td>';
    echo '</tr>';

    echo '<tr class="split-payment-admin-details">';
    echo '<td class="label">' . __('Second Card Payment:', 'woocommerce') . '</td>';
    echo '<td width="1%"></td>'; // Spacer column
    echo '<td class="total">' . wc_price($second_payment_amount, array('currency' => $order->get_currency())) . '</td>';
    echo '</tr>';

    if ($second_payment_processed && $second_payment_id) {
        echo '<tr class="split-payment-admin-details split-payment-id-row">';
        echo '<td class="label"></td>'; // Empty label
        echo '<td width="1%"></td>'; // Spacer column
        // echo '<td class="total"><small class="second-payment-id">' . sprintf(__( 'Second Payment ID: %s', 'woocommerce' ), esc_html($second_payment_id)) . '</small></td>';
        echo '</tr>';
    }

    // Add some basic styling for this section
    echo '<style>
        .split-payment-admin-details td {
            padding: 2px 8px !important; /* Adjust padding to match other total rows */
            border: none !important;
        }
        .split-payment-admin-details .label {
            font-weight: normal !important; /* Match other total labels */
        }
         .split-payment-id-row td {
            padding-top: 0 !important;
        }
    </style>';
}

// Add split payment details to order emails after the order table
//add_action('woocommerce_email_after_order_table', 'split_payment_add_details_to_email', 10, 4);

function split_payment_add_details_to_email($order, $sent_to_admin, $plain_text, $email) {
    // Ensure it's a split payment order
    $split_enabled = $order->get_meta('_enable_split_payment') === 'yes' || $order->get_meta('_split_payment_enabled') === 'yes';
    if (!$split_enabled) return;

    // Get payment details
    $second_payment_amount = floatval($order->get_meta('_second_payment_amount'));
    $second_payment_id = $order->get_meta('_second_payment_id');
    $original_total = floatval($order->get_meta('_cart_total_at_split'));
    $first_payment_amount = $original_total - $second_payment_amount;

    // Only add details to customer emails (not admin new order email, which might fire before second payment)
    // and specifically for processing order email
    if (!$sent_to_admin && $email->id === 'customer_processing_order') {

        if ($plain_text) {
            // Plain text email format
            echo "\n" . strtoupper(__( 'Split Payment Details', 'woocommerce' )) . "\n";
            echo "----------------------------------------\n";
            echo __( 'First Card Payment:', 'woocommerce' ) . '\t' . wc_price( $first_payment_amount, array( 'currency' => $order->get_currency() ) ) . "\n";
            echo __( 'Second Card Payment:', 'woocommerce' ) . '\t' . wc_price( $second_payment_amount, array( 'currency' => $order->get_currency() ) ) . "\n";
            if ( ! empty( $second_payment_id ) ) {
                 echo __( 'Second Payment ID:', 'woocommerce' ) . '\t' . $second_payment_id . "\n";
            }
            echo "\n";
        } else {
            // HTML email format
            echo '<h2>' . esc_html__( 'Split Payment Details', 'woocommerce' ) . '</h2>';
            echo '<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 40px;" border="1">';
            echo '<tbody>';

            echo '<tr>';
            echo '<th class="td" scope="row" colspan="2" style="text-align:left;">' . esc_html__( 'First Card Payment:', 'woocommerce' ) . '</th>';
            echo '<td class="td" style="text-align:right;">' . wp_kses_post( wc_price( $first_payment_amount, array( 'currency' => $order->get_currency() ) ) ) . '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th class="td" scope="row" colspan="2" style="text-align:left;">' . esc_html__( 'Second Card Payment:', 'woocommerce' ) . '</th>';
            echo '<td class="td" style="text-align:right;">' . wp_kses_post( wc_price( $second_payment_amount, array( 'currency' => $order->get_currency() ) ) ) . '</td>';
            echo '</tr>';

            if ( ! empty( $second_payment_id ) ) {
                echo '<tr>';
                echo '<th class="td" scope="row" colspan="2" style="text-align:left;">' . esc_html__( 'Second Payment ID:', 'woocommerce' ) . '</th>';
                echo '<td class="td" style="text-align:right;">' . esc_html( $second_payment_id ) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }
    }
}