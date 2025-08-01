<?php

namespace AISMARTSALES\Includes\Api\App;

use WP_REST_Request;
use WP_REST_Response;
if (!defined('ABSPATH')) {
    exit;
}

class WizardApiHandler {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('ai-smart-sales/v1', '/wizard', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_wizard_request'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route('ai-smart-sales/v1', '/wizard', [
            'methods' => 'GET',
            'callback' => [$this, 'get_wizard_data'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route('ai-smart-sales/v1', '/wizard', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_wizard_data'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function check_permission($request) {
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

    private function format_success_response($message, $data = [], $statusCode = 200) {
        return [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];
    }

    private function format_error_response($message, $code = 400) {
        return [
            'success' => false,
            'message' => $message,
        ];
    }

    private function sanitize_boolean($value) {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', '1', 'yes', 'on'], true);
        }
        
        return (bool) $value;
    }

    public function handle_wizard_request(WP_REST_Request $request) {
        try {
            $params = $request->get_json_params();

            $wizard_data = [
                'business_type' => isset($params['business_type']) ? sanitize_text_field($params['business_type']) : '',
                'inventory_range' => isset($params['inventory_range']) ? sanitize_text_field($params['inventory_range']) : '',
                'has_outlet' => isset($params['has_outlet']) ? $this->sanitize_boolean($params['has_outlet']) : false,
                'additional_notes' => isset($params['additional_notes']) ? sanitize_textarea_field($params['additional_notes']) : '',
                'company_name' => isset($params['company_name']) ? sanitize_text_field($params['company_name']) : '',
                'company_size' => isset($params['company_size']) ? sanitize_text_field($params['company_size']) : '',
                'industry_sector' => isset($params['industry_sector']) ? sanitize_text_field($params['industry_sector']) : '',
                'monthly_revenue' => isset($params['monthly_revenue']) ? sanitize_text_field($params['monthly_revenue']) : '',
                'sales_channel' => isset($params['sales_channel']) ? array_map('sanitize_text_field', (array)$params['sales_channel']) : [],
                'target_market' => isset($params['target_market']) ? sanitize_text_field($params['target_market']) : '',
                'created_at' => current_time('mysql'),
            ];

            // Validate required fields
            $required_fields = ['business_type', 'inventory_range', 'company_name', 'industry_sector'];
            $missing_fields = [];

            foreach ($required_fields as $field) {
                if (empty($wizard_data[$field])) {
                    $missing_fields[] = str_replace('_', ' ', ucfirst($field));
                }
            }

            if (!empty($missing_fields)) {
                return new WP_REST_Response(
                    $this->format_error_response('Required fields missing: ' . implode(', ', $missing_fields)),
                    400
                );
            }

            // Validate business_type
            $valid_business_types = [
                'retail',
                'wholesale',
                'manufacturing',
                'service',
                'ecommerce',
                'dropshipping',
                'restaurant',
                'pharmacy',
                'grocery',
                'fashion',
                'electronics',
                'furniture',
                'automotive',
                'construction',
                'hospitality',
                'healthcare',
                'education',
                'consulting',
                'real_estate',
                'other'
            ];
            if (!in_array($wizard_data['business_type'], $valid_business_types)) {
                return new WP_REST_Response(
                    $this->format_error_response('Invalid business type provided'),
                    400
                );
            }

            // Validate inventory_range
            $valid_inventory_ranges = ['small', 'medium', 'large', 'enterprise'];
            if (!in_array($wizard_data['inventory_range'], $valid_inventory_ranges)) {
                return new WP_REST_Response(
                    $this->format_error_response('Invalid inventory range provided'),
                    400
                );
            }

            // Save to WordPress options with unique identifier
            $wizard_entries = get_option('ai_wizard_entries', []);
            $entry_id = uniqid('wizard_');
            $wizard_entries[$entry_id] = $wizard_data;
            update_option('ai_wizard_entries', $wizard_entries);

            // Also update the latest entry as the current wizard data
            update_option('ai_wizard_data', $wizard_data);

            return new WP_REST_Response(
                $this->format_success_response(
                    'Business information saved successfully',
                    ['entry_id' => $entry_id, 'data' => $wizard_data],
                    200
                ),
                200
            );

        } catch (\Exception $e) {
            return new WP_REST_Response(
                $this->format_error_response('An error occurred: ' . $e->getMessage()),
                500
            );
        }
    }

    public function get_wizard_data(WP_REST_Request $request) {
        $entry_id = $request->get_param('entry_id');
        
        if ($entry_id) {
            $wizard_entries = get_option('ai_wizard_entries', []);
            $wizard_data = isset($wizard_entries[$entry_id]) ? $wizard_entries[$entry_id] : null;
            
            if (!$wizard_data) {
                return new WP_REST_Response(
                    $this->format_error_response('Entry not found'),
                    404
                );
            }
        } else {
            $wizard_data = get_option('ai_wizard_data', [
                'business_type' => 'retail',
                'inventory_range' => 'small',
                'has_outlet' => false,
                'additional_notes' => '',
                'company_name' => '',
                'company_size' => '',
                'industry_sector' => '',
                'monthly_revenue' => '',
                'sales_channel' => [],
                'target_market' => '',
                'created_at' => '',
            ]);
        }

        return new WP_REST_Response(
            $this->format_success_response('Wizard data retrieved successfully', $wizard_data, 200),
            200
        );
    }

    public function update_wizard_data(WP_REST_Request $request) {
        try {
            $params = $request->get_json_params();
            $entry_id = $request->get_param('entry_id');

            // Get existing wizard data
            if ($entry_id) {
                $wizard_entries = get_option('ai_wizard_entries', []);
                if (!isset($wizard_entries[$entry_id])) {
                    return new WP_REST_Response(
                        $this->format_error_response('Entry not found'),
                        404
                    );
                }
                $existing_data = $wizard_entries[$entry_id];
            } else {
                $existing_data = get_option('ai_wizard_data', []);
            }

            // Merge existing data with new data
            $updated_data = array_merge($existing_data, [
                'business_type' => isset($params['business_type']) ? sanitize_text_field($params['business_type']) : $existing_data['business_type'],
                'inventory_range' => isset($params['inventory_range']) ? sanitize_text_field($params['inventory_range']) : $existing_data['inventory_range'],
                'has_outlet' => isset($params['has_outlet']) ? $this->sanitize_boolean($params['has_outlet']) : $existing_data['has_outlet'],
                'additional_notes' => isset($params['additional_notes']) ? sanitize_textarea_field($params['additional_notes']) : $existing_data['additional_notes'],
                'company_name' => isset($params['company_name']) ? sanitize_text_field($params['company_name']) : $existing_data['company_name'],
                'company_size' => isset($params['company_size']) ? sanitize_text_field($params['company_size']) : $existing_data['company_size'],
                'industry_sector' => isset($params['industry_sector']) ? sanitize_text_field($params['industry_sector']) : $existing_data['industry_sector'],
                'monthly_revenue' => isset($params['monthly_revenue']) ? sanitize_text_field($params['monthly_revenue']) : $existing_data['monthly_revenue'],
                'sales_channel' => isset($params['sales_channel']) ? array_map('sanitize_text_field', (array)$params['sales_channel']) : $existing_data['sales_channel'],
                'target_market' => isset($params['target_market']) ? sanitize_text_field($params['target_market']) : $existing_data['target_market'],
                'updated_at' => current_time('mysql'),
            ]);

            // Validate business_type if provided
            if (isset($params['business_type'])) {
                $valid_business_types = [
                    'retail', 'wholesale', 'manufacturing', 'service', 'ecommerce', 
                    'dropshipping', 'restaurant', 'pharmacy', 'grocery', 'fashion', 
                    'electronics', 'furniture', 'automotive', 'construction', 
                    'hospitality', 'healthcare', 'education', 'consulting', 
                    'real_estate', 'other'
                ];
                if (!in_array($updated_data['business_type'], $valid_business_types)) {
                    return new WP_REST_Response(
                        $this->format_error_response('Invalid business type provided'),
                        400
                    );
                }
            }

            // Validate inventory_range if provided
            if (isset($params['inventory_range'])) {
                $valid_inventory_ranges = ['small', 'medium', 'large', 'enterprise'];
                if (!in_array($updated_data['inventory_range'], $valid_inventory_ranges)) {
                    return new WP_REST_Response(
                        $this->format_error_response('Invalid inventory range provided'),
                        400
                    );
                }
            }

            // Save updated data
            if ($entry_id) {
                $wizard_entries[$entry_id] = $updated_data;
                update_option('ai_wizard_entries', $wizard_entries);
                
                // If this is the latest entry, also update the current wizard data
                $current_wizard_data = get_option('ai_wizard_data', []);
                if (isset($current_wizard_data['created_at']) && 
                    isset($existing_data['created_at']) && 
                    $current_wizard_data['created_at'] === $existing_data['created_at']) {
                    update_option('ai_wizard_data', $updated_data);
                }
            } else {
                update_option('ai_wizard_data', $updated_data);
            }

            return new WP_REST_Response(
                $this->format_success_response(
                    'Wizard data updated successfully',
                    ['entry_id' => $entry_id, 'data' => $updated_data],
                    200
                ),
                200
            );

        } catch (\Exception $e) {
            return new WP_REST_Response(
                $this->format_error_response('An error occurred: ' . $e->getMessage()),
                500
            );
        }
    }
}