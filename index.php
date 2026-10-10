<?php
/*
Plugin Name: درگاه زیبال گرویتی فرم
Plugin URI: http://zibal.ir/
Description: افزونه درگاه پرداخت زیبال برای فرم ساز فوق پیشرفته Gravity Forms
Version: 1.3.0
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 5.6
Author: zibal
Author URI: http://zibal.ir/
*/
if (!defined('ABSPATH')) exit;

$zibal_callback_request = (
	isset($_GET['zibal_callback']) && (string) wp_unslash($_GET['zibal_callback']) === '1'
) || (
	isset($_GET['id'], $_GET['entry'], $_GET['zibal_token'])
);

if ($zibal_callback_request && !defined('DONOTCACHEPAGE')) {
	define('DONOTCACHEPAGE', true);
}

require_once plugin_dir_path(__FILE__) . 'zibal.php';
register_activation_hook(__FILE__, array('GFPersian_Gateway_Zibal', 'add_permissions'));
