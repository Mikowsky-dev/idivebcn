<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */




if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

  wp_enqueue_style(
    'hello-elementor-child-style',
    get_stylesheet_directory_uri() . '/style.css',
    [
      'hello-elementor-theme-style',
    ],
    HELLO_ELEMENTOR_CHILD_VERSION
  );

}


 /** transparencia en firmas*/
 add_filter( 'gform_signature_url', 'enable_signature_transparency', 10, 4 );
 function enable_signature_transparency( $url, $file_name, $form_id, $field_id ){
  
     return add_query_arg( 't', 1, $url );
 }
 
 add_filter( 'woocommerce_variable_children_args', function( $args ) {
     global $sitepress;
     $parseQueryAction = [ $sitepress, 'parse_query' ];
     if ( remove_action( 'parse_query',  $parseQueryAction ) ) {
         add_action( 'setted_transient', function() use ( $parseQueryAction ) {
             add_action( 'parse_query',  $parseQueryAction );
         } );
     }
     return $args;
 } );
 /** Desactiva todos los scripts y estilos de WooCommerce excepto en las páginas de la tienda*/
 add_action( 'wp_enqueue_scripts', 'dequeue_woocommerce_styles_scripts', 99 );
 function dequeue_woocommerce_styles_scripts() {
 if ( function_exists( 'is_woocommerce' ) ) {
 if ( ! is_woocommerce() && ! is_cart() &&! is_account_page() && ! is_checkout() ) {
 # Styles
 wp_dequeue_style( 'woocommerce-general' );
 wp_dequeue_style( 'woocommerce-layout' );
 wp_dequeue_style( 'woocommerce-smallscreen' );
 wp_dequeue_style( 'woocommerce_frontend_styles' );
 wp_dequeue_style( 'woocommerce_fancybox_styles' );
 wp_dequeue_style( 'woocommerce_chosen_styles' );
 wp_dequeue_style( 'woocommerce_prettyPhoto_css' );
 # Scripts
 wp_dequeue_script( 'wc_price_slider' );
 wp_dequeue_script( 'wc-single-product' );
 wp_dequeue_script( 'wc-add-to-cart' );
 wp_dequeue_script( 'wc-cart-fragments' );
 wp_dequeue_script( 'wc-checkout' );
 wp_dequeue_script( 'wc-add-to-cart-variation' );
 wp_dequeue_script( 'wc-single-product' );
 wp_dequeue_script( 'wc-cart' );
 wp_dequeue_script( 'wc-chosen' );
 wp_dequeue_script( 'woocommerce' );
 wp_dequeue_script( 'prettyPhoto' );
 wp_dequeue_script( 'prettyPhoto-init' );
 wp_dequeue_script( 'jquery-blockui' );
 wp_dequeue_script( 'jquery-placeholder' );
 wp_dequeue_script( 'fancybox' );
 wp_dequeue_script( 'jqueryui' );
 }
 }
 } 
  
 /** Desactiva llamadas Ajax de WooCommerce en portada de la web */
 add_action( 'wp_enqueue_scripts', 'dequeue_woocommerce_cart_fragments', 11); 
 function dequeue_woocommerce_cart_fragments() { if (is_front_page()) wp_dequeue_script('wc-cart-fragments'); }
 
 /* eliminar componentes del checkout*/
  
 add_filter('use_block_editor_for_post_type', '__return_false', 100);
 
 add_filter( 'woocommerce_checkout_fields' , 'custom_override_checkout_fields' );
 function custom_override_checkout_fields( $fields ) {
 
 unset($fields['billing']['billing_company']);
  
 
 return $fields;
 
 }
 // === Campo DNI | Pasaporte en el checkout de WooCommerce ===
 
 // 1) Añadir campo al bloque de facturación
 add_filter('woocommerce_checkout_fields', function ($fields) {
     $fields['billing']['billing_id_number'] = array(
         'type'        => 'text',
         'label'       => __('DNI | Pasaporte', 'woocommerce'),
         'placeholder' => __('Introduce tu DNI o Pasaporte', 'woocommerce'),
         'required'    => true,
         'class'       => array('form-row-wide'),
         'priority'    => 120,
     );
     return $fields;
 });
 // 4) Incluir el campo en los correos electrónicos
 add_filter('woocommerce_email_order_meta_fields', function ($fields, $sent_to_admin, $order) {
     $id_number = $order->get_meta('_billing_id_number');
     if ($id_number) {
         $fields['billing_id_number'] = array(
             'label' => __('DNI | Pasaporte', 'woocommerce'),
             'value' => $id_number,
         );
     }
     return $fields;
 }, 10, 3);

 
 // Actualiza automáticamente el estado de los pedidos a COMPLETADO
 // Excluye transferencia bancaria (bacs) que requiere verificación manual
 add_action( 'woocommerce_order_status_processing', 'actualiza_estado_pedidos_a_completado' );
 add_action( 'woocommerce_order_status_on-hold', 'actualiza_estado_pedidos_a_completado' );
 function actualiza_estado_pedidos_a_completado( $order_id ) {
     if ( !$order_id ) return;
     $order = wc_get_order( $order_id );
     if ( !$order ) return;

     // Métodos que NO se auto-completan (requieren verificación manual)
     $excluded_methods = array( 'bacs' );

     if ( in_array( $order->get_payment_method(), $excluded_methods ) ) return;
     $order->update_status( 'completed' );
 }
 




add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );
