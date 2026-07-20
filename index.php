<?php
/*
Plugin Name: درگاه زیبال گرویتی فرم
Plugin URI: http://zibal.ir/
Description: افزونه درگاه پرداخت زیبال برای فرم ساز فوق پیشرفته Gravity Forms
Version: 1.2.3
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Author: zibal
Author URI: http://zibal.ir/
*/
if (!defined('ABSPATH')) exit;
require_once plugin_dir_path(__FILE__) . 'zibal.php';
register_activation_hook(__FILE__, array('GFPersian_Gateway_Zibal', 'add_permissions'));
