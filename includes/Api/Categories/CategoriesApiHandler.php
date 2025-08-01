<?php

namespace AISMARTSALES\Includes\Api\Categories;

use WP_REST_Response;
use WP_Term;

if (!defined('ABSPATH')) {
    exit;
}

class CategoriesApiHandler {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('ai-smart-sales/v1', '/categories', [
            'methods' => 'GET',
            'callback' => [$this, 'get_categories'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route('ai-smart-sales/v1', '/categories/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_category'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route('ai-smart-sales/v1', '/categories', [
            'methods' => 'POST',
            'callback' => [$this, 'create_category'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route('ai-smart-sales/v1', '/categories/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_category'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route('ai-smart-sales/v1', '/categories/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_category'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function check_permission($request) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return false;
        }

        // Get current user
        $user = wp_get_current_user();
        
        // For write operations, require higher privileges
        if (in_array($request->get_method(), ['POST', 'PUT', 'DELETE'])) {
            return current_user_can('administrator') || current_user_can('manage_woocommerce');
        }
        
        // For read operations, allow authenticated users with POS roles
        $allowed_roles = ['administrator', 'aipos_outlet_manager', 'aipos_cashier', 'aipos_shop_manager'];
        $user_roles = (array) $user->roles;
        
        return !empty(array_intersect($allowed_roles, $user_roles));
    }

    private function format_error_response($message, $errors = [], $statusCode = 400, $path = '') {
        $error = [];

        // If $errors is an associative array, use it as-is
        if (is_array($errors) && !empty($errors) && array_keys($errors) !== range(0, count($errors) - 1)) {
            $error = $errors; // Use the associative array directly
        } else {
            // Otherwise, use a generic error structure
            $error = [
                'error' => $message, // Fallback for non-associative errors
            ];
        }

        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => $error,
        ];
    }

    private function format_category_response($category) {
        return [
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'count' => $category->count,
            'parent' => $category->parent,
        ];
    }

    public function get_categories($request) {
        $args = [
            'taxonomy' => 'product_cat',
            'hide_empty' => $request->get_param('hide_empty') ?: false,
            'orderby' => $request->get_param('orderby') ?: 'name',
            'order' => $request->get_param('order') ?: 'ASC',
            'number' => $request->get_param('limit') ?: 0,
        ];

        $categories = get_terms($args);

        if (is_wp_error($categories)) {
            return new WP_REST_Response($this->format_error_response(
                'Failed to retrieve categories.',
                [
                    'error' => $categories->get_error_message(),
                ],
                500,
                $request->get_route()
            ), 500);
        }

        if (empty($categories)) {
            return new WP_REST_Response($this->format_error_response(
                'No categories found.',
                [
                    'categories' => 'No categories match the specified criteria.',
                ],
                404,
                $request->get_route()
            ), 404);
        }

        $formatted_categories = array_map([$this, 'format_category_response'], $categories);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Categories retrieved successfully.',
            'data' => $formatted_categories,
        ], 200);
    }

    public function get_category($data) {
        $category_id = $data['id'];
        $category = get_term($category_id, 'product_cat');

        if (is_wp_error($category) || !$category) {
            return new WP_REST_Response($this->format_error_response(
                'Category not found.',
                [
                    'id' => "The category with the ID '{$category_id}' does not exist.",
                ],
                404,
                '/ai-smart-sales/v1/product-categories/' . $category_id
            ), 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Category retrieved successfully.',
            'data' => $this->format_category_response($category),
        ], 200);
    }

    public function create_category($request) {
        $data = $request->get_json_params();

        // Define required fields and their error messages
        $required_fields = [
            'name' => 'name is required.',
        ];

        $errors = [];

        // Check for missing required fields
        foreach ($required_fields as $field => $error_message) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[$field] = $error_message;
            }
        }

        // If there are missing fields, return a comprehensive error response
        if (!empty($errors)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing required fields: ' . implode(', ', array_keys($errors)),
                'data' => null,
                'error' => $errors,
            ], 400);
        }

        // Create the category
        $category = wp_insert_term($data['name'], 'product_cat', [
            'description' => $data['description'] ?? '',
            'slug' => $data['slug'] ?? '',
            'parent' => $data['parent'] ?? 0,
        ]);

        if (is_wp_error($category)) {
            return new WP_REST_Response($this->format_error_response(
                'Failed to create category.',
                [
                    'error' => $category->get_error_message(),
                ],
                500,
                $request->get_route()
            ), 500);
        }

        $category = get_term($category['term_id'], 'product_cat');

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Category created successfully.',
            'data' => $this->format_category_response($category),
        ], 201);
    }

    public function update_category($request) {
        $category_id = $request->get_param('id');
        $data = $request->get_json_params();

        $category = get_term($category_id, 'product_cat');

        if (is_wp_error($category) || !$category) {
            return new WP_REST_Response($this->format_error_response(
                'Category not found.',
                [
                    'id' => "The category with the ID '{$category_id}' does not exist.",
                ],
                404,
                '/ai-smart-sales/v1/product-categories/' . $category_id
            ), 404);
        }

        // Update the category
        $updated_category = wp_update_term($category_id, 'product_cat', [
            'name' => $data['name'] ?? $category->name,
            'description' => $data['description'] ?? $category->description,
            'slug' => $data['slug'] ?? $category->slug,
            'parent' => $data['parent'] ?? $category->parent,
        ]);

        if (is_wp_error($updated_category)) {
            return new WP_REST_Response($this->format_error_response(
                'Failed to update category.',
                [
                    'error' => $updated_category->get_error_message(),
                ],
                500,
                $request->get_route()
            ), 500);
        }

        $updated_category = get_term($updated_category['term_id'], 'product_cat');

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Category updated successfully.',
            'data' => $this->format_category_response($updated_category),
        ], 200);
    }

    public function delete_category($request) {
        $category_id = $request->get_param('id');
        $category = get_term($category_id, 'product_cat');

        if (is_wp_error($category) || !$category) {
            return new WP_REST_Response($this->format_error_response(
                'Category not found.',
                [
                    'id' => "The category with the ID '{$category_id}' does not exist.",
                ],
                404,
                '/ai-smart-sales/v1/product-categories/' . $category_id
            ), 404);
        }

        $deleted = wp_delete_term($category_id, 'product_cat');

        if (is_wp_error($deleted)) {
            return new WP_REST_Response($this->format_error_response(
                'Failed to delete category.',
                [
                    'error' => $deleted->get_error_message(),
                ],
                500,
                $request->get_route()
            ), 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Category deleted successfully.',
            'data' => ['category_id' => $category_id],
        ], 200);
    }
}

new CategoriesApiHandler();