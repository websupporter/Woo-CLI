<?php
/** 
 * Plugin Name: WooCli
 * Author: Websupporter
 * Description: Use WP CLI (wp-cli.org) to get your WooCommerce orders and update them via Console
 * Version: 0.1
 **/

if( defined( 'WP_CLI' ) && WP_CLI ){
	include __DIR__ . '/woo-cli.class.php';
}