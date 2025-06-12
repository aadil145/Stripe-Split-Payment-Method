jQuery(function($){
    console.log('SPLIT JS LOADED');

    // Debug function to check element existence
    function debugElement(id) {
        const element = $(id);
        console.log(`Element ${id}:`, {
            exists: element.length > 0,
            visible: element.is(':visible'),
            html: element.prop('outerHTML')
        });
    }

    // Debug form elements on load
    console.log('Initial form elements check:');
    debugElement('#second-card-number');
    debugElement('#second-card-expiry');
    debugElement('#second-card-cvc');
    debugElement('#second-payment-amount');
    debugElement('#enable-split-payment');

    // Only initialize if we're on checkout page and Stripe is available
    if (typeof Stripe === 'undefined') {
        console.error('Stripe is not available');
        return;
    }

    if (!$('form.checkout').length) {
        console.error('Not on checkout page');
        return;
    }

    // Try to get Stripe key from WooCommerce Stripe settings first, then fall back to our settings
    const stripeKey = (window.wc_stripe_params && window.wc_stripe_params.key) || 
                     (window.splitStripeVars && window.splitStripeVars.stripePublicKey);

    if (!stripeKey) {
        console.error('Stripe key not found in either WooCommerce settings or split payment settings');
        return;
    }

    console.log('Initializing Stripe with key:', stripeKey);
    const stripe = Stripe(stripeKey);
    const elements = stripe.elements();
    
    // Get currency symbol from WooCommerce
    const currencySymbol = window.wc_cart_params ? window.wc_cart_params.currency_symbol : '$';
    
    // Configure Stripe Elements style
    const style = {
        base: {
            color: '#2c3338',
            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
            fontSmoothing: 'antialiased',
            fontSize: '16px',
            '::placeholder': {
                color: '#6b7280'
            },
            ':-webkit-autofill': {
                color: '#2c3338'
            },
            ':focus': {
            color: 'inherit',
        },
        ':hover': {
            color: 'inherit',
            }
        },
        invalid: {
            color: '#dc3545',
            iconColor: '#dc3545'
        }
    };

    // Create separate card elements with proper options
    const cardNumber = elements.create('cardNumber', {
        style: style,
        placeholder: '1234 5678 9012 3456',
        showIcon: true
    });

    const cardExpiry = elements.create('cardExpiry', {
        style: style,
        placeholder: 'MM / YY'
    });

    const cardCvc = elements.create('cardCvc', {
        style: style,
        placeholder: 'CVC'
    });

    // Function to mount Stripe elements
    function mountStripeElements() {
        console.log('Mounting Stripe elements');
        try {
            // Check if elements exist before mounting
            if ($('#second-card-number').length) {
                console.log('Mounting card number element');
                cardNumber.mount('#second-card-number');
            } else {
                console.error('Card number element not found');
            }

            if ($('#second-card-expiry').length) {
                console.log('Mounting card expiry element');
                cardExpiry.mount('#second-card-expiry');
            } else {
                console.error('Card expiry element not found');
            }

            if ($('#second-card-cvc').length) {
                console.log('Mounting card CVC element');
                cardCvc.mount('#second-card-cvc');
            } else {
                console.error('Card CVC element not found');
            }
        } catch (error) {
            console.error('Error mounting Stripe elements:', error);
        }
    }

    // Mount elements initially
    mountStripeElements();

    // Add event listeners for validation
    cardNumber.on('change', function(event) {
        console.log('Card number changed:', event);
        if (event.error) {
            showError(event.error.message);
            return false;
        } else {
            clearError();
        }
    });

    cardExpiry.on('change', function(event) {
        console.log('Card expiry changed:', event);
        if (event.error) {
            showError(event.error.message);
            return false;
        } else {
            clearError();
        }
    });

    cardCvc.on('change', function(event) {
        console.log('Card CVC changed:', event);
        if (event.error) {
            showError(event.error.message);
            return false;
        } else {
            clearError();
        }
    });

    // Function to update max amount display with animation
    function updateMaxAmount() {
        console.log('Updating max amount');
        // Get cart total from WooCommerce cart updates or initial value
        const total = parseFloat($('[data-total-amount]').attr('data-total-amount') || 
                               window.splitStripeVars.cartTotal || 0).toFixed(2);
        const $maxAmount = $('#max-split-amount');
        const $maxAmount2 = $('#max-split-amount2');
        
        console.log('Cart total:', total);
        
        // Animate the number change
        $({ value: parseFloat($maxAmount.text()) || 0 }).animate(
            { value: total },
            {
                duration: 500,
                easing: 'swing',
                step: function() {
                    $maxAmount.text(this.value.toFixed(2));
                    $maxAmount2.text(this.value.toFixed(2));
                },
                complete: function() {
                    // Ensure final value is exactly correct
                    $maxAmount.text(total);
                    $maxAmount2.text(total);
                    // Update input max
                    $('#second-payment-amount').attr('max', total);
                    // Update payment display when max amount changes
                    updateMinimalPaymentDisplay();
                }
            }
        );
    }

    // Update max amount when cart is updated
    $(document.body).on('updated_cart_totals updated_checkout', function() {
        console.log('Cart updated, updating max amount');
        updateMaxAmount();
    });

    // Debounce function to limit how often a function can be called
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Function to bind amount input events
    function bindAmountEvents() {
        console.log('Binding amount input events');
        // Remove existing event handlers
        $('#second-payment-amount, #first-payment-amount').off('input change');
        
        // Flag to track which field is being updated
        let isUpdating = false;
        
        // Create debounced handlers
        const handleFirstAmountChange = debounce(function(input) {
            if (isUpdating) return;
            isUpdating = true;
            
            console.log('First card amount changed:', input.value);
            const value = parseFloat(input.value).toFixed(2) || 0;
            console.log("registered value second" + value);
            $('#first-payment-amount').val(value);
            const max = parseFloat($('#max-split-amount').text()) || 0;

            // Remove any existing validation classes
            $(input).removeClass('is-invalid is-valid');
            
            if (value <= 0) {
                $(input).addClass('is-invalid');
                input.setCustomValidity('Amount must be greater than 0');
                showError('Amount must be greater than 0');
            } else {
                $(input).addClass('is-valid');
                input.setCustomValidity('');
                clearError();
                
                // If value exceeds max, set it to max and second amount to 0
                if (value > max) {
                    $(input).val(parseFloat(max).toFixed(2));
                    $('#second-payment-amount').val('0.00');
                    // showError('Amount must be greater than 0');
                } else {
                    // Otherwise, update second amount to remaining value
                    const secondAmount = (parseFloat(max) - parseFloat(value)).toFixed(2);
                    $('#second-payment-amount').val(secondAmount);
                    if (parseFloat(secondAmount) <= 0) {
                        showError('Amount must be greater than 0');
                    }
                }
            }
            
            // Update payment summary immediately
            updateMinimalPaymentDisplay();
            
            isUpdating = false;
        }, 900); // 300ms debounce time

        const handleSecondAmountChange = debounce(function(input) {
            if (isUpdating) return;
            isUpdating = true;
            
            console.log('Second card amount changed:', input.value);
            const value = parseFloat(input.value).toFixed(2) || 0;
            $('#second-payment-amount').val(value);
            console.log("registered value second" + value);
            const max = parseFloat($('#max-split-amount').text()) || 0;

            // Remove any existing validation classes
            $(input).removeClass('is-invalid is-valid');
            
            if (value <= 0) {
                $(input).addClass('is-invalid');
                input.setCustomValidity('Amount must be greater than 0');
                showError('Amount must be greater than 0');
            } else {
                $(input).addClass('is-valid');
                input.setCustomValidity('');
                clearError();
                
                // If value exceeds max, set it to max and first amount to 0
                if (value > max) {
                    $(input).val(parseFloat(max).toFixed(2));
                    $('#first-payment-amount').val('0.00');
                    // showError('Amount must be greater than 0');
                } else {
                    // Otherwise, update first amount to remaining value
                    const firstAmount = (parseFloat(max) - parseFloat(value)).toFixed(2);
                    $('#first-payment-amount').val(firstAmount);
                    if (parseFloat(firstAmount) <= 0) {
                        showError('Amount must be greater than 0');
                    }
                }
            }
            
            // Update payment summary immediately
            updateMinimalPaymentDisplay();
            
            isUpdating = false;
        }, 900); // 300ms debounce time
        
        // Add event handlers for first card amount
        $('#first-payment-amount').on('input', function() {
            handleFirstAmountChange(this);
        });
        
        // Add event handlers for second card amount
        $('#second-payment-amount').on('input', function() {
            handleSecondAmountChange(this);
        });
    }

    // Function to generate and update minimal payment display
    function updateMinimalPaymentDisplay() {
        console.log('Updating minimal payment display');
        
        const $minimalInfo = $('.split-payment-minimal-info');
        const $details = $('#second-card-details');

        if ($details.is(':visible')) {
            const firstAmount = parseFloat($('#first-payment-amount').val()) || 0;
            const secondAmount = parseFloat($('#second-payment-amount').val()) || 0;
            const total = parseFloat($('#max-split-amount').text()) || 0;
            
            console.log('Minimal Payment amounts in updateMinimalPaymentDisplay:', {
                firstAmount,
                secondAmount,
                total
            });
            
            // const content = `
            //     <span class="split-payment-minimal-info">
            //         Split: ${currencySymbol}${firstAmount} (First) | ${currencySymbol}${secondAmount} (Second)
            //     </span>
            // `;
            const content = '';
            if ($minimalInfo.length === 0) {
                // Insert after the split-payment-help element which contains the max amount text
                const $targetElement = $('.split-payment-help');
                console.log('Target element for minimal insertion:', {
                    exists: $targetElement.length > 0,
                    html: $targetElement.prop('outerHTML')
                });
                
                if ($targetElement.length) {
                    $targetElement.after($(content).hide().fadeIn(300));
                } else {
                    console.error('Could not find target element .split-payment-help for minimal display insertion.');
                }
            } else {
                 // Update content and ensure visibility
                $minimalInfo.html(content).show(); 
            }
        } else {
             // If checkbox is not checked, remove the minimal display
            $minimalInfo.fadeOut(300, function() {
                $(this).remove();
            });
        }
    }

    // Function to initialize split payment functionality
    function initializeSplitPayment() {
        console.log('Initializing split payment functionality');

        // Get toggle link and details elements
        const $toggleLink = $('#enable-split-payment-toggle');
        const $details = $('#second-card-details');
        const $firstAmountField = $('#first-payment-amount').closest('.form-row');

        console.log('Checkbox elements found:', {
            toggleLink: $toggleLink.length,
            details: $details.length,
            firstAmountField: $firstAmountField.length
        });

        // Add a flag to prevent multiple rapid executions
        let isProcessingToggle = false;

        // Function to handle toggle link clicks
        function handleToggleClick(event) {
            event.preventDefault(); // Prevent default link behavior
            
            // If already processing a toggle, ignore this click
            if (isProcessingToggle) {
                console.log('Ignoring rapid toggle click.');
                return;
            }

            // Set flag and reset after a short delay
            isProcessingToggle = true;
            setTimeout(() => {
                isProcessingToggle = false;
            }, 400); // Adjust delay as needed, should be slightly longer than slide animation

            console.log(`Toggle link clicked:`);
            console.log('handleToggleClick executed.');
            
            const isVisible = $details.is(':visible');
            console.log('Details section visible before toggle:', isVisible);
            
            if (!isVisible) { // If currently hidden, slide down
                console.log('Sliding down details section');
                $details.slideDown({
                    duration: 300,
                    start: function() {
                        console.log('Slide down started');
                        // Add a class to indicate the section is expanded
                        $toggleLink.addClass('expanded');
                        // Show first amount field
                        $firstAmountField.slideDown(300);
                    },
                    complete: function() {
                        console.log('Slide down completed');
                        // Update the link text and arrow for the expanded state
                        $toggleLink.html('Pay with single card <span class="toggle-arrow">&#x25B4;</span>');
                        updateMaxAmount();
                        $('#first-payment-amount').val(parseFloat($('[data-total-amount]').attr('data-total-amount') || 
                    window.splitStripeVars.cartTotal || 0).toFixed(2));
                        // Ensure Stripe element is visible and enabled
                        cardNumber.update({disabled: false});
                        cardExpiry.update({disabled: false});
                        cardCvc.update({disabled: false});
                        // Bind amount events
                        bindAmountEvents();
                        // Update minimal payment display
                        updateMinimalPaymentDisplay();
                    }
                });
            } else {
                console.log('Sliding up details section');
                $details.slideUp(300, function() {
                    console.log('Slide up completed');
                    // Update the link text and arrow for the collapsed state
                    $toggleLink.html('Split payment with a second card <span class="toggle-arrow">&#x25BE;</span>');
                    // Hide first amount field
                    $firstAmountField.slideUp(300);
                    // Remove split payment fields when collapsed
                    $('input[name="second_card_token"]').remove();
                    $('input[name="second_payment_amount"]').val('');
                    
                    // Clear any errors
                    $('#second-card-errors').text('');
                    // Disable Stripe element
                    cardNumber.update({disabled: true});
                    cardExpiry.update({disabled: true});
                    cardCvc.update({disabled: true});
                    // Remove minimal payment display
                    $('.split-payment-minimal-info').remove();
                });
            }
        }

        // Bind events using delegation
        function bindToggleEvents() {
             console.log('Binding toggle link events using delegation.');

            // Direct binding
             $toggleLink.on('click', handleToggleClick);

            // Event delegation as backup
             $(document).on('click', '#enable-split-payment-toggle', handleToggleClick);
         }

        // Bind events immediately using delegation
        bindToggleEvents(); // Bind delegated events once on initialization
        // Bind amount events initially (even if not visible, handlers will be attached)
        bindAmountEvents();
        
        // Initial display update on load
        updateMinimalPaymentDisplay();
    }

    // Initialize when document is ready
    initializeSplitPayment();

    // Re-initialize when WooCommerce updates the checkout
    $(document.body).on('updated_checkout', function() {
        console.log('Checkout updated, re-initializing split payment');
        initializeSplitPayment();
        // Re-mount Stripe elements
        mountStripeElements();
         // Update minimal payment display after checkout update
        updateMinimalPaymentDisplay();
    });

    // Also update when the second card details section becomes visible
    $('#second-card-details').on('show', function() {
        console.log('Second card details shown, updating payment display');
        updateMinimalPaymentDisplay();
    });

    // Function to show error message with animation
    function showError(message) {
        console.log('Showing error:', message);
        const $error = $('#second-card-errors');
        $error.fadeOut(200, function() {
            $(this).html(message).fadeIn(200);
        });
    }

    // Function to clear error message with animation
    function clearError() {
        console.log('Clearing error');
        $('#second-card-errors').fadeOut(200);
    }

    // Add styles for the split payment summary
    $('<style>')
        .text(`
            .split-payment-info {
                margin: 15px 0;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 4px;
                border: 1px solid #e9ecef;
                clear: both;
            }
            .split-payment-amounts {
                margin-top: 10px;
            }
            .amount-row {
                display: flex;
                justify-content: space-between;
                margin: 5px 0;
                padding: 5px 0;
                font-size: 14px;
            }
            .amount-row.total {
                border-top: 1px solid #e5e5e5;
                margin-top: 10px;
                padding-top: 10px;
                font-weight: 600;
                font-size: 16px;
            }
            .amount {
                font-weight: 500;
            }
            .form-row.split-payment-amount {
                margin-bottom: 15px;
            }
            .split-payment-help {
                margin-bottom: 10px;
                font-size: 14px;
                color: #666;
                display: block;
            }
            .is-invalid {
                border-color: #dc3545 !important;
                box-shadow: 0 0 0 1px #dc3545 !important;
            }
            .is-valid {
                border-color: #198754 !important;
                box-shadow: 0 0 0 1px #198754 !important;
            }
            /* Stripe Elements Styling */
            .split-stripe-element {
                background-color: #fff;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                box-shadow: 0 1px 3px 0 #e6ebf1;
                transition: box-shadow 150ms ease;
            }
            .split-stripe-element.StripeElement--focus {
                box-shadow: 0 1px 3px 0 #cfd7df;
            }
            .split-stripe-element.StripeElement--invalid {
                border-color: #dc3545;
            }
            .split-stripe-element.StripeElement--webkit-autofill {
                background-color: #fefde5 !important;
            }
            /* Card Element Specific Styles */
            #second-card-number {
                margin-bottom: 10px;
            }
            /* Expiry and CVC Row Styles */
            .card-details-row {
                display: flex;
                gap: 10px;
                margin-bottom: 10px;
            }
            .card-details-row .col-6 {
                flex: 0 0 calc(50% - 5px);
                max-width: calc(50% - 5px);
            }
            /* Form Label Styles */
            .form-label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                color: #2c3338;
            }
            /* Error Message Styles */
            .split-payment-error {
                color: #dc3545;
                font-size: 14px;
                margin-top: 8px;
                padding: 8px 12px;
                border-radius: 4px;
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
            }
            /* Card Container Styles */
            .split-payment-card {
                background: #fff;
                padding: 0;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            /* Input Container Styles */
            .form-row {
                margin-bottom: 15px;
            }
            /* Amount Input Styles */
            #second-payment-amount {
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
                transition: border-color 150ms ease;
            }
            #second-payment-amount:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
                outline: none;
            }
            /* Checkbox Styles */
            #enable-split-payment {
                margin-right: 8px;
            }
            /* Loading Spinner Styles */
            .loading-spinner {
                width: 30px;
                height: 30px;
                border: 3px solid #f3f3f3;
                border-top: 3px solid #2271b1;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `)
        .appendTo('head');
    
    // Add event listener for amount changes
    $('#second-payment-amount').on('input change', function() {
        console.log('Amount changed, updating display');
        updateMinimalPaymentDisplay();
    });

    // Function to handle the second card payment
    function handleSecondCardPayment() {
        console.log('Handling second card payment');

            // Get the second payment amount
            const secondAmount = parseFloat($('#second-payment-amount').val()) || 0;
        const total = parseFloat($('#max-split-amount').text()) || 0;
        
        console.log('Second payment details:', {
            amount: secondAmount,
            total: total
        });

        // Validate the amount
            if (secondAmount <= 0) {
                showError('Please enter a valid amount for the second payment');
                return false;
            }

        if (secondAmount >= total) {
            showError('Second payment amount must be less than the total amount');
                return false;
            }

        // Create payment method with the second card
        stripe.createPaymentMethod({
                        type: 'card',
                        card: cardNumber,
                        billing_details: {
                // Add billing details if needed
                        }
        }).then(function(result) {
            console.log('Payment method creation result:', result);

            if (result.error) {
                // Show error to customer
                showError(result.error.message);
                console.error('Error creating payment method:', result.error);
                return false;
                    }

            // Add the payment method ID to a hidden field
            if (!$('#second_card_token').length) {
                    $('<input>').attr({
                        type: 'hidden',
                    id: 'second_card_token',
                        name: 'second_card_token',
                    value: result.paymentMethod.id
                    }).appendTo('form.checkout');
            } else {
                $('#second_card_token').val(result.paymentMethod.id);
            }

            console.log('Second card token saved:', result.paymentMethod.id);

            // Add a flag to indicate split payment is enabled
            if (!$('#enable_split_payment').length) {
                        $('<input>').attr({
                            type: 'hidden',
                    id: 'enable_split_payment',
                            name: 'enable_split_payment',
                    value: 'yes'
                        }).appendTo('form.checkout');
                    }

            // Add the second payment amount
            if (!$('#second_payment_amount').length) {
                $('<input>').attr({
                    type: 'hidden',
                    id: 'second_payment_amount',
                    name: 'second_payment_amount',
                    value: secondAmount
                }).appendTo('form.checkout');
            } else {
                $('#second_payment_amount').val(secondAmount);
            }

            // Add the original cart total
            if (!$('#cart_total_at_split').length) {
                $('<input>').attr({
                    type: 'hidden',
                    id: 'cart_total_at_split',
                    name: 'cart_total_at_split',
                    value: total
                }).appendTo('form.checkout');
        } else {
                $('#cart_total_at_split').val(total);
        }

            console.log('Split payment data saved:', {
                token: result.paymentMethod.id,
                amount: secondAmount,
                total: total
    });

            return true;
        }).catch(function(error) {
            console.error('Error in handleSecondCardPayment:', error);
            showError('An error occurred while processing the second card. Please try again.');
            return false;
        });
    }

    // Add validation before form submission
    $('form.checkout').on('checkout_place_order', function(e) {
        console.log('Checkout form submission');
        
        // Check if the split payment details section is visible
        if ($('#second-card-details').is(':visible')) {
            console.log('Split payment is enabled, validating second card');
            
            // Validate the second card
            if (!cardNumber._complete || !cardExpiry._complete || !cardCvc._complete) {
                showError('Please complete all card details for the second payment');
                return false;
        }

            // Handle the second card payment
            return handleSecondCardPayment();
        }
        
        return true;
    });
});