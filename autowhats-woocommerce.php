<?php
/**
 * AutoWA WooCommerce Integration
 * Maneja la lógica de eventos y disparadores de mensajes para WooCommerce.
 * * @package AutoWA
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoWA_WooCommerce {

    private $plugin_instance;
    private $admin_phone;
    private $is_enabled;

    public function __construct($plugin_instance) {
        $this->plugin_instance = $plugin_instance;
        
        // Cargar configuración global al iniciar
        $this->is_enabled = get_option('autwa_woo_enabled', 0);
        $this->admin_phone = get_option('autwa_woo_admin_phone', '');
    }

    /**
     * Devuelve la lista de eventos disponibles traducidos.
     */
    public static function get_events() {
        return [
            'new_order'      => __('New Order Created', 'autowa-whatsapp'),
            'processing'     => __('Order Processing (Payment Received)', 'autowa-whatsapp'),
            'completed'      => __('Order Completed (Shipped)', 'autowa-whatsapp'),
            'cancelled'      => __('Order Cancelled', 'autowa-whatsapp'),
            'failed'         => __('Order Failed', 'autowa-whatsapp'),
            'on_hold'        => __('Order On Hold', 'autowa-whatsapp'),
            'refunded'       => __('Order Refunded', 'autowa-whatsapp'),
            'low_stock'      => __('Low Stock Warning', 'autowa-whatsapp'),
            'no_stock'       => __('Out of Stock Warning', 'autowa-whatsapp')
        ];
    }

    /**
     * Inicializa los hooks de WooCommerce.
     */
    public function init() {
        // Si la integración está desactivada o no hay teléfono, no hacemos nada.
        if (!$this->is_enabled || empty($this->admin_phone)) {
            return;
        }

        // Hook para cambios de estado de pedido (Cubre: processing, completed, cancelled, failed, on-hold, refunded)
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);

        // Hook específico para Nuevo Pedido (apenas se crea)
        add_action('woocommerce_new_order', array($this, 'handle_new_order'), 10, 1);

        // Hooks de Inventario
        add_action('woocommerce_low_stock', array($this, 'handle_stock_alert'), 10, 1);
        add_action('woocommerce_no_stock', array($this, 'handle_stock_alert'), 10, 1);
    }

    /**
     * Maneja el evento de Nuevo Pedido.
     */
    public function handle_new_order($order_id) {
        $this->process_order_event($order_id, 'new_order');
    }

    /**
     * Maneja los cambios de estado (Processing, Completed, etc.)
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // Mapeamos el slug de estado de Woo a nuestras claves de evento
        // Ejemplo: 'wc-completed' -> 'completed'
        $status_key = str_replace('wc-', '', $new_status);
        
        // Unificar 'on-hold' a 'on_hold' si es necesario por la clave del array
        if ($status_key === 'on-hold') $status_key = 'on_hold';

        // Procesamos el evento
        $this->process_order_event($order_id, $status_key);
    }

    /**
     * Lógica central para procesar eventos de PEDIDOS.
     */
    private function process_order_event($order_id, $event_key) {
        $is_event_active = get_option("autwa_woo_event_{$event_key}_active", 0);
        if (!$is_event_active) return;

        $template = get_option("autwa_woo_event_{$event_key}_template", '');
        
        // CORRECCIÓN: Fallback si no hay plantilla guardada
        if (empty($template)) {
            $template = "*Nuevo Pedido:* Tienes un pedido (#{order_number}) en {site_name}. Cliente: {customer_name}. Total: {order_total}.";
        }

        $order = wc_get_order($order_id);
        if (!$order) return;

        $message = $this->replace_variables($template, $order);
        $this->send_immediate_message($message);
    }

    /**
     * Maneja alertas de STOCK (Bajo y Agotado).
     */
     public function handle_stock_alert($product) {
        // Determinar tipo de evento
        $event_key = current_action() === 'woocommerce_no_stock' ? 'no_stock' : 'low_stock';

        $is_event_active = get_option("autwa_woo_event_{$event_key}_active", 0);
        if (!$is_event_active) return;

        $template = get_option("autwa_woo_event_{$event_key}_template", '');
        if (empty($template)) return;

        $product_name = $product->get_name();
        $stock_qty = $product->get_stock_quantity();
        $sku = $product->get_sku();

        $message = str_replace(
            ['{product_name}', '{stock_quantity}', '{sku}'],
            [$product_name, $stock_qty, $sku],
            $template
        );

        // Envío inmediato
        $this->send_immediate_message($message);
    }

    /**
     * Reemplaza los placeholders {variable} con datos reales del pedido.
     */
    private function replace_variables($text, $order) {
        $site_name = get_option('blogname');
        
        $data = [
            '{order_id}'      => $order->get_id(),
            '{order_number}'  => $order->get_order_number(),
            '{order_total}'   => $order->get_total(),
            '{customer_name}' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '{customer_phone}'=> $order->get_billing_phone(),
            '{order_status}'  => wc_get_order_status_name($order->get_status()),
            '{items_list}'    => $this->get_product_list_string($order),
            '{date}'          => $order->get_date_created()->date_i18n(get_option('date_format')),
            '{site_name}'     => !empty($site_name) ? $site_name : get_bloginfo('name'), // CORRECCIÓN AQUÍ
        ];

        return str_replace(array_keys($data), array_values($data), $text);
    }

    /**
     * Genera una lista simple de productos (Ej: "2x Gorra, 1x Camiseta")
     */
    private function get_product_list_string($order) {
        $items = $order->get_items();
        $list = [];
        foreach ($items as $item) {
            $list[] = $item->get_quantity() . 'x ' . $item->get_name();
        }
        return implode(', ', $list);
    }

    /**
     * Inserta el mensaje en la base de datos para que el Cron lo envíe.
     * Esto evita ralentizar el proceso de compra de WooCommerce.
     */
     
     private function send_immediate_message($message_body) {
        $clean_phone = preg_replace('/[^0-9]/', '', $this->admin_phone);
        
        if (empty($clean_phone)) {
            error_log('AutoWA WooCommerce: No admin phone configured.');
            return;
        }

        $response = $this->plugin_instance->send_immediate_whatsapp_message($clean_phone, $message_body);
        
        if (is_wp_error($response)) {
            error_log('AutoWA WooCommerce API Error: ' . $response->get_error_message());
        }
    }
    
}