<?php
/**
 * REST API related functions.
 *
 * @package MainStem
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Set hooks related to WP Rest API.
 */
function mainstem_rest_api_hooks()
{
    add_action('rest_api_init', 'mainstem_rest_api_init');
}
add_action('plugins_loaded', 'mainstem_rest_api_hooks');

/**
 * Create the plugin Endpoints.
 */
function mainstem_rest_api_init()
{
    register_rest_route(
        MAINSTEM_REST_API_NS,
        '/orders',
        array(
            'methods' => 'POST',
            'callback' => 'mainstem_rest_api_order_create',
            'permission_callback' => 'mainstem_rest_api_check_perm',
        )
    );
    register_rest_route(
        MAINSTEM_REST_API_NS,
        '/orders/(?P<id>[\d]+)',
        array(
            'args' => array(
                'id' => array(
                    'description' => __('Order ID.', 'mainstem'),
                    'type' => 'integer',
                ),
            ),
            'methods' => 'GET',
            'callback' => 'mainstem_rest_api_order_status',
            'permission_callback' => 'mainstem_rest_api_check_perm',
        )
    );
    register_rest_route(
        MAINSTEM_REST_API_NS,
        '/products',
        array(
            'methods' => 'GET',
            'callback' => 'mainstem_rest_api_get_products',
            'permission_callback' => 'mainstem_rest_api_check_perm',
        )
    );
}

/**
 * General function used to check permission.
 *
 * @param WP_REST_Request $request Request received.
 * @return bool|WP_Error
 */
function mainstem_rest_api_check_perm($request)
{
    $saved_api_key = get_option(MAINSTEN_API_KEY_OPTION);
    $sent_api_key = $request->get_header(MAINSTEN_API_KEY_OPTION);
    if (!empty($sent_api_key) && $sent_api_key == $saved_api_key) {
        return true;
    }

    return new WP_Error(
        'mainstem_api_key_error',
        __('Unauthorized', 'mainstem'),
        array(
            'status' => 401,
        )
    );
}

/**
 * Create a WooCommerce Order. Responsible for /orders endpoint.
 *
 * @param WP_REST_Request $request Request received.
 * @return WP_REST_Response
 */
function mainstem_rest_api_order_create($request)
{
    // Get name and address information from POST request.
    $address = apply_filters(
        'mainstem_create_order_args',
        array(
            'first_name' => $request['first_name'],
            'last_name' => $request['last_name'],
            'company' => $request['company'],
            'email' => $request['email'],
            'phone' => $request['phone'],
            'address_1' => $request['address_1'],
            'address_2' => $request['address_2'],
            'city' => $request['city'],
            'state' => $request['state'],
            'postcode' => $request['postcode'],
            'country' => $request['country'],
        )
    );

    // Now we create the order.
    $order = wc_create_order();

    $json_line_items = $request['line_items'];

    // For each item passed, add it to the order.
    foreach ($json_line_items as $line_item) {
        $line_item = json_decode($line_item, true);
        $line_item_id = $line_item['id'];
        $line_item_quantity = $line_item['quantity'];
        // Add item and quantity to order.
        $order->add_product(get_product($line_item_id), $line_item_quantity);
    }

    // Set our WooCommerce shipping address.
    $order->set_address($address, 'shipping');

    // Set our WooCommerce billing address.
    $order->set_address($address, 'billing');

    // Set created via to MainStem
    $order->set_created_via("MainStem");

    // Calculate WooCommerce order totals.
    $order->calculate_totals();

    // Get the order ID
    $orderID = $order->get_id();

    // Set post/order meta for MainStem Order ID
    add_post_meta($orderID, "mainstem_order_id", $request['mainstem_order_id']);

    // Prepare our API response
    $api_response = (object) array(
        'wasSuccessful' => true,
        'id' => $order->get_id(),
    );

    // Return with our API response
    return new WP_REST_Response($api_response, 201);
}

/**
 * Check a WooCommerce Order status. Responsible for /orders/<id> endpoint.
 *
 * @param WP_REST_Request $request Request received.
 * @return WP_REST_Response
 */
function mainstem_rest_api_order_status($request)
{
    // Get an instance of the WC_Order object (same as before).
    $wc_order = wc_get_order($request['id']);

    // Prepare our API response.
    $api_response = array(
        'wasSuccessful' => true,
        'id' => $wc_order->get_id(),
        'total' => $wc_order->get_total(),
        'shipments' => array(),
    );

    try {
        $st = WC_Shipment_Tracking_Actions::get_instance();
        $tracking_items = $st->get_tracking_items($request['id'], true);

        $shipments_array = array();
        foreach ($tracking_items as $tracking_item) {
            $shipments_array[] = (object) array(
                'tracking_provider' => $tracking_item['formatted_tracking_provider'],
                'tracking_number' => $tracking_item['tracking_number'],
                'date_shipped' => $tracking_item['date_shipped'],
            );
        }

        $api_response['shipments'] = $shipments_array;
    } catch (Exception $ex) {

        $shipment_tracking_provider = '';
        $shipment_tracking_number = '';
        $shipment_date_shipped = '';

        // Check meta data on order for shipment details.
        foreach ($wc_order->get_meta_data() as $metadata) {
            // If metadata is "shipment tracking", set the tracking number in our API response.
            if ('shipment tracking' == $metadata->get_data()['key']) {
                $shipment_tracking_number = $metadata->get_data()['value'];
            } elseif ('shipment carrier' == $metadata->get_data()['key']) {
                $shipment_tracking_provider = $metadata->get_data()['value'];
            } elseif ('shipment date' == $metadata->get_data()['key']) {
                $shipment_date_shipped = $metadata->get_data()['value'];
            }
        }

        $shipments_array = array();

        $shipments_array[] = (object) array(
            'tracking_provider' => $shipment_tracking_provider,
            'tracking_number' => $shipment_tracking_number,
            'date_shipped' => $shipment_date_shipped,
        );

        $api_response['shipments'] = $shipments_array;
    }

    return new WP_REST_Response($api_response, 200);
}

/**
 * Return WooCommerce products. Responsible for /products endpoint.
 *
 * @param WP_REST_Request $request Request received.
 * @return WP_REST_Response
 */
function mainstem_rest_api_get_products($request)
{
    // Prepare WooCommerce product query, sort by name ascending.
    $query = new WC_Product_Query(
        array(
            'orderby' => 'name',
            'order' => 'ASC',
        )
    );

    // Get WooCommerce products.
    $wc_products = $query->get_products();

    // Prepare a new array for our product response.
    $products = array();

    // For each WooCommerce product.
    foreach ($wc_products as $wc_product) {

        $image_id = $wc_product->get_image_id();
        $image_settings = wp_get_attachment_image_src($image_id);
        $image_url = $image_settings[0];

        $gallery_image_ids = $wc_product->get_gallery_image_ids();

        $gallery_images = array();

        foreach ($gallery_image_ids as $gallery_image_id) {
            $gallery_image_settings = wp_get_attachment_image_src($gallery_image_id);
            $gallery_image_url = $gallery_image_settings[0];
            $gallery_images[] = $gallery_image_url;
        }

        // Prepare a new product object.
        $products[] = (object) array(
            'id' => $wc_product->get_id(),
            'name' => $wc_product->get_name(),
            'price' => $wc_product->get_price(),
            'priceRetail' => $wc_product->get_regular_price(),
            'priceWholesale' => $wc_product->get_sale_price(),
            'stock_status' => $wc_product->get_stock_status(),
            'stock_quantity' => $wc_product->get_stock_quantity(),
            'description' => $wc_product->get_description(),
            'sku' => $wc_product->get_sku(),
            'weight' => $wc_product->get_weight(),
            'mainImage' => $image_url,
            'images' => $gallery_images,
        );
    }

    $api_response = (object) array(
        'wasSuccessful' => true,
        'products' => $products,
    );
    return new WP_REST_Response($api_response, 200);
}
