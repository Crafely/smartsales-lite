<?php

namespace AISMARTSALES\Includes\Api\App;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AppApiHandler
{

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // GET: Retrieve business data
        register_rest_route('ai-smart-sales/v1', '/app', [
            'methods' => 'GET',
            'callback' => [$this, 'get_app_data'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // PUT: Update business data
        register_rest_route('ai-smart-sales/v1', '/app', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_app_data'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => $this->get_update_args_schema(),
        ]);
    }

    public function check_permission($request)
    {
        // Check if user is logged in and has appropriate capabilities
        if (!is_user_logged_in()) {
            return false;
        }

        // Get current user
        $user = wp_get_current_user();

        // Check if user has any of our POS roles or is an administrator
        $allowed_roles = ['administrator', 'aipos_outlet_manager', 'aipos_cashier', 'aipos_shop_manager'];
        $user_roles = (array) $user->roles;

        if (!array_intersect($allowed_roles, $user_roles)) {
            return false;
        }

        return true;
    }

    public function check_admin_permission($request)
    {
        // Check if user is logged in and is an administrator
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'rest_forbidden',
                __('You must be logged in to access this resource.', 'crafely-smartsales-lite'),
                ['status' => 401]
            );
        }

        // Get current user
        $user = wp_get_current_user();

        // Only administrators can update app data
        if (!in_array('administrator', (array) $user->roles)) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to update app data. Administrator role required.', 'crafely-smartsales-lite'),
                ['status' => 403]
            );
        }

        return true;
    }

    public function get_app_data(WP_REST_Request $request)
    {
        // Sample WooCommerce store details (already present)
        $store_address = get_option('woocommerce_store_address', '123 Default St');
        $store_address_2 = get_option('woocommerce_store_address_2', '');
        $store_city = get_option('woocommerce_store_city', 'Default City');
        $store_postcode = get_option('woocommerce_store_postcode', '00000');
        $store_country = get_option('woocommerce_default_country', 'US');
        $currency = get_option('woocommerce_currency', 'USD');

        // Get inventory size
        $inventory_size = 0;
        if (function_exists('wc_get_products')) {
            $args = [
                'limit' => -1,
                'status' => 'publish',
                'return' => 'ids',
            ];
            $products = wc_get_products($args);
            $inventory_size = count($products);
        }

        // Get wizard data
        $wizard_data = get_option('ai_wizard_data', [
            'business_type' => 'retail',
            'inventory_range' => 'small',
            'has_outlet' => 'no',
            'additional_notes' => ''
        ]);

        // Check for actual outlets in the system
        $outlets = get_posts([
            'post_type' => 'outlet',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        $actual_outlets_exist = !empty($outlets);

        // Convert has_outlet to boolean with better logic
        $has_outlet_value = $wizard_data['has_outlet'] ?: 'no';
        $has_outlet = $actual_outlets_exist ||
            in_array(strtolower($has_outlet_value), ['yes', 'true', '1', 'on']);

        $data = [
            'store_address'           => $store_address,
            'store_address_2'         => $store_address_2,
            'store_city'              => $store_city,
            'store_postcode'          => $store_postcode,
            'store_country'           => $store_country,
            'currency'                => $currency,
            'email'                   => get_option('admin_email', ''),
            'business_type'           => $wizard_data['business_type'] ?: 'retail',
            'inventory_range'         => $wizard_data['inventory_range'] ?: 'small',
            'inventory_size'          => $inventory_size,
            'has_outlet'              => $has_outlet,
            'additional_notes'        => $wizard_data['additional_notes'] ?: '',
            'plugin_name'             => defined('SMARTSALES_NAME') ? SMARTSALES_NAME : 'AI Smart Sales',
            'plugin_version'          => defined('SMARTSALES_VERSION') ? SMARTSALES_VERSION : '1.0.0',
            'site_url'                => get_site_url(),
            'site_name'               => get_bloginfo('name'),
            'wordpress_version'       => get_bloginfo('version'),
            'php_version'             => phpversion(),
            'active_theme'            => wp_get_theme()->get('Name'),
            'site_language'           => get_bloginfo('language'),
        ];

        return new WP_REST_Response(
            $this->format_success_response('App data retrieved successfully.', $data, 200),
            200
        );
    }

    public function update_app_data(WP_REST_Request $request)
    {
        $params = $request->get_params();
        $updated_fields = [];
        $errors = [];

        // Validate and update WooCommerce store settings
        $wc_options = [
            'store_address' => 'woocommerce_store_address',
            'store_address_2' => 'woocommerce_store_address_2',
            'store_city' => 'woocommerce_store_city',
            'store_postcode' => 'woocommerce_store_postcode',
            'store_country' => 'woocommerce_default_country',
            'currency' => 'woocommerce_currency',
        ];

        foreach ($wc_options as $param_key => $option_key) {
            if (isset($params[$param_key])) {
                $value = $params[$param_key];

                // Additional validation for specific fields
                if ($param_key === 'currency' && !$this->is_valid_currency($value)) {
                    $errors[] = "Invalid currency code: {$value}";
                    continue;
                }

                if ($param_key === 'store_country' && !$this->is_valid_country($value)) {
                    $errors[] = "Invalid country code: {$value}";
                    continue;
                }

                update_option($option_key, $value);
                $updated_fields[$param_key] = $value;
            }
        }

        // Update admin email
        if (isset($params['email'])) {
            if (is_email($params['email'])) {
                update_option('admin_email', $params['email']);
                $updated_fields['email'] = $params['email'];
            } else {
                $errors[] = "Invalid email address: {$params['email']}";
            }
        }

        // Update site name
        if (isset($params['site_name'])) {
            update_option('blogname', $params['site_name']);
            $updated_fields['site_name'] = $params['site_name'];
        }

        // Update wizard data
        $wizard_data = get_option('ai_wizard_data', []);
        $wizard_fields = ['business_type', 'inventory_range', 'has_outlet', 'additional_notes'];

        $wizard_updated = false;
        foreach ($wizard_fields as $field) {
            if (isset($params[$field])) {
                $wizard_data[$field] = $params[$field];
                $updated_fields[$field] = $params[$field];
                $wizard_updated = true;
            }
        }

        if ($wizard_updated) {
            update_option('ai_wizard_data', $wizard_data);
        }

        // Return response
        if (!empty($errors)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Some fields could not be updated due to validation errors.',
                'errors' => $errors,
                'updated_fields' => $updated_fields,
            ], 400);
        }

        if (empty($updated_fields)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No valid fields provided for update.',
                'data' => [],
            ], 400);
        }

        return new WP_REST_Response(
            $this->format_success_response(
                'App data updated successfully.',
                [
                    'updated_fields' => $updated_fields,
                    'updated_count' => count($updated_fields)
                ]
            ),
            200
        );
    }

    private function is_valid_currency($currency)
    {
        // Extended currency validation - you can expand this list
        $valid_currencies = [
            'USD',
            'EUR',
            'GBP',
            'JPY',
            'AUD',
            'CAD',
            'CHF',
            'CNY',
            'SEK',
            'NZD',
            'BDT',
            'INR',
            'PKR',
            'SGD',
            'MYR',
            'THB',
            'PHP',
            'IDR',
            'VND',
            'KRW',
            'TWD',
            'HKD',
            'AED',
            'SAR',
            'QAR',
            'KWD',
            'BHD',
            'OMR',
            'JOD',
            'LBP',
            'EGP',
            'ZAR',
            'NGN',
            'KES',
            'GHS',
            'MAD',
            'TND',
            'DZD',
            'ETB',
            'UGX',
            'TZS',
            'RWF',
            'XOF',
            'XAF',
            'MXN',
            'BRL',
            'ARS',
            'CLP',
            'COP',
            'PEN',
            'UYU',
            'BOB',
            'PYG',
            'VES',
            'DOP',
            'GTQ',
            'HNL',
            'NIO',
            'CRC',
            'PAB',
            'CUP',
            'JMD',
            'BBD',
            'XCD',
            'TTD',
            'BSD',
            'BZD',
            'GYD',
            'SRD',
            'AWG',
            'RUB',
            'PLN',
            'CZK',
            'HUF',
            'RON',
            'BGN',
            'HRK',
            'RSD',
            'BAM',
            'MKD',
            'ALL',
            'MDL',
            'UAH',
            'BYN',
            'GEL',
            'AMD',
            'AZN',
            'KZT',
            'KGS',
            'UZS',
            'TJS',
            'TMT',
            'AFN',
            'IRR',
            'IQD',
            'SYP',
            'YER',
            'LYD',
            'SDG',
            'SOS',
            'DJF',
            'ERN',
            'MRU',
            'CDF',
            'AOA',
            'ZMW',
            'BWP',
            'SZL',
            'LSL',
            'NAD',
            'MWK',
            'MZN',
            'MGA',
            'KMF',
            'SCR',
            'MUR',
            'MVR',
            'LKR',
            'NPR',
            'BTN',
            'MMK',
            'LAK',
            'KHR',
            'BND',
            'FJD',
            'PGK',
            'SBD',
            'VUV',
            'TOP',
            'WST',
            'TVD',
            'NRU',
            'KID',
            'AUD'
        ];
        return in_array(strtoupper($currency), $valid_currencies);
    }

    private function is_valid_country($country)
    {
        // Extended country validation - WordPress/WooCommerce country codes
        $valid_countries = [
            'US',
            'CA',
            'GB',
            'AU',
            'DE',
            'FR',
            'IT',
            'ES',
            'NL',
            'BE',
            'BD',
            'IN',
            'PK',
            'SG',
            'MY',
            'TH',
            'PH',
            'ID',
            'VN',
            'KR',
            'TW',
            'HK',
            'AE',
            'SA',
            'QA',
            'KW',
            'BH',
            'OM',
            'JO',
            'LB',
            'EG',
            'ZA',
            'NG',
            'KE',
            'GH',
            'MA',
            'TN',
            'DZ',
            'ET',
            'UG',
            'TZ',
            'RW',
            'BF',
            'CM',
            'MX',
            'BR',
            'AR',
            'CL',
            'CO',
            'PE',
            'UY',
            'BO',
            'PY',
            'VE',
            'DO',
            'GT',
            'HN',
            'NI',
            'CR',
            'PA',
            'CU',
            'JM',
            'BB',
            'AG',
            'TT',
            'BS',
            'BZ',
            'GY',
            'SR',
            'AW',
            'RU',
            'PL',
            'CZ',
            'HU',
            'RO',
            'BG',
            'HR',
            'RS',
            'BA',
            'MK',
            'AL',
            'MD',
            'UA',
            'BY',
            'GE',
            'AM',
            'AZ',
            'KZ',
            'KG',
            'UZ',
            'TJ',
            'TM',
            'AF',
            'IR',
            'IQ',
            'SY',
            'YE',
            'LY',
            'SD',
            'SO',
            'DJ',
            'ER',
            'MR',
            'CD',
            'AO',
            'ZM',
            'BW',
            'SZ',
            'LS',
            'NA',
            'MW',
            'MZ',
            'MG',
            'KM',
            'SC',
            'MU',
            'MV',
            'LK',
            'NP',
            'BT',
            'MM',
            'LA',
            'KH',
            'BN',
            'FJ',
            'PG',
            'SB',
            'VU',
            'TO',
            'WS',
            'TV',
            'NR',
            'KI',
            'PW'
        ];
        return in_array(strtoupper($country), $valid_countries);
    }

    private function format_success_response($message, $data = [], $statusCode = 200)
    {
        return [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];
    }

    private function get_update_args_schema()
    {
        return [
            'store_address' => [
                'type' => 'string',
                'description' => 'Store address line 1',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'store_address_2' => [
                'type' => 'string',
                'description' => 'Store address line 2',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'store_city' => [
                'type' => 'string',
                'description' => 'Store city',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'store_postcode' => [
                'type' => 'string',
                'description' => 'Store postal code',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'store_country' => [
                'type' => 'string',
                'description' => 'Store country code',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'currency' => [
                'type' => 'string',
                'description' => 'Store currency',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'email' => [
                'type' => 'string',
                'format' => 'email',
                'description' => 'Admin email address',
                'sanitize_callback' => 'sanitize_email',
            ],
            'business_type' => [
                'type' => 'string',
                'description' => 'Type of business',
                'enum' => ['retail', 'wholesale', 'restaurant', 'service', 'other'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'inventory_range' => [
                'type' => 'string',
                'description' => 'Inventory size range',
                'enum' => ['small', 'medium', 'large'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'has_outlet' => [
                'type' => 'string',
                'description' => 'Whether business has outlets',
                'enum' => ['yes', 'no'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'additional_notes' => [
                'type' => 'string',
                'description' => 'Additional business notes',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'site_name' => [
                'type' => 'string',
                'description' => 'Site name',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }
}