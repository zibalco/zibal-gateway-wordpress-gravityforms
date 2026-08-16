<?php if (!defined('ABSPATH')) {
	exit;
}

add_action('init', array('GFPersian_Gateway_Zibal', 'init'));

require_once plugin_dir_path(__FILE__) . 'database.php';
require_once plugin_dir_path(__FILE__) . 'chart.php';

class GFPersian_Gateway_Zibal
{

	//Dont Change this Parameter if you are legitimate !!!
	public static $author = "Zibal";


	private static $version = "1.3.0";
	private static $min_gravityforms_version = "1.9.10";
	private static $config = array();
	private static $payment_confirmation_content = '';
	private static $payment_confirmation_form_id = 0;
	private static $verification_lock_tokens = array();


	public static function init()
	{
		if (!class_exists("GFPersian_Payments") || !defined('GF_PERSIAN_VERSION') || version_compare(GF_PERSIAN_VERSION, '2.3.1', '<')) {
			add_action('admin_notices', array(__CLASS__, 'admin_notice_persian_gf'));

			return false;
		}

		if (!self::is_gravityforms_supported()) {
			add_action('admin_notices', array(__CLASS__, 'admin_notice_gf_support'));

			return false;
		}

		add_filter('members_get_capabilities', array(__CLASS__, "members_get_capabilities"));

		if (is_admin() && self::has_access()) {

			add_filter('gform_tooltips', array(__CLASS__, 'tooltips'));
			add_filter('gform_addon_navigation', array(__CLASS__, 'menu'));
			add_action('gform_entry_info', array(__CLASS__, 'payment_entry_detail'), 4, 2);
			add_action('gform_after_update_entry', array(__CLASS__, 'update_payment_entry'), 4, 2);

			if (version_compare(GFCommon::$version, '2.5', '>=')) {
				add_filter('gform_confirmation_settings_fields', array(__CLASS__, 'confirmation_settings_fields'), 10, 3);
			} else {
				add_filter('gform_confirmation_ui_settings', array(__CLASS__, 'legacy_confirmation_ui_settings'), 10, 3);
			}
			add_filter('gform_pre_confirmation_save', array(__CLASS__, 'save_confirmation_payment_result'), 10, 3);

			if (get_option("gf_zibal_configured")) {
				add_filter('gform_form_settings_menu', array(__CLASS__, 'toolbar'), 10, 2);
				add_action('gform_form_settings_page_zibal', array(__CLASS__, 'feed_page'));
			}

			if (rgget("page") == "gf_settings") {
				GFForms::add_settings_page(
					array(
						'name'      => 'gf_zibal',
						'tab_label' => __('درگاه زیبال', 'gravityformszibal'),
						'title'     => __('تنظیمات درگاه زیبال', 'gravityformszibal'),
						'handler'   => array(__CLASS__, 'settings_page'),
					)
				);
			}

			if (self::is_zibal_page()) {
				wp_enqueue_script(array("sack"));
				self::setup();
			}

			add_action('wp_ajax_gf_zibal_update_feed_active', array(__CLASS__, 'update_feed_active'));
			add_action('admin_post_gf_zibal_retry_verification', array(__CLASS__, 'retry_verification'));
		}
		if (get_option("gf_zibal_configured")) {
			add_filter("gform_disable_post_creation", array(__CLASS__, "delay_posts"), 10, 3);
			add_filter("gform_is_delayed_pre_process_feed", array(__CLASS__, "delay_addons"), 10, 4);

			add_filter("gform_confirmation", array(__CLASS__, "Request"), 1000, 4);
			add_action('wp', array(__CLASS__, 'Verify'), 5);
		}

		add_filter("gform_logging_supported", array(__CLASS__, "set_logging_supported"));
		add_filter('gform_is_value_match', array(__CLASS__, 'match_payment_status_condition'), 20, 6);

		// --------------------------------------------------------------------------------------------
		add_filter('gf_payment_gateways', array(__CLASS__, 'gravityformszibal'), 2);
		do_action('gravityforms_gateways');
		do_action('gravityforms_zibal');
		// --------------------------------------------------------------------------------------------
	}



	public static function admin_notice_persian_gf()
	{
		$class   = 'notice notice-error';
		$message = sprintf(__("برای استفاده از نسخه جدید درگاه های پرداخت گرویتی فرم نصب بسته فارسی ساز نسخه 2.3.1 به بالا الزامی است. برای نصب فارسی ساز %sکلیک کنید%s.", "gravityformszibal"), '<a href="' . esc_url(admin_url("plugin-install.php?tab=plugin-information&plugin=persian-gravity-forms&TB_iframe=true&width=772&height=884")) . '">', '</a>');
		printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses_post($message));
	}


	public static function admin_notice_gf_support()
	{
		$class   = 'notice notice-error';
		$message = sprintf(__("درگاه زیبال نیاز به گرویتی فرم نسخه %s به بالا دارد. برای بروز رسانی هسته گرویتی فرم به %sسایت گرویتی فرم فارسی%s مراجعه نمایید .", "gravityformszibal"), esc_html(self::$min_gravityforms_version), "<a href='https://gravityforms.ir/11378' target='_blank' rel='noopener noreferrer'>", "</a>");
		printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses_post($message));
	}


	// #1

	public static function gravityformszibal($form, $entry)
	{
		$zibal = array(
			'class' => (__CLASS__ . '|' . self::$author),
			'title' => __('زیبال', 'gravityformszibal'),
			'param' => array(
				'email'  => __('ایمیل', 'gravityformszibal'),
				'mobile' => __('موبایل', 'gravityformszibal'),
				'desc'   => __('توضیحات', 'gravityformszibal')
			)
		);

		return apply_filters(self::$author . '_gf_zibal_detail', apply_filters(self::$author . '_gf_gateway_detail', $zibal, $form, $entry), $form, $entry);
	}


	public static function add_permissions()
	{
		global $wp_roles;
		$editable_roles = get_editable_roles();
		foreach ((array) $editable_roles as $role => $details) {
			$capabilities = isset($details['capabilities']) && is_array($details['capabilities']) ? $details['capabilities'] : array();
			if ($role == 'administrator' || !empty($capabilities['gravityforms_edit_forms'])) {
				$wp_roles->add_cap($role, 'gravityforms_zibal');
				$wp_roles->add_cap($role, 'gravityforms_zibal_uninstall');
			}
		}
	}


	public static function members_get_capabilities($caps)
	{
		return array_merge($caps, array("gravityforms_zibal", "gravityforms_zibal_uninstall"));
	}


	private static function setup()
	{
		if (get_option("gf_zibal_version") != self::$version) {
			GFPersian_DB_Zibal::update_table();
			update_option("gf_zibal_version", self::$version);
		}
	}


	public static function tooltips($tooltips)
	{
		$tooltips["gateway_name"] = __("تذکر مهم : این قسمت برای نمایش به بازدید کننده می باشد و لطفا جهت جلوگیری از مشکل و تداخل آن را فقط یکبار تنظیم نمایید و از تنظیم مکرر آن خود داری نمایید .", "gravityformszibal");

		return $tooltips;
	}


	public static function menu($menus)
	{
		$permission = "gravityforms_zibal";
		if (!empty($permission)) {
			$menus[] = array(
				"name"       => "gf_zibal",
				"label"      => __("زیبال", "gravityformszibal"),
				"callback"   => array(__CLASS__, "zibal_page"),
				"permission" => $permission
			);
		}

		return $menus;
	}


	public static function toolbar($menu_items)
	{
		$menu_items[] = array(
			'name'  => 'zibal',
			'label' => __('زیبال', 'gravityformszibal')
		);

		return $menu_items;
	}


	private static function is_gravityforms_supported()
	{
		if (class_exists("GFCommon")) {
			$is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");

			return $is_correct_version;
		} else {
			return false;
		}
	}


	protected static function has_access($required_permission = 'gravityforms_zibal')
	{
		if (!function_exists('wp_get_current_user')) {
			include(ABSPATH . "wp-includes/pluggable.php");
		}

		return GFCommon::current_user_can_any($required_permission);
	}


	protected static function get_base_url()
	{
		return plugins_url(null, __FILE__);
	}


	protected static function get_base_path()
	{
		$folder = basename(dirname(__FILE__));

		return WP_PLUGIN_DIR . "/" . $folder;
	}


	public static function set_logging_supported($plugins)
	{
		$plugins[basename(dirname(__FILE__))] = "Zibal";

		return $plugins;
	}


	public static function uninstall()
	{
		if (!self::has_access("gravityforms_zibal_uninstall")) {
			die(__("شما مجوز کافی برای این کار را ندارید . سطح دسترسی شما پایین تر از حد مجاز است . ", "gravityformszibal"));
		}
		GFPersian_DB_Zibal::drop_tables();
		delete_option("gf_zibal_settings");
		delete_option("gf_zibal_configured");
		delete_option("gf_zibal_version");
		$plugin = basename(dirname(__FILE__)) . "/index.php";
		deactivate_plugins($plugin);
		update_option('recently_activated', array($plugin => time()) + (array) get_option('recently_activated'));
	}


	private static function is_zibal_page()
	{
		$current_page    = in_array(trim(strtolower((string) rgget("page"))), array('gf_zibal', 'zibal'));
		$current_view    = in_array(trim(strtolower((string) rgget("view"))), array('gf_zibal', 'zibal'));
		$current_subview = in_array(trim(strtolower((string) rgget("subview"))), array('gf_zibal', 'zibal'));

		return $current_page || $current_view || $current_subview;
	}


	public static function feed_page()
	{
		GFFormSettings::page_header(); ?>
		<h3>
			<span><i class="fa fa-credit-card"></i> <?php esc_html_e('زیبال', 'gravityformszibal') ?>
				<a id="add-new-confirmation" class="add-new-h2" href="<?php echo esc_url(admin_url('admin.php?page=gf_zibal&view=edit&fid=' . absint(rgget("id")))) ?>"><?php esc_html_e('افزودن فید جدید', 'gravityformszibal') ?></a></span>
			<a class="add-new-h2" href="<?php echo esc_url(admin_url('admin.php?page=gf_zibal&view=stats&id=' . absint(rgget("id")))) ?>"><?php _e("نمودار ها", "gravityformszibal") ?></a>
		</h3>
		<?php self::list_page('per-form'); ?>
		<?php GFFormSettings::page_footer();
	}


	public static function has_zibal_condition($form, $config)
	{

		if (empty($config['meta'])) {
			return false;
		}

		if (empty($config['meta']['zibal_conditional_enabled'])) {
			return true;
		}

		if (!empty($config['meta']['zibal_conditional_field_id'])) {
			$condition_field_ids = $config['meta']['zibal_conditional_field_id'];
			if (!is_array($condition_field_ids)) {
				$condition_field_ids = array('1' => $condition_field_ids);
			}
		} else {
			return true;
		}

		if (!empty($config['meta']['zibal_conditional_value'])) {
			$condition_values = $config['meta']['zibal_conditional_value'];
			if (!is_array($condition_values)) {
				$condition_values = array('1' => $condition_values);
			}
		} else {
			$condition_values = array('1' => '');
		}

		if (!empty($config['meta']['zibal_conditional_operator'])) {
			$condition_operators = $config['meta']['zibal_conditional_operator'];
			if (!is_array($condition_operators)) {
				$condition_operators = array('1' => $condition_operators);
			}
		} else {
			$condition_operators = array('1' => 'is');
		}

		$type = !empty($config['meta']['zibal_conditional_type']) ? strtolower($config['meta']['zibal_conditional_type']) : '';
		$type = $type == 'all' ? 'all' : 'any';

		foreach ($condition_field_ids as $i => $field_id) {

			if (empty($field_id)) {
				continue;
			}

			$field = GFFormsModel::get_field($form, $field_id);
			if (empty($field)) {
				continue;
			}

			$value    = !empty($condition_values['' . $i . '']) ? $condition_values['' . $i . ''] : '';
			$operator = !empty($condition_operators['' . $i . '']) ? $condition_operators['' . $i . ''] : 'is';

			$is_visible     = !GFFormsModel::is_field_hidden($form, $field, array());
			$field_value    = GFFormsModel::get_field_value($field, array());
			$is_value_match = GFFormsModel::is_value_match($field_value, $value, $operator);
			$check          = $is_value_match && $is_visible;

			if ($type == 'any' && $check) {
				return true;
			} else if ($type == 'all' && !$check) {
				return false;
			}
		}

		if ($type == 'any') {
			return false;
		} else {
			return true;
		}
	}


	public static function get_config_by_entry($entry)
	{
		$feed_id = gform_get_meta($entry["id"], "zibal_feed_id");
		$feed    = !empty($feed_id) ? GFPersian_DB_Zibal::get_feed($feed_id) : '';
		$return  = !empty($feed) ? $feed : false;

		return apply_filters(self::$author . '_gf_zibal_get_config_by_entry', apply_filters(self::$author . '_gf_gateway_get_config_by_entry', $return, $entry), $entry);
	}


	public static function delay_posts($is_disabled, $form, $entry)
	{

		$config = self::get_active_config($form);

		if (!empty($config) && is_array($config) && $config) {
			return true;
		}

		return $is_disabled;
	}


	public static function delay_addons($is_delayed, $form, $entry, $slug)
	{

		$config = self::get_active_config($form);

		if (!empty($config["meta"]) && is_array($config["meta"]) && $config = $config["meta"]) {

			$user_registration_slug = apply_filters('gf_user_registration_slug', 'gravityformsuserregistration');

			if ($slug != $user_registration_slug && !empty($config["addon"]) && $config["addon"] == 'true') {
				$flag = true;
			} elseif ($slug == $user_registration_slug && !empty($config["type"]) && $config["type"] == "subscription") {
				$flag = true;
			}

			if (!empty($flag)) {
				$fulfilled = gform_get_meta($entry['id'], $slug . '_is_fulfilled');
				$processed = gform_get_meta($entry['id'], 'processed_feeds');

				$is_delayed = empty($fulfilled) && rgempty($slug, $processed);
			}
		}

		return $is_delayed;
	}


	private static function redirect_confirmation($url, $ajax)
	{

		if (headers_sent() || $ajax) {
			$encoded_url = wp_json_encode((string) $url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
			if ($encoded_url === false) {
				$encoded_url = '""';
			}
			$confirmation = "<script type=\"text/javascript\">" . apply_filters('gform_cdata_open', '') . " function gformRedirect(){document.location.href=" . $encoded_url . ";}";
			if (!$ajax) {
				$confirmation .= 'gformRedirect();';
			}
			$confirmation .= apply_filters('gform_cdata_close', '') . '</script>';
		} else {
			$confirmation = array('redirect' => $url);
		}

		return $confirmation;
	}


	public static function confirmation_settings_fields($fields, $confirmation, $form)
	{
		$fields[0]['fields'][] = array(
			'type'          => 'select',
			'name'          => 'zibalPaymentResult',
			'label'         => __('نتیجه پرداخت زیبال', 'gravityformszibal'),
			'description'   => __('این تأییدیه فقط پس از نتیجه انتخاب‌شده اجرا می‌شود. منطق شرطی خود تأییدیه نیز اعمال خواهد شد.', 'gravityformszibal'),
			'default_value' => self::sanitize_confirmation_payment_result(rgar($confirmation, 'zibalPaymentResult')),
			'choices'       => array(
				array(
					'label' => __('همه پرداخت‌ها (پیش‌فرض)', 'gravityformszibal'),
					'value' => 'all',
				),
				array(
					'label' => __('پرداخت موفق', 'gravityformszibal'),
					'value' => 'success',
				),
				array(
					'label' => __('پرداخت ناموفق یا انصرافی', 'gravityformszibal'),
					'value' => 'failure',
				),
			),
		);

		return $fields;
	}


	public static function legacy_confirmation_ui_settings($ui_settings, $confirmation, $form)
	{
		$value = self::sanitize_confirmation_payment_result(rgar($confirmation, 'zibalPaymentResult'));
		$options = array(
			'all'     => __('همه پرداخت‌ها (پیش‌فرض)', 'gravityformszibal'),
			'success' => __('پرداخت موفق', 'gravityformszibal'),
			'failure' => __('پرداخت ناموفق یا انصرافی', 'gravityformszibal'),
		);
		$select = '<select id="zibalPaymentResult" name="zibalPaymentResult">';
		foreach ($options as $option_value => $option_label) {
			$select .= '<option value="' . esc_attr($option_value) . '" ' . selected($value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
		}
		$select .= '</select><p class="description">' . esc_html__('این تأییدیه فقط پس از نتیجه انتخاب‌شده اجرا می‌شود. منطق شرطی خود تأییدیه نیز اعمال خواهد شد.', 'gravityformszibal') . '</p>';

		$ui_settings['zibal_payment_result'] = '<tr><th><label for="zibalPaymentResult">' . esc_html__('نتیجه پرداخت زیبال', 'gravityformszibal') . '</label></th><td>' . $select . '</td></tr>';

		return $ui_settings;
	}


	public static function save_confirmation_payment_result($confirmation, $form, $is_new_confirmation = false)
	{
		$value = null;
		$field_names = array(
			'zibalPaymentResult',
			'_gform_setting_zibalPaymentResult',
		);

		foreach ($field_names as $field_name) {
			if (isset($_POST[$field_name])) {
				$value = sanitize_key(wp_unslash($_POST[$field_name]));
				break;
			}
		}

		if ($value === null) {
			$value = rgar($confirmation, 'zibalPaymentResult', 'all');
		}

		$confirmation['zibalPaymentResult'] = self::sanitize_confirmation_payment_result($value);

		return $confirmation;
	}


	private static function sanitize_confirmation_payment_result($value)
	{
		$value = sanitize_key((string) $value);

		return in_array($value, array('success', 'failure'), true) ? $value : 'all';
	}


	private static function normalize_payment_status_for_condition($status)
	{
		$status = strtolower(trim((string) $status));

		if (in_array($status, array('completed', 'success', 'successful', 'paid', 'active', 'actived', 'approved'), true)) {
			return 'completed';
		}

		if (in_array($status, array('failed', 'failure', 'cancelled', 'canceled'), true)) {
			return 'failed';
		}

		return $status;
	}


	public static function match_payment_status_condition($is_match, $field_value, $target_value, $operation, $source_field = null, $rule = null)
	{
		$field_id = is_array($rule) ? (string) rgar($rule, 'fieldId') : '';
		if ($field_id === '' && is_array($source_field)) {
			$field_id = (string) rgar($source_field, 'fieldId');
		} elseif ($field_id === '' && is_object($source_field) && isset($source_field->fieldId)) {
			$field_id = (string) $source_field->fieldId;
		}

		if ($field_id !== 'payment_status' || !in_array($operation, array('is', 'isnot'), true)) {
			return $is_match;
		}

		$field_value = self::normalize_payment_status_for_condition($field_value);
		$target_value = self::normalize_payment_status_for_condition($target_value);

		return $operation === 'isnot' ? $field_value !== $target_value : $field_value === $target_value;
	}


	private static function confirmation_matches_entry($confirmation, $form, $entry)
	{
		$logic = rgar($confirmation, 'conditionalLogic');
		$rules = is_array($logic) ? rgar($logic, 'rules') : array();
		if (empty($rules) || !is_array($rules)) {
			return false;
		}

		if (class_exists('GFFormsModel') && is_callable(array('GFFormsModel', 'evaluate_conditional_logic'))) {
			return GFFormsModel::evaluate_conditional_logic($logic, $form, $entry);
		}

		if (class_exists('RGFormsModel') && is_callable(array('RGFormsModel', 'evaluate_conditional_logic'))) {
			return GFFormsModel::evaluate_conditional_logic($logic, $form, $entry);
		}

		$matches = array();
		foreach ($rules as $rule) {
			$field_id = (string) rgar($rule, 'fieldId');
			$operation = (string) rgar($rule, 'operator');
			$target_value = rgar($rule, 'value');
			$field_value = rgar($entry, $field_id);

			if ($field_id === 'payment_status') {
				$matches[] = self::match_payment_status_condition(false, $field_value, $target_value, $operation, null, $rule);
			} elseif (class_exists('GFFormsModel') && method_exists('GFFormsModel', 'is_value_match')) {
				$matches[] = GFFormsModel::is_value_match($field_value, $target_value, $operation);
			} else {
				$matches[] = GFFormsModel::is_value_match($field_value, $target_value, $operation);
			}
		}

		$logic_type = strtolower((string) rgar($logic, 'logicType'));
		$is_match = $logic_type === 'any' ? in_array(true, $matches, true) : !in_array(false, $matches, true);
		$action_type = strtolower((string) rgar($logic, 'actionType'));

		return $action_type === 'hide' ? !$is_match : $is_match;
	}


	private static function get_payment_confirmation_form($form, $entry, $status)
	{
		$payment_result = self::normalize_payment_status_for_condition($status) === 'completed' ? 'success' : 'failure';
		$confirmations = rgar($form, 'confirmations');
		$selected = null;
		$default = null;

		foreach ((array) $confirmations as $confirmation_id => $confirmation) {
			if (!is_array($confirmation)) {
				continue;
			}

			$is_default = !empty($confirmation['isDefault']) || $confirmation_id === 'default';
			if ($is_default && $default === null) {
				$default = $confirmation;
			}

			$target_result = self::sanitize_confirmation_payment_result(rgar($confirmation, 'zibalPaymentResult'));
			if ($target_result !== 'all' && $target_result !== $payment_result) {
				continue;
			}

			$logic = rgar($confirmation, 'conditionalLogic');
			$rules = is_array($logic) ? rgar($logic, 'rules') : array();
			if (!empty($rules)) {
				if (self::confirmation_matches_entry($confirmation, $form, $entry)) {
					$selected = $confirmation;
					break;
				}
			} elseif (!$is_default && $target_result !== 'all') {
				$selected = $confirmation;
				break;
			}
		}

		if ($selected === null && $default !== null) {
			$default_result = self::sanitize_confirmation_payment_result(rgar($default, 'zibalPaymentResult'));
			if ($payment_result === 'success' && in_array($default_result, array('all', 'success'), true)) {
				$selected = $default;
			} elseif ($payment_result === 'failure' && $default_result === 'failure') {
				$selected = $default;
			}
		}

		if ($selected === null) {
			$is_success = $payment_result === 'success';
			$default_message = $is_success
				? __('پرداخت با موفقیت انجام شد.', 'gravityformszibal')
				: __('پرداخت ناموفق بود یا توسط شما لغو شد. لطفاً دوباره تلاش کنید.', 'gravityformszibal');
			$default_message = apply_filters('gform_zibal_default_payment_confirmation_message', $default_message, $payment_result, $form, $entry);
			$selected = array(
				'id'                => 'zibal-payment-' . ($is_success ? 'success' : 'failed'),
				'name'              => $is_success ? __('پرداخت موفق', 'gravityformszibal') : __('پرداخت ناموفق', 'gravityformszibal'),
				'isDefault'         => true,
				'type'              => 'message',
				'message'           => wp_kses_post((string) $default_message),
				'disableAutoformat' => false,
			);
		}

		$selected = apply_filters('gform_zibal_selected_payment_confirmation', $selected, $payment_result, $form, $entry);
		if (!is_array($selected)) {
			return $form;
		}

		unset($selected['conditionalLogic']);
		$selected['isDefault'] = true;
		$confirmation_id = !empty($selected['id']) ? sanitize_key((string) $selected['id']) : 'zibal-payment-result';
		$selected['id'] = $confirmation_id;
		$form['confirmations'] = array($confirmation_id => $selected);
		$form['confirmation'] = $selected;

		return $form;
	}


	private static function get_payment_confirmation_message($confirmation_form, $entry, $payment_result, $apply_confirmation_filters = true)
	{
		$selected = rgar($confirmation_form, 'confirmation');
		$message = is_array($selected) ? (string) rgar($selected, 'message') : '';
		if ($message === '') {
			$message = $payment_result === 'success'
				? __('پرداخت با موفقیت انجام شد.', 'gravityformszibal')
				: __('پرداخت ناموفق بود یا توسط شما لغو شد. لطفاً دوباره تلاش کنید.', 'gravityformszibal');
		}

		$disable_autoformat = is_array($selected) && !empty($selected['disableAutoformat']);
		$message = GFCommon::replace_variables($message, $confirmation_form, $entry, false, true, !$disable_autoformat);
		if ($apply_confirmation_filters) {
			$message = apply_filters('gform_confirmation', $message, $confirmation_form, $entry, false);
			$message = apply_filters('gform_confirmation_' . absint(rgar($confirmation_form, 'id')), $message, $confirmation_form, $entry, false);
		}

		if (!is_string($message) || trim($message) === '') {
			$message = $payment_result === 'success'
				? __('پرداخت با موفقیت انجام شد.', 'gravityformszibal')
				: __('پرداخت ناموفق بود یا توسط شما لغو شد. لطفاً دوباره تلاش کنید.', 'gravityformszibal');
		}

		return $message;
	}


	private static function get_payment_confirmation_return($form, $entry, $status, $ajax)
	{
		$payment_result = self::normalize_payment_status_for_condition($status) === 'completed' ? 'success' : 'failure';
		$confirmation_form = self::get_payment_confirmation_form($form, $entry, $status);
		$selected = rgar($confirmation_form, 'confirmation');
		$type = is_array($selected) ? (string) rgar($selected, 'type') : 'message';

		if ($type === 'redirect' || $type === 'page') {
			$url = $type === 'page' ? get_permalink(absint(rgar($selected, 'pageId'))) : (string) rgar($selected, 'url');
			$query_string = trim((string) rgar($selected, 'queryString'), "?& \t\n\r\0\x0B");
			$url = GFCommon::replace_variables($url, $confirmation_form, $entry, false, true, false);
			$query_string = GFCommon::replace_variables($query_string, $confirmation_form, $entry, false, true, false);
			if ($url !== '') {
				if ($query_string !== '') {
					$url .= (strpos($url, '?') === false ? '?' : '&') . $query_string;
				}

				return self::redirect_confirmation($url, $ajax);
			}
		}

		$message = self::get_payment_confirmation_message($confirmation_form, $entry, $payment_result, false);
		$message = apply_filters('gform_zibal_request_failure_confirmation', $message, $confirmation_form, $entry);
		$form_id = absint(rgar($confirmation_form, 'id'));
		$default_anchor = 0;
		$anchor = gf_apply_filters('gform_confirmation_anchor', $form_id, $default_anchor)
			? sprintf('<a id="gf_%1$d" name="gf_%1$d" class="gform_anchor"></a>', $form_id)
			: '';
		$css_classes = preg_split('/\s+/', (string) rgar($confirmation_form, 'cssClass'));
		$css_classes = array_filter(array_map('sanitize_html_class', $css_classes));

		return sprintf(
			'%1$s<div id="gform_confirmation_wrapper_%2$d" class="gform_confirmation_wrapper %3$s"><div id="gform_confirmation_message_%2$d" class="gform_confirmation_message_%2$d gform_confirmation_message">%4$s</div></div>',
			$anchor,
			$form_id,
			esc_attr(implode(' ', $css_classes)),
			$message
		);
	}


	private static function display_payment_confirmation($form, $entry, $status, $message = '')
	{
		$normalized_status = self::normalize_payment_status_for_condition($status);
		$payment_result = $normalized_status === 'completed' ? 'success' : 'failure';
		if (!empty($entry['id'])) {
			gform_update_meta($entry['id'], 'zibal_payment_result', $payment_result);
		}
		$entry['zibal_payment_result'] = $payment_result;
		$confirmation_form = self::get_payment_confirmation_form($form, $entry, $status);
		$selected = rgar($confirmation_form, 'confirmation');

		if (!is_array($selected) || (string) rgar($selected, 'type') !== 'message' || !class_exists('GFFormDisplay')) {
			GFPersian_Payments::confirmation($confirmation_form, $entry, '');

			return;
		}

		$confirmation_message = self::get_payment_confirmation_message($confirmation_form, $entry, $payment_result, true);
		if ($normalized_status === 'processing' && $message !== '') {
			$confirmation_message = '<p>' . esc_html($message) . '</p>';
		}
		$form_id = absint(rgar($confirmation_form, 'id'));
		$css_classes = preg_split('/\s+/', (string) rgar($confirmation_form, 'cssClass'));
		$css_classes = array_filter(array_map('sanitize_html_class', $css_classes));
		$default_anchor = 0;
		$anchor = gf_apply_filters('gform_confirmation_anchor', $form_id, $default_anchor)
			? sprintf('<a id="gf_%1$d" name="gf_%1$d" class="gform_anchor"></a>', $form_id)
			: '';
		$confirmation_message = sprintf(
			'%1$s<div id="gform_confirmation_wrapper_%2$d" class="gform_confirmation_wrapper %3$s"><div id="gform_confirmation_message_%2$d" class="gform_confirmation_message_%2$d gform_confirmation_message">%4$s</div></div>',
			$anchor,
			$form_id,
			esc_attr(implode(' ', $css_classes)),
			$confirmation_message
		);

		GFFormDisplay::$submission[$form_id] = array(
			'is_valid'             => true,
			'is_confirmation'      => true,
			'confirmation_message' => $confirmation_message,
			'form'                 => $confirmation_form,
			'lead'                 => $entry,
		);

		self::$payment_confirmation_content = $confirmation_message;
		self::$payment_confirmation_form_id = $form_id;
		add_filter('the_content', array(__CLASS__, 'replace_payment_form_content'), PHP_INT_MAX);
		self::send_direct_payment_confirmation_response($confirmation_message, $payment_result, $form, $entry);
	}


	private static function send_direct_payment_confirmation_response($confirmation_message, $payment_result, $form, $entry)
	{
		$send_directly = apply_filters(
			'gform_zibal_direct_confirmation_response',
			true,
			$payment_result,
			$form,
			$entry
		);
		if (!$send_directly) {
			return;
		}

		if (!headers_sent()) {
			if (function_exists('status_header')) {
				status_header(200);
			}
			if (function_exists('nocache_headers')) {
				nocache_headers();
			}
			header('Referrer-Policy: no-referrer');
			header('X-Robots-Tag: noindex, nofollow', true);
		}

		$title = $payment_result === 'success'
			? __('نتیجه پرداخت موفق', 'gravityformszibal')
			: __('نتیجه پرداخت ناموفق', 'gravityformszibal');
		$result_class = $payment_result === 'success' ? 'success' : 'failure';
		$content = sprintf(
			'<main id="zibal-payment-confirmation" class="zibal-payment-confirmation-page zibal-payment-confirmation-page--%1$s" role="status" aria-live="polite" tabindex="-1"><div class="zibal-payment-confirmation-card">%2$s</div></main>',
			esc_attr($result_class),
			wp_kses_post((string) $confirmation_message)
		);
		$presentation = '<style id="zibal-payment-confirmation-styles">
			.zibal-payment-confirmation-page{box-sizing:border-box;width:100%;min-height:45vh;padding:clamp(72px,8vw,128px) 20px 80px;clear:both;position:relative;z-index:1;direction:rtl}
			.zibal-payment-confirmation-card{box-sizing:border-box;width:min(100%,1200px);margin:0 auto;padding:clamp(20px,3vw,40px);background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 10px 30px rgba(15,23,42,.08);overflow-wrap:anywhere}
			.zibal-payment-confirmation-page--success .zibal-payment-confirmation-card{border-top:4px solid #16803c}
			.zibal-payment-confirmation-page--failure .zibal-payment-confirmation-card{border-top:4px solid #c62828}
			.zibal-payment-confirmation-card .gform_confirmation_wrapper{margin:0}
			@media(max-width:600px){.zibal-payment-confirmation-page{padding:72px 12px 48px}.zibal-payment-confirmation-card{padding:20px 14px;border-radius:10px}}
		</style>';
		$presentation .= '<script id="zibal-payment-confirmation-position">(function(){try{if(window.history&&window.history.replaceState&&window.URL){var clean=new URL(window.location.href);var keys=["zibal_token","zibal_callback","success","status","Status","trackId","orderId","orderid","no","id","entry"];for(var k=0;k<keys.length;k++){clean.searchParams.delete(keys[k]);}window.history.replaceState({},document.title,clean.pathname+(clean.search?clean.search:"")+(clean.hash?clean.hash:""));}}catch(ignore){}function reveal(){var main=document.getElementById("zibal-payment-confirmation");if(!main){return;}var bottom=0;var nodes=document.querySelectorAll("header,.site-header,#masthead,.elementor-location-header,.elementor-sticky--active");for(var i=0;i<nodes.length;i++){var style=window.getComputedStyle(nodes[i]);var rect=nodes[i].getBoundingClientRect();if((style.position==="fixed"||style.position==="sticky")&&rect.top<=10&&rect.bottom>bottom){bottom=rect.bottom;}}var gap=24;var target=window.pageYOffset+main.getBoundingClientRect().top-bottom-gap;window.scrollTo(0,Math.max(0,target));if(main.focus){try{main.focus({preventScroll:true});}catch(e){main.focus();}}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",reveal);}else{window.setTimeout(reveal,0);}})();</script>';
		$terminate = apply_filters(
			'gform_zibal_terminate_direct_confirmation_response',
			true,
			$payment_result,
			$form,
			$entry
		);

		if (function_exists('get_header') && function_exists('get_footer')) {
			get_header();
			echo $presentation . $content;
			get_footer();
		} else {
			wp_die($presentation . $content, esc_html($title), array('response' => 200, 'back_link' => false));
		}

		if ($terminate) {
			exit;
		}
	}


	public static function replace_payment_form_content($content)
	{
		if (self::$payment_confirmation_content === '' || self::$payment_confirmation_form_id < 1) {
			return $content;
		}

		if (function_exists('is_main_query') && !is_main_query()) {
			return $content;
		}

		if (function_exists('in_the_loop') && !in_the_loop()) {
			return $content;
		}

		$form_id = self::$payment_confirmation_form_id;
		$form_markers = array(
			'gform_wrapper_' . $form_id,
			'gform_' . $form_id,
			'gravityform id="' . $form_id . '"',
			"gravityform id='" . $form_id . "'",
		);
		$contains_form = false;
		foreach ($form_markers as $marker) {
			if (strpos((string) $content, $marker) !== false) {
				$contains_form = true;
				break;
			}
		}

		if (!$contains_form) {
			return $content;
		}

		$confirmation_content = self::$payment_confirmation_content;
		self::$payment_confirmation_content = '';
		self::$payment_confirmation_form_id = 0;

		return $confirmation_content;
	}


	private static function get_entry_for_payment($entry_id)
	{
		$entry_id = absint($entry_id);
		if ($entry_id < 1) {
			return new WP_Error('invalid_entry_id', __('شناسه ورودی پرداخت معتبر نیست.', 'gravityformszibal'));
		}

		if (class_exists('GFAPI') && is_callable(array('GFAPI', 'get_entry'))) {
			$entry = GFAPI::get_entry($entry_id);
			if (!is_wp_error($entry) && is_array($entry)) {
				return $entry;
			}
		}

		return GFPersian_Payments::get_entry($entry_id);
	}


	private static function acquire_verification_lock($entry_id)
	{
		$entry_id = absint($entry_id);
		$lock_name = 'gf_zibal_verify_lock_' . $entry_id;
		$now = time();
		$lock_token = uniqid((string) mt_rand(), true);
		$lock_value = $now . ':' . $lock_token;
		if (add_option($lock_name, $lock_value, '', 'no')) {
			self::$verification_lock_tokens[$entry_id] = $lock_value;

			return true;
		}

		$current_value = (string) get_option($lock_name);
		$current_parts = explode(':', $current_value, 2);
		$created_at = absint(isset($current_parts[0]) ? $current_parts[0] : 0);
		if ($created_at > 0 && $created_at < ($now - 120)) {
			global $wpdb;
			if (is_object($wpdb) && isset($wpdb->options) && is_callable(array($wpdb, 'query'))) {
				$updated = $wpdb->query($wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$lock_value,
					$lock_name,
					$current_value
				));
				if ((int) $updated === 1) {
					if (function_exists('wp_cache_delete')) {
						wp_cache_delete($lock_name, 'options');
					}
					self::$verification_lock_tokens[$entry_id] = $lock_value;

					return true;
				}
			}
		}

		return false;
	}


	private static function release_verification_lock($entry_id)
	{
		$entry_id = absint($entry_id);
		if (!isset(self::$verification_lock_tokens[$entry_id])) {
			return;
		}

		$lock_name = 'gf_zibal_verify_lock_' . $entry_id;
		$lock_value = self::$verification_lock_tokens[$entry_id];
		global $wpdb;
		if (is_object($wpdb) && isset($wpdb->options) && is_callable(array($wpdb, 'query'))) {
			$wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$lock_name,
				$lock_value
			));
			if (function_exists('wp_cache_delete')) {
				wp_cache_delete($lock_name, 'options');
			}
		} else if (self::secure_equals((string) get_option($lock_name), $lock_value)) {
			delete_option($lock_name);
		}

		unset(self::$verification_lock_tokens[$entry_id]);
	}


	private static function secure_equals($known_value, $provided_value)
	{
		$known_value = (string) $known_value;
		$provided_value = (string) $provided_value;

		if (function_exists('hash_equals')) {
			return hash_equals($known_value, $provided_value);
		}

		if (strlen($known_value) !== strlen($provided_value)) {
			return false;
		}

		$result = 0;
		$length = strlen($known_value);
		for ($index = 0; $index < $length; $index++) {
			$result |= ord($known_value[$index]) ^ ord($provided_value[$index]);
		}

		return $result === 0;
	}


	private static function is_retryable_zibal_result($result_code)
	{
		return in_array(
			(string) $result_code,
			array('wp_error', 'http_error', 'invalid_json', 'internal_error', 'exception'),
			true
		);
	}


	public static function get_active_config($form)
	{
		$form_id = absint(rgar($form, 'id'));

		if (array_key_exists($form_id, self::$config)) {
			return self::$config[$form_id];
		}

		$configs = GFPersian_DB_Zibal::get_feed_by_form($form_id, true);

		$configs = apply_filters(self::$author . '_gf_zibal_get_active_configs', apply_filters(self::$author . '_gf_gateway_get_active_configs', $configs, $form), $form);

		$return = false;

		if (!empty($configs) && is_array($configs)) {

			foreach ($configs as $config) {
				if (self::has_zibal_condition($form, $config)) {
					$return = $config;
					break;
				}
			}
		}

		self::$config[$form_id] = apply_filters(self::$author . '_gf_zibal_get_active_config', apply_filters(self::$author . '_gf_gateway_get_active_config', $return, $form), $form);

		return self::$config[$form_id];
	}


	public static function zibal_page()
	{
		$view = rgget("view");
		if ($view == "edit") {
			self::config_page();
		} else if ($view == "stats") {
			GFPersian_Chart_Zibal::stats_page();
		} else {
			self::list_page('');
		}
	}


	private static function list_page($arg)
	{
		if (!self::has_access()) {
			wp_die(esc_html__('شما مجوز کافی برای مدیریت فیدهای زیبال را ندارید.', 'gravityformszibal'));
		}

		if (!self::is_gravityforms_supported()) {
			wp_die(wp_kses_post(sprintf(__("درگاه زیبال نیاز به گرویتی فرم نسخه %s دارد. برای بروز رسانی هسته گرویتی فرم به %sسایت گرویتی فرم فارسی%s مراجعه نمایید .", "gravityformszibal"), esc_html(self::$min_gravityforms_version), "<a href='https://gravityforms.ir/11378' target='_blank' rel='noopener noreferrer'>", "</a>")));
		}

		if (rgpost('action') == "delete") {
			check_admin_referer("list_action", "gf_zibal_list");
			$id = absint(rgpost("action_argument"));
			GFPersian_DB_Zibal::delete_feed($id);
		?>
			<div class="updated fade" style="padding:6px"><?php _e("فید حذف شد", "gravityformszibal") ?></div><?php
		} else if (rgpost("bulk_action") === 'delete') {

																												check_admin_referer("list_action", "gf_zibal_list");
																												$selected_feeds = rgpost("feed");
																												if (is_array($selected_feeds)) {
																													foreach ($selected_feeds as $feed_id) {
																														GFPersian_DB_Zibal::delete_feed(absint($feed_id));
																													}
																												}

																												?>
			<div class="updated fade" style="padding:6px"><?php _e("فید ها حذف شدند", "gravityformszibal") ?></div>
		<?php
																											}
		?>
		<div class="wrap">

			<?php if ($arg != 'per-form') { ?>

				<h2>
					<?php _e("فرم های زیبال", "gravityformszibal");
					if (get_option("gf_zibal_configured")) { ?>
						<a class="add-new-h2" href="admin.php?page=gf_zibal&view=edit"><?php _e("افزودن جدید", "gravityformszibal") ?></a>
					<?php
					} ?>
				</h2>

			<?php } ?>

			<form id="confirmation_list_form" method="post">
				<?php wp_nonce_field('list_action', 'gf_zibal_list') ?>
				<input type="hidden" id="action" name="action" />
				<input type="hidden" id="action_argument" name="action_argument" />
				<div class="tablenav">
					<div class="alignleft actions" style="padding:8px 0 7px 0;">
						<label class="hidden" for="bulk_action"><?php _e("اقدام دسته جمعی", "gravityformszibal") ?></label>
						<select name="bulk_action" id="bulk_action">
							<option value=''> <?php _e("اقدامات دسته جمعی", "gravityformszibal") ?> </option>
							<option value='delete'><?php _e("حذف", "gravityformszibal") ?></option>
						</select>
						<?php
						echo '<input type="submit" class="button" value="' . __("اعمال", "gravityformszibal") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("فید حذف شود ؟ ", "gravityformszibal") . __("\'Cancel\' برای منصرف شدن, \'OK\' برای حذف کردن", "gravityformszibal") . '\')) { return false; } return true;"/>';
						?>
						<a class="button button-primary" href="admin.php?page=gf_settings&subview=gf_zibal"><?php _e('تنظیمات حساب زیبال', 'gravityformszibal') ?></a>
					</div>
				</div>
				<table class="wp-list-table widefat fixed striped toplevel_page_gf_edit_forms" cellspacing="0">
					<thead>
						<tr>
							<th scope="col" id="cb" class="manage-column column-cb check-column" style="padding:13px 3px;width:30px"><input type="checkbox" /></th>
							<th scope="col" id="active" class="manage-column" style="width:<?php echo $arg != 'per-form' ? '50px' : '20px' ?>"><?php echo $arg != 'per-form' ? __('وضعیت', 'gravityformszibal') : '' ?></th>
							<th scope="col" class="manage-column" style="width:<?php echo $arg != 'per-form' ? '65px' : '30%' ?>"><?php _e(" آیدی فید", "gravityformszibal") ?></th>
							<?php if ($arg != 'per-form') { ?>
								<th scope="col" class="manage-column"><?php _e("فرم متصل به درگاه", "gravityformszibal") ?></th>
							<?php } ?>
							<th scope="col" class="manage-column"><?php _e("نوع تراکنش", "gravityformszibal") ?></th>
						</tr>
					</thead>
					<tfoot>
						<tr>
							<th scope="col" id="cb" class="manage-column column-cb check-column" style="padding:13px 3px;">
								<input type="checkbox" />
							</th>
							<th scope="col" id="active" class="manage-column"><?php echo $arg != 'per-form' ? __('وضعیت', 'gravityformszibal') : '' ?></th>
							<th scope="col" class="manage-column"><?php _e("آیدی فید", "gravityformszibal") ?></th>
							<?php if ($arg != 'per-form') { ?>
								<th scope="col" class="manage-column"><?php _e("فرم متصل به درگاه", "gravityformszibal") ?></th>
							<?php } ?>
							<th scope="col" class="manage-column"><?php _e("نوع تراکنش", "gravityformszibal") ?></th>
						</tr>
					</tfoot>
					<tbody class="list:user user-list">
						<?php
						if ($arg != 'per-form') {
							$settings = GFPersian_DB_Zibal::get_feeds();
						} else {
							$settings = GFPersian_DB_Zibal::get_feed_by_form(absint(rgget('id')), false);
						}

						if (!get_option("gf_zibal_configured")) {
						?>
							<tr>
								<td colspan="5" style="padding:20px;">
									<?php echo sprintf(__("برای شروع باید درگاه را فعال نمایید . به %sتنظیمات زیبال%s بروید . ", "gravityformszibal"), '<a href="admin.php?page=gf_settings&subview=gf_zibal">', "</a>"); ?>
								</td>
							</tr>
							<?php
						} else if (is_array($settings) && sizeof($settings) > 0) {
							foreach ($settings as $setting) {
							?>
								<tr class='author-self status-inherit' valign="top">

									<th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo absint($setting["id"]) ?>" /></th>

									<td><img style="cursor:pointer;width:25px" src="<?php echo esc_url(GFCommon::get_base_url()) ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo esc_attr($setting["is_active"] ? __("درگاه فعال است", "gravityformszibal") : __("درگاه غیر فعال است", "gravityformszibal")); ?>" title="<?php echo esc_attr($setting["is_active"] ? __("درگاه فعال است", "gravityformszibal") : __("درگاه غیر فعال است", "gravityformszibal")); ?>" onclick="ToggleActive(this, <?php echo absint($setting['id']) ?>); " /></td>

									<td><?php echo absint($setting["id"]) ?>
										<?php if ($arg == 'per-form') { ?>
											<div class="row-actions">
												<span class="edit">
													<a title="<?php esc_attr_e("ویرایش فید", "gravityformszibal") ?>" href="<?php echo esc_url(admin_url('admin.php?page=gf_zibal&view=edit&id=' . absint($setting["id"]))) ?>"><?php _e("ویرایش فید", "gravityformszibal") ?></a>
													|
												</span>
												<span class="trash">
													<a title="<?php _e("حذف", "gravityformszibal") ?>" href="javascript: if(confirm('<?php _e("فید حذف شود؟ ", "gravityformszibal") ?> <?php _e("\'Cancel\' برای انصراف, \'OK\' برای حذف کردن.", "gravityformszibal") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("حذف", "gravityformszibal") ?></a>
												</span>
											</div>
										<?php } ?>
									</td>

									<?php if ($arg != 'per-form') { ?>
										<td class="column-title">
											<strong><a class="row-title" href="<?php echo esc_url(admin_url('admin.php?page=gf_zibal&view=edit&id=' . absint($setting["id"]))) ?>" title="<?php esc_attr_e("تنظیم مجدد درگاه", "gravityformszibal") ?>"><?php echo esc_html($setting["form_title"]) ?></a></strong>

											<div class="row-actions">
												<span class="edit">
													<a title="<?php esc_attr_e("ویرایش فید", "gravityformszibal") ?>" href="<?php echo esc_url(admin_url('admin.php?page=gf_zibal&view=edit&id=' . absint($setting["id"]))) ?>"><?php _e("ویرایش فید", "gravityformszibal") ?></a>
													|
												</span>
												<span class="trash">
													<a title="<?php _e("حذف فید", "gravityformszibal") ?>" href="javascript: if(confirm('<?php _e("فید حذف شود؟ ", "gravityformszibal") ?> <?php _e("\'Cancel\' برای انصراف, \'OK\' برای حذف کردن.", "gravityformszibal") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("حذف", "gravityformszibal") ?></a>
													|
												</span>
												<span class="view">
													<a title="<?php esc_attr_e("ویرایش فرم", "gravityformszibal") ?>" href="<?php echo esc_url(admin_url('admin.php?page=gf_edit_forms&id=' . absint($setting["form_id"]))) ?>"><?php _e("ویرایش فرم", "gravityformszibal") ?></a>
													|
												</span>
												<span class="view">
													<a title="<?php esc_attr_e("مشاهده صندوق ورودی", "gravityformszibal") ?>" href="<?php echo esc_url(admin_url('admin.php?page=gf_entries&view=entries&id=' . absint($setting["form_id"]))) ?>"><?php _e("صندوق ورودی", "gravityformszibal") ?></a>
													|
												</span>
												<span class="view">
													<a title="<?php esc_attr_e("نمودارهای فرم", "gravityformszibal") ?>" href="<?php echo esc_url(admin_url('admin.php?page=gf_zibal&view=stats&id=' . absint($setting["form_id"]))) ?>"><?php _e("نمودارهای فرم", "gravityformszibal") ?></a>
												</span>
											</div>
										</td>
									<?php } ?>


									<td class="column-date">
										<?php
										if (isset($setting["meta"]["type"]) && $setting["meta"]["type"] == 'subscription') {
											_e("عضویت", "gravityformszibal");
										} else {
											_e("محصول معمولی یا فرم ارسال پست", "gravityformszibal");
										}
										?>
									</td>
								</tr>
							<?php
							}
						} else {
							?>
							<tr>
								<td colspan="5" style="padding:20px;">
									<?php
									if ($arg == 'per-form') {
										echo sprintf(__("شما هیچ فید زیبالی ندارید . %sیکی بسازید%s .", "gravityformszibal"), '<a href="admin.php?page=gf_zibal&view=edit&fid=' . absint(rgget("id")) . '">', "</a>");
									} else {
										echo sprintf(__("شما هیچ فید زیبالی ندارید . %sیکی بسازید%s .", "gravityformszibal"), '<a href="admin.php?page=gf_zibal&view=edit">', "</a>");
									}
									?>
								</td>
							</tr>
						<?php
						}
						?>
					</tbody>
				</table>
			</form>
		</div>
		<script type="text/javascript">
			function DeleteSetting(id) {
				jQuery("#action_argument").val(id);
				jQuery("#action").val("delete");
				jQuery("#confirmation_list_form")[0].submit();
			}

			function ToggleActive(img, feed_id) {
				var is_active = img.src.indexOf("active1.png") >= 0;
				if (is_active) {
					img.src = img.src.replace("active1.png", "active0.png");
					jQuery(img).attr('title', '<?php _e("درگاه غیر فعال است", "gravityformszibal") ?>').attr('alt', '<?php _e("درگاه غیر فعال است", "gravityformszibal") ?>');
				} else {
					img.src = img.src.replace("active0.png", "active1.png");
					jQuery(img).attr('title', '<?php _e("درگاه فعال است", "gravityformszibal") ?>').attr('alt', '<?php _e("درگاه فعال است", "gravityformszibal") ?>');
				}
				var mysack = new sack(ajaxurl);
				mysack.execute = 1;
				mysack.method = 'POST';
				mysack.setVar("action", "gf_zibal_update_feed_active");
				mysack.setVar("gf_zibal_update_feed_active", "<?php echo wp_create_nonce("gf_zibal_update_feed_active") ?>");
				mysack.setVar("feed_id", feed_id);
				mysack.setVar("is_active", is_active ? 0 : 1);
				mysack.onError = function() {
					alert('<?php _e("خطای Ajax رخ داده است", "gravityformszibal") ?>')
				};
				mysack.runAJAX();
				return true;
			}
		</script>
		<?php
	}


	public static function update_feed_active()
	{
		check_ajax_referer('gf_zibal_update_feed_active', 'gf_zibal_update_feed_active');
		if (!self::has_access()) {
			wp_die(__('شما مجوز کافی برای این کار را ندارید.', 'gravityformszibal'));
		}

		$id = absint(rgpost('feed_id'));
		if (empty($id)) {
			wp_die(__('فید درخواستی معتبر نیست.', 'gravityformszibal'));
		}

		$feed = GFPersian_DB_Zibal::get_feed($id);
		if (empty($feed)) {
			wp_die(__('فید درخواستی پیدا نشد.', 'gravityformszibal'));
		}

		$feed_form_id   = absint($feed["form_id"]);
		$feed_meta      = is_array($feed["meta"]) ? $feed["meta"] : array();
		$post_is_active_value = isset($_POST["is_active"]) ? sanitize_key(wp_unslash($_POST["is_active"])) : '';
		$post_is_active = in_array($post_is_active_value, array('1', 'true'), true) ? 1 : 0;
		GFPersian_DB_Zibal::update_feed($id, $feed_form_id, $post_is_active, $feed_meta);

		wp_die();
	}


	private static function Return_URL($form_id, $entry_id)
	{
		$scheme = GFCommon::is_ssl() ? 'https' : 'http';
		$request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
		$request_uri = '/' . ltrim((string) $request_uri, '/');
		$request_parts = parse_url($request_uri);
		$request_path = is_array($request_parts) && isset($request_parts['path']) ? (string) $request_parts['path'] : '/';
		$request_query = is_array($request_parts) && isset($request_parts['query']) ? (string) $request_parts['query'] : '';

		/*
		 * REQUEST_URI includes the WordPress subdirectory, while home_url() adds it
		 * itself. Remove that prefix before passing the path to home_url() so an
		 * installation such as /test1 does not produce /test1/test1/... callbacks.
		 */
		$home_path = parse_url(home_url('/', $scheme), PHP_URL_PATH);
		$home_path = is_string($home_path) ? rtrim($home_path, '/') : '';
		if ($home_path !== '' && ($request_path === $home_path || strpos($request_path, $home_path . '/') === 0)) {
			$request_path = (string) substr($request_path, strlen($home_path));
		}

		$request_path = '/' . ltrim($request_path, '/');
		$pageURL = home_url($request_path, $scheme);
		if ($request_query !== '') {
			$pageURL .= '?' . $request_query;
		}
		$pageURL = esc_url_raw($pageURL);

		$arr_params = array('id', 'entry', 'no', 'trackId', 'Status', 'status', 'success', 'orderId', 'orderid', 'zibal_token', 'zibal_callback');
		$pageURL    = esc_url_raw(remove_query_arg($arr_params, $pageURL));
		$payment_token = gform_get_meta($entry_id, 'zibal_payment_token');
		if (empty($payment_token)) {
			$payment_token = wp_generate_password(32, false, false);
			gform_update_meta($entry_id, 'zibal_payment_token', $payment_token);
		}

		$pageURL = add_query_arg(array(
			'id'             => $form_id,
			'entry'          => $entry_id,
			'zibal_token'    => $payment_token,
			'zibal_callback' => '1',
		), $pageURL);

		return apply_filters(self::$author . '_zibal_return_url', apply_filters(self::$author . '_gateway_return_url', $pageURL, $form_id, $entry_id, __CLASS__), $form_id, $entry_id, __CLASS__);
	}


	public static function get_order_total($form, $entry)
	{

		$total = GFCommon::get_order_total($form, $entry);
		$total = (!empty($total) && $total > 0) ? $total : 0;

		return apply_filters(self::$author . '_zibal_get_order_total', apply_filters(self::$author . '_gateway_get_order_total', $total, $form, $entry), $form, $entry);
	}


	private static function get_mapped_field_list($field_name, $selected_field, $fields)
	{
		$field_name = sanitize_key($field_name);
		$str = "<select name='" . esc_attr($field_name) . "' id='" . esc_attr($field_name) . "'><option value=''></option>";
		if (is_array($fields)) {
			foreach ($fields as $field) {
				$field_id    = $field[0];
				$field_label = esc_html(GFCommon::truncate_middle($field[1], 40));
				$selected    = $field_id == $selected_field ? "selected='selected'" : "";
				$str         .= "<option value='" . esc_attr($field_id) . "' " . $selected . ">" . $field_label . "</option>";
			}
		}
		$str .= "</select>";

		return $str;
	}


	private static function get_form_fields($form)
	{
		$fields = array();
		if (is_array($form["fields"])) {
			foreach ($form["fields"] as $field) {
				if (isset($field["inputs"]) && is_array($field["inputs"])) {
					foreach ($field["inputs"] as $input) {
						$fields[] = array($input["id"], GFCommon::get_label($field, $input["id"]));
					}
				} else if (!rgar($field, 'displayOnly')) {
					$fields[] = array($field["id"], GFCommon::get_label($field));
				}
			}
		}

		return $fields;
	}

	// --------------------------------------------
	//desc
	private static function get_customer_information_desc($form, $config = null)
	{
		$form_fields    = self::get_form_fields($form);
		$selected_field = !empty($config["meta"]["customer_fields_desc"]) ? $config["meta"]["customer_fields_desc"] : '';

		return self::get_mapped_field_list('zibal_customer_field_desc', $selected_field, $form_fields);
	}

	//email
	private static function get_customer_information_email($form, $config = null)
	{
		$form_fields    = self::get_form_fields($form);
		$selected_field = !empty($config["meta"]["customer_fields_email"]) ? $config["meta"]["customer_fields_email"] : '';

		return self::get_mapped_field_list('zibal_customer_field_email', $selected_field, $form_fields);
	}

	//mobile
	private static function get_customer_information_mobile($form, $config = null)
	{
		$form_fields    = self::get_form_fields($form);
		$selected_field = !empty($config["meta"]["customer_fields_mobile"]) ? $config["meta"]["customer_fields_mobile"] : '';

		return self::get_mapped_field_list('zibal_customer_field_mobile', $selected_field, $form_fields);
	}
	// ------------------------------------------------------------------------------------------------------------



	public static function payment_entry_detail($form_id, $entry)
	{
		if (!self::has_access()) {
			return;
		}

		$payment_gateway = rgar($entry, "payment_method");

		if (!empty($payment_gateway) && $payment_gateway == "zibal") {

			do_action('gf_gateway_entry_detail');

		?>
			<hr />
			<strong>
				<?php _e('اطلاعات تراکنش :', 'gravityformszibal') ?>
			</strong>
			<br />
			<br />
			<?php

			$transaction_type = rgar($entry, "transaction_type");
			$payment_status   = rgar($entry, "payment_status");
			$payment_amount   = rgar($entry, "payment_amount");

			if (empty($payment_amount)) {
				$form           = GFFormsModel::get_form_meta($form_id);
				$payment_amount = self::get_order_total($form, $entry);
			}

			$transaction_id = rgar($entry, "transaction_id");
			$payment_date   = rgar($entry, "payment_date");

			$payment_date = self::format_payment_date_for_display($payment_date);

			$payment_status_persian = self::get_payment_status_label($payment_status);

			if (!strtolower((string) rgpost("save")) || GFForms::post("screen_mode") != "edit") {
				echo esc_html__('وضعیت پرداخت : ', 'gravityformszibal') . esc_html($payment_status_persian) . '<br/><br/>';
				echo esc_html__('تاریخ پرداخت : ', 'gravityformszibal') . '<span style="">' . esc_html($payment_date) . '</span><br/><br/>';
				echo esc_html__('مبلغ پرداختی : ', 'gravityformszibal') . esc_html(GFCommon::to_money($payment_amount, rgar($entry, "currency"))) . '<br/><br/>';
				echo esc_html__('کد رهگیری : ', 'gravityformszibal') . esc_html($transaction_id) . '<br/><br/>';
				self::print_zibal_transaction_meta($entry);
				esc_html_e('درگاه پرداخت : زیبال', 'gravityformszibal');
				$retry_url = self::get_retry_verification_url($entry);
				if ($retry_url !== '' && !in_array($payment_status, array('Paid', 'Active'), true)) {
					echo '<br/><br/><a class="button button-secondary" href="' . esc_url($retry_url) . '">' . esc_html__('بررسی مجدد پرداخت در زیبال', 'gravityformszibal') . '</a>';
				}
			} else {
				$payment_string = '';
				$payment_string .= '<select id="payment_status" name="payment_status">';
				$payment_string .= '<option value="' . esc_attr($payment_status) . '" selected>' . esc_html($payment_status_persian) . '</option>';

				if ($transaction_type == 1) {
					if ($payment_status != "Paid") {
						$payment_string .= '<option value="Paid">' . __('موفق', 'gravityformszibal') . '</option>';
					}
				}

				if ($transaction_type == 2) {
					if ($payment_status != "Active") {
						$payment_string .= '<option value="Active">' . __('موفق', 'gravityformszibal') . '</option>';
					}
				}

				if (!$transaction_type) {

					if ($payment_status != "Paid") {
						$payment_string .= '<option value="Paid">' . __('موفق', 'gravityformszibal') . '</option>';
					}

					if ($payment_status != "Active") {
						$payment_string .= '<option value="Active">' . __('موفق', 'gravityformszibal') . '</option>';
					}
				}

				if ($payment_status != "Failed") {
					$payment_string .= '<option value="Failed">' . __('ناموفق', 'gravityformszibal') . '</option>';
				}

				if ($payment_status != "Cancelled") {
					$payment_string .= '<option value="Cancelled">' . __('منصرف شده', 'gravityformszibal') . '</option>';
				}

				if ($payment_status != "Processing") {
					$payment_string .= '<option value="Processing">' . __('معلق', 'gravityformszibal') . '</option>';
				}

				$payment_string .= '</select>';

				echo esc_html__('وضعیت پرداخت :', 'gravityformszibal') . $payment_string . '<br/><br/>';
			?>
				<div id="edit_payment_status_details" style="display:block">
					<table>
						<tr>
							<td><?php _e('تاریخ پرداخت :', 'gravityformszibal') ?></td>
							<td><input type="text" id="payment_date" name="payment_date" value="<?php echo esc_attr($payment_date) ?>"></td>
						</tr>
						<tr>
							<td><?php _e('مبلغ پرداخت :', 'gravityformszibal') ?></td>
							<td><input type="text" id="payment_amount" name="payment_amount" value="<?php echo esc_attr($payment_amount) ?>"></td>
						</tr>
						<tr>
							<td><?php _e('شماره تراکنش :', 'gravityformszibal') ?></td>
							<td><input type="text" id="zibal_transaction_id" name="zibal_transaction_id" value="<?php echo esc_attr($transaction_id) ?>"></td>
						</tr>

					</table>
					<br />
				</div>
		<?php
				echo __('درگاه پرداخت : زیبال (غیر قابل ویرایش)', 'gravityformszibal');
			}

			echo '<br/>';
		}
	}


	private static function get_retry_verification_url($entry)
	{
		$entry_id = absint(rgar($entry, 'id'));
		$form_id = absint(rgar($entry, 'form_id'));
		if ($entry_id < 1 || $form_id < 1 || rgar($entry, 'payment_method') !== 'zibal') {
			return '';
		}

		$track_id = substr(sanitize_text_field((string) gform_get_meta($entry_id, 'zibal_track_id')), 0, 128);
		$payment_token = substr(sanitize_text_field((string) gform_get_meta($entry_id, 'zibal_payment_token')), 0, 128);
		$callback_url = esc_url_raw((string) gform_get_meta($entry_id, 'zibal_callback_url'));
		if ($track_id === '' || $payment_token === '' || $callback_url === '') {
			return '';
		}

		$callback_url = wp_validate_redirect($callback_url, '');
		if ($callback_url === '' || !self::is_same_site_url($callback_url)) {
			return '';
		}

		$admin_url = add_query_arg(array(
			'action'   => 'gf_zibal_retry_verification',
			'entry_id' => $entry_id,
		), admin_url('admin-post.php'));

		return wp_nonce_url($admin_url, 'gf_zibal_retry_verification_' . $entry_id);
	}


	private static function is_same_site_url($url)
	{
		$url_parts = wp_parse_url((string) $url);
		$home_parts = wp_parse_url(home_url('/'));
		if (!is_array($url_parts) || !is_array($home_parts) || empty($url_parts['host']) || empty($home_parts['host'])) {
			return false;
		}

		$scheme = isset($url_parts['scheme']) ? strtolower((string) $url_parts['scheme']) : '';
		if (!in_array($scheme, array('http', 'https'), true)) {
			return false;
		}

		$url_port = isset($url_parts['port']) ? absint($url_parts['port']) : ($scheme === 'https' ? 443 : 80);
		$home_scheme = isset($home_parts['scheme']) ? strtolower((string) $home_parts['scheme']) : 'http';
		$home_port = isset($home_parts['port']) ? absint($home_parts['port']) : ($home_scheme === 'https' ? 443 : 80);

		return self::secure_equals(strtolower((string) $home_parts['host']), strtolower((string) $url_parts['host'])) && $url_port === $home_port;
	}


	public static function retry_verification()
	{
		if (!self::has_access()) {
			wp_die(esc_html__('شما مجوز کافی برای بررسی مجدد پرداخت را ندارید.', 'gravityformszibal'));
		}

		$entry_id = isset($_GET['entry_id']) ? absint(wp_unslash($_GET['entry_id'])) : 0;
		if ($entry_id < 1) {
			wp_die(esc_html__('شناسه ورودی پرداخت معتبر نیست.', 'gravityformszibal'));
		}
		check_admin_referer('gf_zibal_retry_verification_' . $entry_id);

		$entry = self::get_entry_for_payment($entry_id);
		$retry_url = !is_wp_error($entry) && is_array($entry) ? self::get_retry_verification_url($entry) : '';
		if ($retry_url === '') {
			wp_die(esc_html__('اطلاعات لازم برای بررسی مجدد این پرداخت کامل نیست.', 'gravityformszibal'));
		}

		/* Rebuild from protected entry metadata; never redirect to a submitted URL. */
		$track_id = substr(sanitize_text_field((string) gform_get_meta($entry_id, 'zibal_track_id')), 0, 128);
		$payment_token = substr(sanitize_text_field((string) gform_get_meta($entry_id, 'zibal_payment_token')), 0, 128);
		$base_callback = wp_validate_redirect(esc_url_raw((string) gform_get_meta($entry_id, 'zibal_callback_url')), '');
		if ($track_id === '' || $payment_token === '' || $base_callback === '' || !self::is_same_site_url($base_callback)) {
			wp_die(esc_html__('اطلاعات لازم برای بررسی مجدد این پرداخت کامل نیست.', 'gravityformszibal'));
		}
		$base_callback = remove_query_arg(array('id', 'entry', 'no', 'trackId', 'Status', 'status', 'success', 'orderId', 'orderid', 'zibal_token', 'zibal_callback'), $base_callback);
		$callback = add_query_arg(array(
			'id'             => absint(rgar($entry, 'form_id')),
			'entry'          => $entry_id,
			'zibal_token'    => $payment_token,
			'zibal_callback' => '1',
			'success'        => '1',
			'trackId'        => $track_id,
		), $base_callback);

		wp_safe_redirect($callback);
		exit;
	}


	public static function update_payment_entry($form, $entry_id)
	{
		if (!self::has_access()) {
			return;
		}

		check_admin_referer('gforms_save_entry', 'gforms_save_entry');

		do_action('gf_gateway_update_entry');

		$entry = GFPersian_Payments::get_entry($entry_id);

		$payment_gateway = rgar($entry, "payment_method");

		if (empty($payment_gateway)) {
			return;
		}

		if ($payment_gateway != "zibal") {
			return;
		}

		$payment_status = rgpost("payment_status");
		if (empty($payment_status)) {
			$payment_status = rgar($entry, "payment_status");
		}
		$allowed_payment_statuses = array('Paid', 'Active', 'Cancelled', 'Failed', 'Processing');
		if (!in_array($payment_status, $allowed_payment_statuses, true)) {
			$payment_status = 'Processing';
		}

		$payment_amount = rgpost("payment_amount");
		$payment_amount = is_numeric($payment_amount) && (float) $payment_amount >= 0 ? (float) $payment_amount : 0;
		$payment_transaction = sanitize_text_field((string) rgpost("zibal_transaction_id"));
		$is_paid_status = in_array($payment_status, array('Paid', 'Active'), true);
		$payment_date = $is_paid_status
			? self::parse_admin_payment_date(rgpost("payment_date"), rgar($entry, "payment_date") ?: rgar($entry, "date_created"))
			: '';
		if (!$is_paid_status) {
			$payment_amount = 0;
		}

		global $current_user;
		$user_id   = 0;
		$user_name = __("مهمان", 'gravityformszibal');
		if ($current_user && $user_data = get_userdata($current_user->ID)) {
			$user_id   = $current_user->ID;
			$user_name = $user_data->display_name;
		}

		$entry["payment_status"] = $payment_status;
		$entry["payment_amount"] = $payment_amount;
		$entry["payment_date"]   = $payment_date;
		$entry["transaction_id"] = $payment_transaction;
		if ($is_paid_status) {
			$entry["is_fulfilled"] = 1;
		} else {
			$entry["is_fulfilled"] = 0;
		}
		GFAPI::update_entry($entry);
		self::persist_zibal_transaction_id($entry["id"], $payment_transaction, $entry);

		$new_status = '';
		switch (rgar($entry, "payment_status")) {
			case "Active":
				$new_status = __('موفق', 'gravityformszibal');
				break;

			case "Paid":
				$new_status = __('موفق', 'gravityformszibal');
				break;

			case "Cancelled":
				$new_status = __('منصرف شده', 'gravityformszibal');
				break;

			case "Failed":
				$new_status = __('ناموفق', 'gravityformszibal');
				break;

			case "Processing":
				$new_status = __('معلق', 'gravityformszibal');
				break;
		}

		GFFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("اطلاعات تراکنش به صورت دستی ویرایش شد . وضعیت : %s - مبلغ : %s - کد رهگیری : %s - تاریخ : %s", "gravityformszibal"), $new_status, GFCommon::to_money($entry["payment_amount"], $entry["currency"]), $payment_transaction, $entry["payment_date"]));
	}

	private static function get_payment_status_label($payment_status)
	{
		switch ($payment_status) {
			case 'Paid':
			case 'Active':
				return __('موفق', 'gravityformszibal');

			case 'Cancelled':
				return __('منصرف شده', 'gravityformszibal');

			case 'Failed':
				return __('ناموفق', 'gravityformszibal');

			case 'Processing':
				return __('معلق', 'gravityformszibal');

			default:
				return !empty($payment_status) ? esc_html($payment_status) : __('نامشخص', 'gravityformszibal');
		}
	}

	private static function get_gmt_offset()
	{
		$offset = function_exists('get_option') ? get_option('gmt_offset') : 0;

		return is_numeric($offset) ? (float) $offset : 0;
	}

	private static function apply_gmt_offset($payment_date, $direction = 'display')
	{
		try {
			$date = new DateTime((string) $payment_date);
		} catch (Exception $exception) {
			return '';
		}

		$tzb = self::get_gmt_offset();
		$tzn = abs($tzb) * 3600;
		$tzh = intval(gmdate("H", (int) $tzn));
		$tzm = intval(gmdate("i", (int) $tzn));
		$interval = new DateInterval('P0DT' . $tzh . 'H' . $tzm . 'M');

		if ($direction == 'storage') {
			if (intval($tzb) < 0) {
				$date->add($interval);
			} else {
				$date->sub($interval);
			}
		} else {
			if (intval($tzb) < 0) {
				$date->sub($interval);
			} else {
				$date->add($interval);
			}
		}

		return $date->format('Y-m-d H:i:s');
	}

	private static function format_payment_date_for_display($payment_date)
	{
		if (empty($payment_date)) {
			return '-';
		}

		$payment_date = self::apply_gmt_offset($payment_date, 'display');
		if (empty($payment_date)) {
			return '-';
		}

		if (function_exists('GF_jdate')) {
			return GF_jdate('Y-m-d H:i:s', strtotime($payment_date), '', date_default_timezone_get(), 'en');
		}

		return $payment_date;
	}

	private static function parse_admin_payment_date($payment_date, $fallback = '')
	{
		$payment_date = trim((string) $payment_date);

		if ($payment_date === '') {
			return !empty($fallback) ? $fallback : gmdate("Y-m-d H:i:s");
		}

		if (function_exists('GF_jalali_to_gregorian') && preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})\s+(\d{1,2}):(\d{1,2}):(\d{1,2})$/', $payment_date, $matches)) {
			$miladi = GF_jalali_to_gregorian($matches[1], $matches[2], $matches[3]);
			if (is_array($miladi) && count($miladi) >= 3) {
				$payment_date = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $miladi[0], $miladi[1], $miladi[2], $matches[4], $matches[5], $matches[6]);
			}
		}

		$timestamp = strtotime($payment_date);
		if ($timestamp === false) {
			return !empty($fallback) ? $fallback : gmdate("Y-m-d H:i:s");
		}

		return self::apply_gmt_offset(date("Y-m-d H:i:s", $timestamp), 'storage');
	}

	private static function print_zibal_transaction_meta($entry)
	{
		$entry_id = rgar($entry, 'id');
		if (empty($entry_id)) {
			return;
		}

		$fields = array(
			'zibal_track_id'    => __('شماره تراکنش زیبال : ', 'gravityformszibal'),
			'zibal_ref_number'  => __('شماره مرجع زیبال : ', 'gravityformszibal'),
			'zibal_card_number' => __('شماره کارت : ', 'gravityformszibal'),
			'zibal_order_id'    => __('شماره سفارش : ', 'gravityformszibal'),
			'zibal_paid_at'     => __('زمان پرداخت زیبال : ', 'gravityformszibal'),
			'zibal_status'      => __('وضعیت زیبال : ', 'gravityformszibal'),
			'zibal_result'      => __('کد پاسخ زیبال : ', 'gravityformszibal'),
		);

		foreach ($fields as $meta_key => $label) {
			$value = gform_get_meta($entry_id, $meta_key);
			if ($meta_key == 'zibal_card_number') {
				$display_value = ($value !== '' && $value !== null && $value !== false) ? (string) $value : '-';
				echo $label . esc_html($display_value) . '<br/><br/>';
			} else if ($value !== '' && $value !== null && $value !== false) {
				echo $label . esc_html($value) . '<br/><br/>';
			}
		}
	}

	private static function normalize_zibal_response($response)
	{
		if (is_array($response)) {
			return $response;
		}

		if (is_object($response)) {
			return json_decode(wp_json_encode($response), true);
		}

		if ($response === false || $response === null) {
			return array(
				'result'  => 'internal_error',
				'message' => __('پاسخ معتبر از زیبال دریافت نشد.', 'gravityformszibal'),
			);
		}

		return array(
			'result'  => 'invalid_response',
			'message' => sanitize_text_field((string) $response),
		);
	}

	private static function get_zibal_value($response, $keys, $default = '')
	{
		$response = self::normalize_zibal_response($response);
		foreach ((array) $keys as $key) {
			if (isset($response[$key]) && $response[$key] !== '') {
				return $response[$key];
			}

			if (isset($response['data']) && is_array($response['data']) && isset($response['data'][$key]) && $response['data'][$key] !== '') {
				return $response['data'][$key];
			}
		}

		return $default;
	}

	private static function get_zibal_transaction_id($response, $fallback = '')
	{
		$transaction_id = self::get_zibal_value($response, array('trackId', 'track_id', 'refNumber', 'ref_number'), $fallback);
		if (!is_scalar($transaction_id) && $transaction_id !== null) {
			$transaction_id = is_scalar($fallback) || $fallback === null ? $fallback : '';
		}

		return sanitize_text_field((string) $transaction_id);
	}

	private static function get_zibal_card_number($response)
	{
		$card_number = self::get_zibal_value($response, array('cardNumber', 'card_number', 'cardNo', 'card_no', 'pan', 'maskedCardNumber', 'masked_card_number'));

		return self::mask_zibal_card_number($card_number);
	}

	private static function mask_zibal_card_number($card_number)
	{
		if (!is_scalar($card_number) && $card_number !== null) {
			return '';
		}
		$card_number = sanitize_text_field((string) $card_number);
		$digits = preg_replace('/\D+/', '', $card_number);
		$length = strlen($digits);
		if ($length < 12) {
			return $card_number;
		}

		return substr($digits, 0, 6) . str_repeat('*', $length - 10) . substr($digits, -4);
	}

	private static function sanitize_zibal_response_for_storage($response)
	{
		if (!is_array($response)) {
			return is_scalar($response) || $response === null ? sanitize_text_field((string) $response) : '';
		}

		$sanitized = array();
		$card_keys = array('cardnumber', 'card_number', 'cardno', 'card_no', 'pan', 'maskedcardnumber', 'masked_card_number');
		foreach ($response as $key => $value) {
			$safe_key = is_int($key) ? $key : preg_replace('/[^A-Za-z0-9_-]/', '', (string) $key);
			$normalized_key = strtolower((string) $safe_key);
			if (in_array($normalized_key, $card_keys, true)) {
				$sanitized[$safe_key] = self::mask_zibal_card_number($value);
			} else if (strpos($normalized_key, 'token') !== false || in_array($normalized_key, array('merchant', 'merchantid', 'callbackurl', 'callback_url'), true)) {
				$sanitized[$safe_key] = '[redacted]';
			} else if ($normalized_key === 'body') {
				$sanitized[$safe_key] = is_scalar($value) || $value === null ? sanitize_text_field(substr((string) $value, 0, 2048)) : '';
			} else if (is_array($value)) {
				$sanitized[$safe_key] = self::sanitize_zibal_response_for_storage($value);
			} else {
				$sanitized[$safe_key] = is_scalar($value) || $value === null ? sanitize_text_field((string) $value) : '';
			}
		}

		return $sanitized;
	}

	private static function persist_zibal_card_number($entry_id, $card_number, $entry = null)
	{
		$card_number = sanitize_text_field((string) $card_number);
		if (empty($entry_id)) {
			return;
		}

		gform_update_meta($entry_id, 'zibal_card_number', $card_number);

		if (empty($entry) || !is_array($entry)) {
			$entry = GFPersian_Payments::get_entry($entry_id);
		}

		if (!is_wp_error($entry) && is_array($entry) && !empty($entry['post_id'])) {
			update_post_meta($entry['post_id'], '_zibal_card_number', $card_number);
		}
	}

	private static function update_entry_property_in_database($entry_id, $property_name, $value)
	{
		global $wpdb;

		if (empty($wpdb) || empty($entry_id)) {
			return;
		}

		$allowed_properties = array(
			'payment_status',
			'payment_amount',
			'payment_date',
			'payment_method',
			'transaction_id',
			'transaction_type',
			'is_fulfilled',
		);

		if (!in_array($property_name, $allowed_properties, true)) {
			return;
		}

		$table_name = GFPersian_DB_Zibal::get_entry_table_name();
		$format = in_array($property_name, array('payment_amount'), true) ? '%f' : (in_array($property_name, array('transaction_type', 'is_fulfilled'), true) ? '%d' : '%s');

		$wpdb->update(
			$table_name,
			array($property_name => $value),
			array('id' => absint($entry_id)),
			array($format),
			array('%d')
		);
	}

	private static function update_entry_property($entry_id, $property_name, $value, &$entry = null)
	{
		if (empty($entry_id) || empty($property_name)) {
			return;
		}

		$updated = false;
		if (method_exists('GFAPI', 'update_entry_property')) {
			$result = GFAPI::update_entry_property($entry_id, $property_name, $value);
			$updated = !is_wp_error($result) && $result !== false;
		} else {
			if (empty($entry) || !is_array($entry)) {
				$entry = GFPersian_Payments::get_entry($entry_id);
			}

			if (!is_wp_error($entry) && is_array($entry)) {
				$entry[$property_name] = $value;
				$result = GFAPI::update_entry($entry);
				$updated = !is_wp_error($result) && $result !== false;
			}
		}

		if (is_array($entry)) {
			$entry[$property_name] = $value;
		}

		if (!$updated) {
			self::update_entry_property_in_database($entry_id, $property_name, $value);
		}
	}

	private static function persist_payment_fields($entry_id, $fields, &$entry = null)
	{
		foreach ((array) $fields as $property_name => $value) {
			self::update_entry_property($entry_id, $property_name, $value, $entry);
		}
	}

	private static function persist_zibal_transaction_id($entry_id, $transaction_id, $entry = null)
	{
		$transaction_id = sanitize_text_field((string) $transaction_id);
		if (empty($entry_id) || $transaction_id === '') {
			return;
		}

		self::update_entry_property($entry_id, 'transaction_id', $transaction_id, $entry);

		if (empty($entry) || !is_array($entry)) {
			$entry = GFPersian_Payments::get_entry($entry_id);
		}

		if (!is_wp_error($entry) && is_array($entry)) {
			if (!empty($entry['post_id'])) {
				update_post_meta($entry['post_id'], '_zibal_transaction_id', $transaction_id);
				update_post_meta($entry['post_id'], '_zibal_track_id', $transaction_id);
			}
		}

		gform_update_meta($entry_id, 'transaction_id', $transaction_id);
		gform_update_meta($entry_id, 'zibal_transaction_id', $transaction_id);
		gform_update_meta($entry_id, 'zibal_track_id', $transaction_id);
	}

	private static function get_zibal_result_code($response)
	{
		$result_code = self::get_zibal_value($response, 'result');

		return is_scalar($result_code) || $result_code === null ? $result_code : 'invalid_response';
	}

	private static function get_zibal_error_message($response)
	{
		$result_code = self::get_zibal_result_code($response);
		$message     = self::Fault($result_code);

		if (!is_numeric($result_code)) {
			$response_message = self::get_zibal_value($response, 'message');
			if ((is_scalar($response_message) || $response_message === null) && $response_message !== '') {
				$message = sanitize_text_field((string) $response_message);
			}
		}

		return $message;
	}

	private static function store_zibal_response($entry_id, $context, $response, $extra_meta = array())
	{
		if (empty($entry_id)) {
			return;
		}

		$response = self::sanitize_zibal_response_for_storage(self::normalize_zibal_response($response));
		$json     = wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if (!empty($json)) {
			gform_update_meta($entry_id, 'zibal_' . sanitize_key($context) . '_response', $json);
			gform_update_meta($entry_id, 'zibal_last_response', $json);
		}

		$meta_map = array(
			'zibal_track_id'    => array('trackId', 'track_id'),
			'zibal_ref_number'  => array('refNumber', 'ref_number'),
			'zibal_card_number' => array('cardNumber', 'card_number', 'cardNo', 'card_no', 'pan', 'maskedCardNumber', 'masked_card_number'),
			'zibal_order_id'    => array('orderId', 'order_id'),
			'zibal_paid_at'     => array('paidAt', 'paid_at'),
			'zibal_status'      => array('status'),
			'zibal_result'      => array('result'),
			'zibal_message'     => array('message'),
		);

		$saved_meta = array();
		foreach ($meta_map as $meta_key => $keys) {
			$value = self::get_zibal_value($response, $keys);
			if ($value !== '' && (is_scalar($value) || $value === null)) {
				$value = sanitize_text_field((string) $value);
				gform_update_meta($entry_id, $meta_key, $value);
				$saved_meta[$meta_key] = $value;
			}
		}

		foreach ((array) $extra_meta as $meta_key => $value) {
			if ($value !== '' && $value !== null) {
				$meta_key = sanitize_key($meta_key);
				if ($meta_key === 'zibal_callback_url') {
					$value = esc_url_raw(remove_query_arg('zibal_token', (string) $value));
				}
				$value = sanitize_text_field((string) $value);
				gform_update_meta($entry_id, $meta_key, $value);
				$saved_meta[$meta_key] = $value;
			}
		}

		if (!empty($saved_meta)) {
			$entry = self::get_entry_for_payment($entry_id);
			if (!is_wp_error($entry) && is_array($entry) && !empty($entry['post_id'])) {
				foreach ($saved_meta as $meta_key => $value) {
					if (isset($meta_map[$meta_key]) && $meta_key !== 'zibal_message') {
						update_post_meta($entry['post_id'], '_' . $meta_key, $value);
					}
				}
			}
		}
	}

	private static function add_zibal_response_note($entry_id, $user_id, $user_name, $title, $response, $message = '')
	{
		if (empty($entry_id)) {
			return;
		}

		$response = self::sanitize_zibal_response_for_storage(self::normalize_zibal_response($response));
		$json     = wp_json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$note     = $title;

		if (!empty($message)) {
			$note .= "\n" . sprintf(__('پیام: %s', 'gravityformszibal'), $message);
		}

		if (!empty($json)) {
			$note .= "\n" . __('پاسخ کامل زیبال:', 'gravityformszibal') . "\n" . $json;
		}

		GFFormsModel::add_note($entry_id, $user_id, $user_name, $note);
	}

	private static function get_zibal_request_headers()
	{
		return array(
			'Content-Type'           => 'application/json',
			'User-Agent'             => 'GravityForms-Zibal/' . self::$version . '; WordPress',
			'X-Zibal-Plugin'         => 'gravityforms-zibal',
			'X-Zibal-Plugin-Version' => self::$version,
		);
	}

	private static function should_add_zibal_response_note($status)
	{
		return strtolower((string) $status) != 'completed';
	}

	// #2

	public static function settings_page()
	{
		if (!self::has_access()) {
			wp_die(esc_html__('شما مجوز کافی برای مشاهده تنظیمات زیبال را ندارید.', 'gravityformszibal'));
		}

		if (rgpost("uninstall")) {
			check_admin_referer("uninstall", "gf_zibal_uninstall");
			self::uninstall();
			echo '<div class="updated fade" style="padding:20px;">' . esc_html__("درگاه با موفقیت غیرفعال شد و اطلاعات مربوط به آن نیز از بین رفت برای فعالسازی مجدد میتوانید از طریق افزونه های وردپرس اقدام نمایید .", "gravityformszibal") . '</div>';

			return;
		} else if (isset($_POST["gf_zibal_submit"])) {

			check_admin_referer("update", "gf_zibal_update");
			$settings = array(
				"merchent" => rgpost('gf_zibal_merchent'),
				"gname"    => rgpost('gf_zibal_gname'),
				"direct"   => rgpost('gf_zibal_direct') === '1' ? '1' : '',
			);
			update_option("gf_zibal_settings", array_map('sanitize_text_field', $settings));
			if (isset($_POST["gf_zibal_configured"])) {
				update_option("gf_zibal_configured", sanitize_text_field(wp_unslash($_POST["gf_zibal_configured"])));
			} else {
				delete_option("gf_zibal_configured");
			}
		} else {
			$settings = get_option("gf_zibal_settings");
		}

		if (!empty($_POST)) {

			if (isset($_POST["gf_zibal_configured"]) && ($Response = self::Request('valid_checker', '', '', '')) && $Response != false) {

				if ($Response === true) {
					echo '<div class="updated fade" style="padding:6px">' . esc_html__("ارتباط با درگاه برقرار شد و اطلاعات وارد شده صحیح است .", "gravityformszibal") . '</div>';
				} else if ($Response == 'sandbox') {
					echo '<div class="updated fade" style="padding:6px">' . esc_html__("در حالت تستی نیاز به ورود اطلاعات صحیح نمی باشد .", "gravityformszibal") . '</div>';
				} else {
					echo '<div class="error fade" style="padding:6px">' . esc_html($Response) . '</div>';
				}
			} else {
				echo '<div class="updated fade" style="padding:6px">' . esc_html__("تنظیمات ذخیره شدند .", "gravityformszibal") . '</div>';
			}
		} else if (isset($_GET['subview']) && sanitize_key(wp_unslash($_GET['subview'])) === 'gf_zibal' && isset($_GET['updated'])) {
			echo '<div class="updated fade" style="padding:6px">' . esc_html__("تنظیمات ذخیره شدند .", "gravityformszibal") . '</div>';
		}
		?>

		<form action="" method="post">

			<?php wp_nonce_field("update", "gf_zibal_update") ?>

			<h3>
				<span>
					<i class="fa fa-credit-card"></i>
					<?php _e("تنظیمات زیبال", "gravityformszibal") ?>
				</span>
			</h3>

			<table class="form-table">

				<tr>
					<th scope="row"><label for="gf_zibal_configured"><?php _e("فعالسازی", "gravityformszibal"); ?></label>
					</th>
					<td>
						<input type="checkbox" name="gf_zibal_configured" id="gf_zibal_configured" <?php echo get_option("gf_zibal_configured") ? "checked='checked'" : "" ?> />
						<label class="inline" for="gf_zibal_configured"><?php _e("بله", "gravityformszibal"); ?></label>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="gf_zibal_merchent"><?php _e("کد مرچنت", "gravityformszibal"); ?></label></th>
					<td>
						<input style="width:350px;text-align:left;direction:ltr !important" type="text" id="gf_zibal_merchent" name="gf_zibal_merchent" value="<?php echo esc_attr(sanitize_text_field(rgar($settings, 'merchent'))) ?>" />
					</td>
				</tr>

				<?php

				$gateway_title = __("زیبال", "gravityformszibal");

				if (sanitize_text_field(rgar($settings, 'gname'))) {
					$gateway_title = sanitize_text_field($settings["gname"]);
				}

				?>
				<tr>
					<th scope="row">
						<label for="gf_zibal_gname">
							<?php _e("عنوان", "gravityformszibal"); ?>
							<?php gform_tooltip('gateway_name') ?>
						</label>
					</th>
					<td>
						<input style="width:350px;" type="text" id="gf_zibal_gname" name="gf_zibal_gname" value="<?php echo esc_attr($gateway_title); ?>" />
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="gf_zibal_direct"><?php esc_html_e("انتقال مستقیم به صفحه پرداخت", "gravityformszibal"); ?></label></th>
					<td>
						<input type="checkbox" name="gf_zibal_direct" id="gf_zibal_direct" value="1" <?php checked(rgar($settings, 'direct'), '1'); ?> />
						<span class="description"><?php esc_html_e("در صورت پشتیبانی زیبال، کاربر مستقیماً وارد صفحه پرداخت می‌شود.", "gravityformszibal"); ?></span>
					</td>
				</tr>

				<tr>
					<td colspan="2"><input style="font-family:tahoma !important;" type="submit" name="gf_zibal_submit" class="button-primary" value="<?php _e("ذخیره تنظیمات", "gravityformszibal") ?>" /></td>
				</tr>

			</table>

		</form>

		<form action="" method="post">
			<?php

			wp_nonce_field("uninstall", "gf_zibal_uninstall");

			if (self::has_access("gravityforms_zibal_uninstall")) {

			?>
				<div class="hr-divider"></div>
				<div class="delete-alert alert_red">

					<h3>
						<i class="fa fa-exclamation-triangle gf_invalid"></i>
						<?php _e("غیر فعالسازی افزونه دروازه پرداخت زیبال", "gravityformszibal"); ?>
					</h3>

					<div class="gf_delete_notice"><?php _e("تذکر : بعد از غیرفعالسازی تمامی اطلاعات مربوط به زیبال حذف خواهد شد", "gravityformszibal") ?></div>

					<?php
					$uninstall_button = '<input  style="font-family:tahoma !important;" type="submit" name="uninstall" value="' . __("غیر فعال سازی درگاه زیبال", "gravityformszibal") . '" class="button" onclick="return confirm(\'' . __("تذکر : بعد از غیرفعالسازی تمامی اطلاعات مربوط به زیبال حذف خواهد شد . آیا همچنان مایل به غیر فعالسازی میباشید؟", "gravityformszibal") . '\');"/>';
					echo apply_filters("gform_zibal_uninstall_button", $uninstall_button);
					?>

				</div>

			<?php } ?>
		</form>
	<?php
	}



	public static function get_gname()
	{
		$settings = get_option("gf_zibal_settings");
		if (isset($settings["gname"])) {
			$gname = $settings["gname"];
		} else {
			$gname = __('زیبال', 'gravityformszibal');
		}

		return $gname;
	}


	private static function get_merchent()
	{
		$settings = get_option("gf_zibal_settings");
		$merchent = isset($settings["merchent"]) ? $settings["merchent"] : '';

		return trim($merchent);
	}


	private static function normalize_mobile($mobile)
	{
		$original_mobile = sanitize_text_field((string) $mobile);
		$normalized_mobile = GFPersian_Payments::fix_mobile($original_mobile);

		return is_scalar($normalized_mobile) && (string) $normalized_mobile !== ''
			? sanitize_text_field((string) $normalized_mobile)
			: $original_mobile;
	}


	private static function convert_amount_to_rial($amount, $entry, $form)
	{
		return GFPersian_Payments::amount($amount, 'IRR', $entry, $form);
	}


	private static function get_direct()
	{
		$settings = get_option("gf_zibal_settings");
		$direct   = isset($settings["direct"]) ? $settings["direct"] : '';

		return $direct;
	}


	// #3

	private static function config_page()
	{
		if (!self::has_access()) {
			wp_die(esc_html__('شما مجوز کافی برای مدیریت فید زیبال را ندارید.', 'gravityformszibal'));
		}

		wp_register_style('gform_admin_zibal', GFCommon::get_base_url() . '/css/admin.css');
		wp_print_styles(array('jquery-ui-styles', 'gform_admin_zibal', 'wp-pointer')); ?>

		<?php if (is_rtl()) { ?>
			<style type="text/css">
				table.gforms_form_settings th {
					text-align: right !important;
				}
			</style>
		<?php } ?>

		<div class="wrap gforms_edit_form gf_browser_gecko">

			<?php
			$id        = !rgempty("zibal_setting_id") ? absint(rgpost("zibal_setting_id")) : absint(rgget("id"));
			$config    = empty($id) ? array(
				"meta"      => array(),
				"is_active" => true
			) : GFPersian_DB_Zibal::get_feed($id);
			if (!empty($id) && empty($config)) {
				$id = 0;
				$config = array(
					"meta"      => array(),
					"is_active" => true,
				);
			}
			$get_feeds = GFPersian_DB_Zibal::get_feeds();
			$form_name = '';


			$_get_form_id = rgget('fid') ? rgget('fid') : (!empty($config["form_id"]) ? $config["form_id"] : '');

			foreach ((array) $get_feeds as $get_feed) {
				if ($get_feed['id'] == $id) {
					$form_name = $get_feed['form_title'];
				}
			}
			?>


			<h2 class="gf_admin_page_title"><?php _e("پیکربندی درگاه زیبال", "gravityformszibal") ?>

				<?php if (!empty($_get_form_id)) { ?>
					<span class="gf_admin_page_subtitle">
						<span class="gf_admin_page_formid"><?php echo esc_html(sprintf(__("فید: %s", "gravityformszibal"), absint($id))) ?></span>
						<span class="gf_admin_page_formname"><?php echo esc_html(sprintf(__("فرم: %s", "gravityformszibal"), $form_name)) ?></span>
					</span>
				<?php } ?>

			</h2>
			<a class="button add-new-h2" href="admin.php?page=gf_settings&subview=gf_zibal" style="margin:8px 9px;"><?php _e("تنظیمات حساب زیبال", "gravityformszibal") ?></a>

			<?php
			if (!rgempty("gf_zibal_submit")) {
				// ------------------
				check_admin_referer("update", "gf_zibal_feed");

				$config["form_id"]                     = absint(rgpost("gf_zibal_form"));
				$config["meta"]["type"]                = rgpost("gf_zibal_type");
				$config["meta"]["addon"]               = rgpost("gf_zibal_addon");
				$config["meta"]["update_post_action1"] = rgpost('gf_zibal_update_action1');
				$config["meta"]["update_post_action2"] = rgpost('gf_zibal_update_action2');

				// ------------------
				$config["meta"]["zibal_conditional_enabled"]  = rgpost('gf_zibal_conditional_enabled');
				$config["meta"]["zibal_conditional_field_id"] = rgpost('gf_zibal_conditional_field_id');
				$config["meta"]["zibal_conditional_operator"] = rgpost('gf_zibal_conditional_operator');
				$config["meta"]["zibal_conditional_value"]    = rgpost('gf_zibal_conditional_value');
				$config["meta"]["zibal_conditional_type"]     = rgpost('gf_zibal_conditional_type');

				// ------------------
				$config["meta"]["desc_pm"]                = rgpost("gf_zibal_desc_pm");
				$config["meta"]["customer_fields_desc"]   = rgpost("zibal_customer_field_desc");
				$config["meta"]["customer_fields_email"]  = rgpost("zibal_customer_field_email");
				$config["meta"]["customer_fields_mobile"] = rgpost("zibal_customer_field_mobile");


				$safe_data = array();
				foreach ($config["meta"] as $key => $val) {
					if (!is_array($val)) {
						$safe_data[$key] = sanitize_text_field($val);
					} else {
						$safe_data[$key] = array();
						foreach ($val as $array_key => $array_value) {
							if (is_scalar($array_value) || $array_value === null) {
								$safe_data[$key][$array_key] = sanitize_text_field((string) $array_value);
							}
						}
					}
				}
				$safe_data['type'] = isset($safe_data['type']) && $safe_data['type'] === 'subscription' ? 'subscription' : '';
				$safe_data['addon'] = isset($safe_data['addon']) && $safe_data['addon'] === 'true' ? 'true' : '';
				$allowed_post_actions_1 = array('default', 'publish', 'draft', 'pending', 'private');
				$allowed_post_actions_2 = array('dont', 'default', 'publish', 'draft', 'pending', 'private');
				$safe_data['update_post_action1'] = in_array(rgar($safe_data, 'update_post_action1'), $allowed_post_actions_1, true) ? $safe_data['update_post_action1'] : 'default';
				$safe_data['update_post_action2'] = in_array(rgar($safe_data, 'update_post_action2'), $allowed_post_actions_2, true) ? $safe_data['update_post_action2'] : 'dont';
				$safe_data['zibal_conditional_enabled'] = !empty($safe_data['zibal_conditional_enabled']) ? '1' : '';
				$safe_data['zibal_conditional_type'] = rgar($safe_data, 'zibal_conditional_type') === 'all' ? 'all' : 'any';

				$condition_fields = isset($config['meta']['zibal_conditional_field_id']) && is_array($config['meta']['zibal_conditional_field_id']) ? $config['meta']['zibal_conditional_field_id'] : array();
				$condition_operators_post = isset($config['meta']['zibal_conditional_operator']) && is_array($config['meta']['zibal_conditional_operator']) ? $config['meta']['zibal_conditional_operator'] : array();
				$condition_values_post = isset($config['meta']['zibal_conditional_value']) && is_array($config['meta']['zibal_conditional_value']) ? $config['meta']['zibal_conditional_value'] : array();
				$safe_data['zibal_conditional_field_id'] = array();
				$safe_data['zibal_conditional_operator'] = array();
				$safe_data['zibal_conditional_value'] = array();
				$allowed_condition_operators = array('is', 'isnot', '>', '<', 'contains', 'starts_with', 'ends_with');
				foreach ($condition_fields as $condition_index => $condition_field) {
					$condition_index = absint($condition_index);
					if ($condition_index < 1) {
						continue;
					}
					$condition_operator = isset($condition_operators_post[$condition_index]) ? sanitize_text_field($condition_operators_post[$condition_index]) : 'is';
					$safe_data['zibal_conditional_field_id'][$condition_index] = sanitize_text_field($condition_field);
					$safe_data['zibal_conditional_operator'][$condition_index] = in_array($condition_operator, $allowed_condition_operators, true) ? $condition_operator : 'is';
					$safe_data['zibal_conditional_value'][$condition_index] = isset($condition_values_post[$condition_index]) ? sanitize_text_field($condition_values_post[$condition_index]) : '';
				}
				$config["meta"] = $safe_data;

				$config = apply_filters(self::$author . '_gform_gateway_save_config', $config);
				$config = apply_filters(self::$author . '_gform_zibal_save_config', $config);

				$is_active = isset($config["is_active"]) ? (int) (bool) $config["is_active"] : 1;
				$id = GFPersian_DB_Zibal::update_feed(absint($id), $config["form_id"], $is_active, $config["meta"]);
				if (!headers_sent()) {
					wp_safe_redirect(admin_url('admin.php?page=gf_zibal&view=edit&id=' . $id . '&updated=true'));
					exit;
				} else {
					$redirect_url = wp_json_encode(admin_url('admin.php?page=gf_zibal&view=edit&id=' . absint($id) . '&updated=true'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
					echo "<script type='text/javascript'>window.onload = function () { top.location.href = " . $redirect_url . "; };</script>";
					exit;
				}
			?>
				<div class="updated fade" style="padding:6px"><?php echo sprintf(__("فید به روز شد . %sبازگشت به لیست%s.", "gravityformszibal"), "<a href='?page=gf_zibal'>", "</a>") ?></div>
			<?php
			}

			$_get_form_id = rgget('fid') ? rgget('fid') : (!empty($config["form_id"]) ? $config["form_id"] : '');

			$form = array();
			if (!empty($_get_form_id)) {
				$form = GFFormsModel::get_form_meta($_get_form_id);
			}

			if (rgget('updated') == 'true') {

				$id = empty($id) && isset($_GET['id']) ? rgget('id') : $id;
				$id = absint($id); ?>

				<div class="updated fade" style="padding:6px"><?php echo sprintf(__("فید به روز شد . %sبازگشت به لیست%s . ", "gravityformszibal"), "<a href='?page=gf_zibal'>", "</a>") ?></div>

			<?php
			}


			if (!empty($_get_form_id)) { ?>

				<div id="gf_form_toolbar">
					<ul id="gf_form_toolbar_links">

						<?php
						$menu_items = apply_filters('gform_toolbar_menu', GFForms::get_toolbar_menu_items($_get_form_id), $_get_form_id);
						echo GFForms::format_toolbar_menu_items($menu_items); ?>

						<li class="gf_form_switcher">
							<label for="export_form"><?php _e('یک فید انتخاب کنید', 'gravityformszibal') ?></label>
							<?php
							$feeds = GFPersian_DB_Zibal::get_feeds();
							$current_view = (string) rgget('view');
							if ($current_view !== 'entry') { ?>
								<select name="form_switcher" id="form_switcher" onchange="GF_SwitchForm(jQuery(this).val());">
									<option value=""><?php _e('تغییر فید زیبال', 'gravityformszibal') ?></option>
									<?php foreach ($feeds as $feed) {
										$selected = $feed["id"] == $id ? "selected='selected'" : ""; ?>
										<option value="<?php echo absint($feed["id"]) ?>" <?php echo $selected ?>><?php echo esc_html(sprintf(__('فرم: %s (فید: %s)', 'gravityformszibal'), $feed["form_title"], $feed["id"])) ?></option>
									<?php } ?>
								</select>
							<?php
							}
							?>
						</li>
					</ul>
				</div>
			<?php } ?>

			<?php
			$condition_field_ids = array('1' => '');
			$condition_values    = array('1' => '');
			$condition_operators = array('1' => 'is');
			?>

			<div id="gform_tab_group" class="gform_tab_group vertical_tabs">
				<?php if (!empty($_get_form_id)) { ?>
					<ul id="gform_tabs" class="gform_tabs">
						<?php
						$title        = '';
						$get_form     = GFFormsModel::get_form_meta($_get_form_id);
						$current_tab  = rgempty('subview', $_GET) ? 'settings' : rgget('subview');
						$current_tab  = !empty($current_tab) ? $current_tab : ' ';
						$setting_tabs = GFFormSettings::get_tabs($get_form['id']);
						if (!$title) {
							foreach ($setting_tabs as $tab) {
								$query = array(
									'page'    => 'gf_edit_forms',
									'view'    => 'settings',
									'subview' => $tab['name'],
									'id'      => $get_form['id']
								);
								$url   = add_query_arg($query, admin_url('admin.php'));
								echo $tab['name'] == 'zibal' ? '<li class="active">' : '<li>';
						?>
								<a href="<?php echo esc_url($url); ?>"><?php echo esc_html($tab['label']) ?></a>
								<span></span>
								</li>
						<?php
							}
						}
						?>
					</ul>
				<?php }
				$has_product = false;
				if (isset($form["fields"])) {
					foreach ($form["fields"] as $field) {
						$shipping_field = GFAPI::get_fields_by_type($form, array('shipping'));
						if ($field["type"] == "product" || !empty($shipping_field)) {
							$has_product = true;
							break;
						}
					}
				} else if (empty($_get_form_id)) {
					$has_product = true;
				}
				?>
				<div id="gform_tab_container_<?php echo $_get_form_id ? absint($_get_form_id) : 1 ?>" class="gform_tab_container">
					<div class="gform_tab_content" id="tab_<?php echo !empty($current_tab) ? esc_attr($current_tab) : '' ?>">
						<div id="form_settings" class="gform_panel gform_panel_form_settings">
							<h3>
								<span>
									<i class="fa fa-credit-card"></i>
									<?php _e("پیکربندی درگاه زیبال", "gravityformszibal"); ?>
								</span>
							</h3>
							<form method="post" action="" id="gform_form_settings">

								<?php wp_nonce_field("update", "gf_zibal_feed") ?>


								<input type="hidden" name="zibal_setting_id" value="<?php echo absint($id) ?>" />
								<table class="form-table gforms_form_settings" cellspacing="0" cellpadding="0">
									<tbody>

										<tr style="<?php echo rgget('id') || rgget('fid') ? 'display:none !important' : ''; ?>">
											<th>
												<?php _e("انتخاب فرم", "gravityformszibal"); ?>
											</th>
											<td>
												<select id="gf_zibal_form" name="gf_zibal_form" onchange="GF_SwitchFid(jQuery(this).val());">
													<option value=""><?php _e("یک فرم انتخاب نمایید", "gravityformszibal"); ?> </option>
													<?php
													$available_forms = GFPersian_DB_Zibal::get_available_forms();
													foreach ($available_forms as $current_form) {
														$selected = absint($current_form->id) == $_get_form_id ? 'selected="selected"' : ''; ?>
														<option value="<?php echo absint($current_form->id) ?>" <?php echo $selected; ?>><?php echo esc_html($current_form->title) ?></option>
													<?php
													}
													?>
												</select>
												<img src="<?php echo esc_url(GFCommon::get_base_url()) ?>/images/spinner.gif" id="zibal_wait" style="display: none;" />
											</td>
										</tr>

									</tbody>
								</table>

								<?php if (empty($has_product) || !$has_product) { ?>
									<div id="gf_zibal_invalid_product_form" class="gf_zibal_invalid_form" style="background-color:#FFDFDF; margin-top:4px; margin-bottom:6px;padding:18px; border:1px dotted #C89797;">
										<?php _e("فرم انتخاب شده هیچ گونه فیلد قیمت گذاری ندارد، لطفا پس از افزودن این فیلدها مجددا اقدام نمایید.", "gravityformszibal") ?>
									</div>
								<?php } else { ?>
									<table class="form-table gforms_form_settings" id="zibal_field_group" <?php echo empty($_get_form_id) ? "style='display:none;'" : "" ?> cellspacing="0" cellpadding="0">
										<tbody>

											<tr>
												<th>
													<?php _e("فرم ثبت نام", "gravityformszibal"); ?>
												</th>
												<td>
													<input type="checkbox" name="gf_zibal_type" id="gf_zibal_type_subscription" value="subscription" <?php echo rgar($config['meta'], 'type') == "subscription" ? "checked='checked'" : "" ?> />
													<label for="gf_zibal_type"></label>
													<span class="description"><?php _e('در صورتی که تیک بزنید عملیات ثبت نام که توسط افزونه User Registration انجام خواهد شد تنها برای پرداخت های موفق عمل خواهد کرد'); ?></span>
												</td>
											</tr>

											<tr>
												<th>
													<?php _e("توضیحات پرداخت", "gravityformszibal"); ?>
												</th>
												<td>
													<input type="text" name="gf_zibal_desc_pm" id="gf_zibal_desc_pm" class="fieldwidth-1" value="<?php echo esc_attr(rgar($config["meta"], "desc_pm")) ?>" />
													<span class="description"><?php _e("شورت کد ها : {form_id} , {form_title} , {entry_id}", "gravityformszibal"); ?></span>
												</td>
											</tr>

											<tr>
												<th>
													<?php _e("توضیح تکمیلی", "gravityformszibal"); ?>
												</th>
												<td class="zibal_customer_fields_desc">
													<?php
													if (!empty($form)) {
														echo self::get_customer_information_desc($form, $config);
													}
													?>
												</td>
											</tr>

											<tr>
												<th>
													<?php _e("تلفن همراه پرداخت کننده", "gravityformszibal"); ?>
												</th>
												<td class="zibal_customer_fields_mobile">
													<?php
													if (!empty($form)) {
														echo self::get_customer_information_mobile($form, $config);
													}
													?>
												</td>
											</tr>

											<?php $display_post_fields = !empty($form) ? GFCommon::has_post_field($form["fields"]) : false; ?>

											<tr <?php echo $display_post_fields ? "" : "style='display:none;'" ?>>
												<th>
													<?php _e("نوشته بعد از پرداخت موفق", "gravityformszibal"); ?>
												</th>
												<td>
													<select id="gf_zibal_update_action1" name="gf_zibal_update_action1">
														<option value="default" <?php echo rgar($config["meta"], "update_post_action1") == "default" ? "selected='selected'" : "" ?>><?php _e("وضعیت پیشفرض فرم", "gravityformszibal") ?></option>
														<option value="publish" <?php echo rgar($config["meta"], "update_post_action1") == "publish" ? "selected='selected'" : "" ?>><?php _e("منتشر شده", "gravityformszibal") ?></option>
														<option value="draft" <?php echo rgar($config["meta"], "update_post_action1") == "draft" ? "selected='selected'" : "" ?>><?php _e("پیشنویس", "gravityformszibal") ?></option>
														<option value="pending" <?php echo rgar($config["meta"], "update_post_action1") == "pending" ? "selected='selected'" : "" ?>><?php _e("در انتظار بررسی", "gravityformszibal") ?></option>
														<option value="private" <?php echo rgar($config["meta"], "update_post_action1") == "private" ? "selected='selected'" : "" ?>><?php _e("خصوصی", "gravityformszibal") ?></option>
													</select>
												</td>
											</tr>

											<tr <?php echo $display_post_fields ? "" : "style='display:none;'" ?>>
												<th>
													<?php _e("نوشته قبل از پرداخت موفق", "gravityformszibal"); ?>
												</th>
												<td>
													<select id="gf_zibal_update_action2" name="gf_zibal_update_action2">
														<option value="dont" <?php echo rgar($config["meta"], "update_post_action2") == "dont" ? "selected='selected'" : "" ?>><?php _e("عدم ایجاد پست", "gravityformszibal") ?></option>
														<option value="default" <?php echo rgar($config["meta"], "update_post_action2") == "default" ? "selected='selected'" : "" ?>><?php _e("وضعیت پیشفرض فرم", "gravityformszibal") ?></option>
														<option value="publish" <?php echo rgar($config["meta"], "update_post_action2") == "publish" ? "selected='selected'" : "" ?>><?php _e("منتشر شده", "gravityformszibal") ?></option>
														<option value="draft" <?php echo rgar($config["meta"], "update_post_action2") == "draft" ? "selected='selected'" : "" ?>><?php _e("پیشنویس", "gravityformszibal") ?></option>
														<option value="pending" <?php echo rgar($config["meta"], "update_post_action2") == "pending" ? "selected='selected'" : "" ?>><?php _e("در انتظار بررسی", "gravityformszibal") ?></option>
														<option value="private" <?php echo rgar($config["meta"], "update_post_action2") == "private" ? "selected='selected'" : "" ?>><?php _e("خصوصی", "gravityformszibal") ?></option>
													</select>
												</td>
											</tr>

											<tr>
												<th>
													<?php echo __("سازگاری با افزودنی ها", "gravityformszibal"); ?>
												</th>
												<td>
													<input type="checkbox" name="gf_zibal_addon" id="gf_zibal_addon_true" value="true" <?php echo rgar($config['meta'], 'addon') == "true" ? "checked='checked'" : "" ?> />
													<label for="gf_zibal_addon"></label>
													<span class="description"><?php _e('برخی افزودنی های گرویتی فرم دارای متد add_delayed_payment_support هستند. در صورتی که میخواهید این افزودنی ها تنها در صورت تراکنش موفق عمل کنند این گزینه را تیک بزنید.', 'gravityformszibal'); ?></span>
												</td>
											</tr>

											<?php
											do_action(self::$author . '_gform_gateway_config', $config, $form);
											do_action(self::$author . '_gform_zibal_config', $config, $form);
											?>

											<tr id="gf_zibal_conditional_option">
												<th>
													<?php _e("منطق شرطی", "gravityformszibal"); ?>
												</th>
												<td>
													<input type="checkbox" id="gf_zibal_conditional_enabled" name="gf_zibal_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_zibal_conditional_container').fadeIn('fast');} else{ jQuery('#gf_zibal_conditional_container').fadeOut('fast'); }" <?php echo rgar($config['meta'], 'zibal_conditional_enabled') ? "checked='checked'" : "" ?> />
													<label for="gf_zibal_conditional_enabled"><?php _e("فعالسازی منطق شرطی", "gravityformszibal"); ?></label><br />
													<br>
													<table cellspacing="0" cellpadding="0">
														<tr>
															<td>
																<div id="gf_zibal_conditional_container" <?php echo !rgar($config['meta'], 'zibal_conditional_enabled') ? "style='display:none'" : "" ?>>

																	<span><?php _e("این درگاه را فعال کن اگر ", "gravityformszibal") ?></span>

																	<select name="gf_zibal_conditional_type">
																		<option value="all" <?php echo rgar($config['meta'], 'zibal_conditional_type') == 'all' ? "selected='selected'" : "" ?>><?php _e("همه", "gravityformszibal") ?></option>
																		<option value="any" <?php echo rgar($config['meta'], 'zibal_conditional_type') == 'any' ? "selected='selected'" : "" ?>><?php _e("حداقل یکی", "gravityformszibal") ?></option>
																	</select>
																	<span><?php _e("مطابق گزینه های زیر باشند:", "gravityformszibal") ?></span>

																	<?php
																	if (!empty($config["meta"]["zibal_conditional_field_id"])) {
																		$condition_field_ids = $config["meta"]["zibal_conditional_field_id"];
																		if (!is_array($condition_field_ids)) {
																			$condition_field_ids = array('1' => $condition_field_ids);
																		}
																	}

																	if (!empty($config["meta"]["zibal_conditional_value"])) {
																		$condition_values = $config["meta"]["zibal_conditional_value"];
																		if (!is_array($condition_values)) {
																			$condition_values = array('1' => $condition_values);
																		}
																	}

																	if (!empty($config["meta"]["zibal_conditional_operator"])) {
																		$condition_operators = $config["meta"]["zibal_conditional_operator"];
																		if (!is_array($condition_operators)) {
																			$condition_operators = array('1' => $condition_operators);
																		}
																	}

																	ksort($condition_field_ids);
																	foreach ($condition_field_ids as $i => $value) : ?>

																					<div class="gf_zibal_conditional_div" id="gf_zibal_<?php echo absint($i); ?>__conditional_div">

																						<select class="gf_zibal_conditional_field_id" id="gf_zibal_<?php echo absint($i); ?>__conditional_field_id" name="gf_zibal_conditional_field_id[<?php echo absint($i); ?>]" title="">
																			</select>

																						<select class="gf_zibal_conditional_operator" id="gf_zibal_<?php echo absint($i); ?>__conditional_operator" name="gf_zibal_conditional_operator[<?php echo absint($i); ?>]" style="font-family:tahoma,serif !important" title="">
																				<option value="is"><?php _e("هست", "gravityformszibal") ?></option>
																				<option value="isnot"><?php _e("نیست", "gravityformszibal") ?></option>
																				<option value=">"><?php _e("بیشتر یا بزرگتر از", "gravityformszibal") ?></option>
																				<option value="<"><?php _e("کمتر یا کوچکتر از", "gravityformszibal") ?></option>
																				<option value="contains"><?php _e("شامل میشود", "gravityformszibal") ?></option>
																				<option value="starts_with"><?php _e("شروع می شود با", "gravityformszibal") ?></option>
																				<option value="ends_with"><?php _e("تمام میشود با", "gravityformszibal") ?></option>
																			</select>

																						<div id="gf_zibal_<?php echo absint($i); ?>__conditional_value_container" style="display:inline;">
																			</div>

																			<a class="add_new_condition gficon_link" href="#">
																				<i class="gficon-add"></i>
																			</a>

																			<a class="delete_this_condition gficon_link" href="#">
																				<i class="gficon-subtract"></i>
																			</a>
																		</div>
																	<?php endforeach; ?>

																	<input type="hidden" value="<?php echo absint(key(array_slice($condition_field_ids, -1, 1, true))); ?>" id="gf_zibal_conditional_counter">

																	<div id="gf_no_conditional_message" style="display:none;background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding:18px; border:1px dotted #C89797;">
																		<?php _e("برای قرار دادن منطق شرطی، باید فیلدهای فرم شما هم قابلیت منطق شرطی را داشته باشند.", "gravityformszibal") ?>
																	</div>

																</div>
															</td>
														</tr>
													</table>
												</td>
											</tr>

											<tr>
												<td>
													<input type="submit" class="button-primary gfbutton" name="gf_zibal_submit" value="<?php _e("ذخیره", "gravityformszibal"); ?>" />
												</td>
											</tr>
										</tbody>
									</table>
								<?php } ?>
							</form>
						</div>
					</div>
				</div>
			</div>
		</div>

		<style type="text/css">
			.gforms_form_settings select {
				width: 180px !important;
			}

			.delete_this_condition,
			.add_new_condition {
				text-decoration: none !important;
				color: #000;
				outline: none !important;
			}

			#gf_zibal_conditional_container *,
			.delete_this_condition *,
			.add_new_condition * {
				outline: none !important;
			}

			.condition_field_value {
				width: 150px !important;
			}

			table.gforms_form_settings th {
				font-weight: 600;
				line-height: 1.3;
				font-size: 14px;
			}

			.gf_zibal_conditional_div {
				margin: 3px;
			}
		</style>
		<script type="text/javascript">
			function GF_SwitchFid(fid) {
				jQuery("#zibal_wait").show();
				document.location = "?page=gf_zibal&view=edit&fid=" + fid;
			}

			function GF_SwitchForm(id) {
				if (id.length > 0) {
					document.location = "?page=gf_zibal&view=edit&id=" + id;
				}
			}

			var form = [];
			form = <?php echo wp_json_encode(!empty($form) ? $form : array(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

			jQuery(document).ready(function($) {

				var delete_link, selectedField, selectedValue, selectedOperator;

				delete_link = $('.delete_this_condition');
				if (delete_link.length === 1)
					delete_link.hide();

				$(document.body).on('change', '.gf_zibal_conditional_field_id', function() {
					var id = $(this).attr('id');
					id = id.replace('gf_zibal_', '').replace('__conditional_field_id', '');
					var selectedOperator = $('#gf_zibal_' + id + '__conditional_operator').val();
					$('#gf_zibal_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_zibal_" + id + "__conditional", jQuery(this).val(), selectedOperator, "", 20, id));
				}).on('change', '.gf_zibal_conditional_operator', function() {
					var id = $(this).attr('id');
					id = id.replace('gf_zibal_', '').replace('__conditional_operator', '');
					var selectedOperator = $(this).val();
					var field_id = $('#gf_zibal_' + id + '__conditional_field_id').val();
					$('#gf_zibal_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_zibal_" + id + "__conditional", field_id, selectedOperator, "", 20, id));
				}).on('click', '.add_new_condition', function() {
					var parent_div = $(this).parent('.gf_zibal_conditional_div');
					var counter = $('#gf_zibal_conditional_counter');
					var new_id = parseInt(counter.val()) + 1;
					var content = parent_div[0].outerHTML
						.replace(new RegExp('gf_zibal_\\d+__', 'g'), ('gf_zibal_' + new_id + '__'))
						.replace(new RegExp('\\[\\d+\\]', 'g'), ('[' + new_id + ']'));
					counter.val(new_id);
					counter.before(content);
					//parent_div.after(content);
					RefreshConditionRow("gf_zibal_" + new_id + "__conditional", "", "is", "", new_id);
					$('.delete_this_condition').show();
					return false;
				}).on('click', '.delete_this_condition', function() {
					$(this).parent('.gf_zibal_conditional_div').remove();
					var delete_link = $('.delete_this_condition');
					if (delete_link.length === 1)
						delete_link.hide();
					return false;
				});

				<?php foreach ($condition_field_ids as $i => $field_id) : ?>
					selectedField = <?php echo wp_json_encode((string) $field_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
					selectedValue = <?php echo wp_json_encode((string) rgar($condition_values, $i), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
					selectedOperator = <?php echo wp_json_encode((string) rgar($condition_operators, $i, 'is'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
					RefreshConditionRow("gf_zibal_<?php echo absint($i); ?>__conditional", selectedField, selectedOperator, selectedValue, <?php echo absint($i); ?>);
				<?php endforeach; ?>
			});

			function RefreshConditionRow(input, selectedField, selectedOperator, selectedValue, index) {
				var field_id = jQuery("#" + input + "_field_id");
				field_id.html(GetSelectableFields(selectedField, 20));
				var optinConditionField = field_id.val();
				var checked = jQuery("#" + input + "_enabled").attr('checked');
				if (optinConditionField) {
					jQuery("#gf_no_conditional_message").hide();
					jQuery("#" + input + "_div").show();
					jQuery("#" + input + "_value_container").html(GetConditionalFieldValues("" + input + "", optinConditionField, selectedOperator, selectedValue, 20, index));
					jQuery("#" + input + "_value").val(selectedValue);
					jQuery("#" + input + "_operator").val(selectedOperator);
				} else {
					jQuery("#gf_no_conditional_message").show();
					jQuery("#" + input + "_div").hide();
				}
				if (!checked) jQuery("#" + input + "_container").hide();
			}

			/**
			 * @return {string}
			 */
			function GetConditionalFieldValues(input, fieldId, selectedOperator, selectedValue, labelMaxCharacters, index) {
				if (!fieldId)
					return "";
				var str = "";
				var name = (input.replace(new RegExp('_\\d+__', 'g'), '_')) + "_value[" + index + "]";
				var field = GetFieldById(fieldId);
				if (!field)
					return "";

				var is_text = false;

				if (selectedOperator == '' || selectedOperator == 'is' || selectedOperator == 'isnot') {
					if (field["type"] == "post_category" && field["displayAllCategories"]) {
						str += '<?php $dd = wp_dropdown_categories(array(
									"class"        => "condition_field_value",
									"orderby"      => "name",
									"id"           => "gf_dropdown_cat_id",
									"name"         => "gf_dropdown_cat_name",
									"hierarchical" => true,
									"hide_empty"   => 0,
									"echo"         => false
								));
								echo str_replace("\n", "", str_replace("'", "\\'", $dd)); ?>';
						str = str.replace("gf_dropdown_cat_id", "" + input + "_value").replace("gf_dropdown_cat_name", name);
					} else if (field.choices) {
						var isAnySelected = false;
						str += "<select class='condition_field_value' id='" + input + "_value' name='" + name + "'>";
						for (var i = 0; i < field.choices.length; i++) {
							var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
							var isSelected = fieldValue == selectedValue;
							var selected = isSelected ? "selected='selected'" : "";
							if (isSelected)
								isAnySelected = true;
							str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
						}
						if (!isAnySelected && selectedValue) {
							str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
						}
						str += "</select>";
					} else {
						is_text = true;
					}
				} else {
					is_text = true;
				}

				if (is_text) {
					selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
					str += "<input type='text' class='condition_field_value' style='padding:3px' placeholder='<?php _e("یک مقدار وارد نمایید", "gravityformszibal"); ?>' id='" + input + "_value' name='" + name + "' value='" + selectedValue + "'>";
				}
				return str;
			}

			/**
			 * @return {string}
			 */
			function GetSelectableFields(selectedFieldId, labelMaxCharacters) {
				var str = "";
				if (typeof form.fields !== "undefined") {
					var inputType;
					var fieldLabel;
					for (var i = 0; i < form.fields.length; i++) {
						fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
						inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
						if (IsConditionalLogicField(form.fields[i])) {
							var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
							str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
						}
					}
				}
				return str;
			}

			/**
			 * @return {string}
			 */
			function TruncateMiddle(text, maxCharacters) {
				if (!text)
					return "";
				if (text.length <= maxCharacters)
					return text;
				var middle = parseInt(maxCharacters / 2);
				return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
			}

			/**
			 * @return {object}
			 */
			function GetFieldById(fieldId) {
				for (var i = 0; i < form.fields.length; i++) {
					if (form.fields[i].id == fieldId)
						return form.fields[i];
				}
				return null;
			}

			/**
			 * @return {boolean}
			 */
			function IsConditionalLogicField(field) {
				var inputType = field.inputType ? field.inputType : field.type;
				var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
					"post_tags", "post_custom_field", "post_content", "post_excerpt"
				];
				var index = jQuery.inArray(inputType, supported_fields);
				return index >= 0;
			}
		</script>
<?php
	}

	// #4
	//Start Online Transaction
	public static function Request($confirmation, $form, $entry, $ajax)
	{

		do_action('gf_gateway_request_1', $confirmation, $form, $entry, $ajax);
		do_action('gf_zibal_request_1', $confirmation, $form, $entry, $ajax);

		if (apply_filters('gf_zibal_request_return', apply_filters('gf_gateway_request_return', false, $confirmation, $form, $entry, $ajax), $confirmation, $form, $entry, $ajax)) {
			return $confirmation;
		}

		$valid_checker = $confirmation == 'valid_checker';
		$custom        = $confirmation == 'custom';

		global $current_user;
		$user_id   = 0;
		$user_name = __('مهمان', 'gravityformszibal');

		if ($current_user && $user_data = get_userdata($current_user->ID)) {
			$user_id   = $current_user->ID;
			$user_name = $user_data->display_name;
		}

		if (!$valid_checker) {

			$entry_id = $entry['id'];

			if (!$custom) {

				if (GFForms::post("gform_submit") != $form['id']) {
					return $confirmation;
				}

				$config = self::get_active_config($form);
				if (empty($config)) {
					return $confirmation;
				}
				$config_meta = is_array(rgar($config, 'meta')) ? rgar($config, 'meta') : array();

				gform_update_meta($entry['id'], 'zibal_feed_id', $config['id']);
				gform_update_meta($entry['id'], 'payment_type', 'form');
				gform_update_meta($entry['id'], 'payment_gateway', self::get_gname());

				switch ((string) rgar($config_meta, 'type')) {
					case "subscription":
						$transaction_type = 2;
						break;

					default:
						$transaction_type = 1;
						break;
				}

				if (GFCommon::has_post_field($form["fields"])) {
					$update_post_action = (string) rgar($config_meta, 'update_post_action2');
					if ($update_post_action !== '') {
						if ($update_post_action != 'dont') {
							if ($update_post_action != 'default') {
								$form['postStatus'] = $update_post_action;
							}
						} else {
							$dont_create = true;
						}
					}
					if (empty($dont_create)) {
						GFFormsModel::create_post($form, $entry);
					}
				}

				$Amount = self::get_order_total($form, $entry);
				$Amount = apply_filters(self::$author . "_gform_form_gateway_price_{$form['id']}", apply_filters(self::$author . "_gform_form_gateway_price", $Amount, $form, $entry), $form, $entry);
				$Amount = apply_filters(self::$author . "_gform_form_zibal_price_{$form['id']}", apply_filters(self::$author . "_gform_form_zibal_price", $Amount, $form, $entry), $form, $entry);
				$Amount = apply_filters(self::$author . "_gform_gateway_price_{$form['id']}", apply_filters(self::$author . "_gform_gateway_price", $Amount, $form, $entry), $form, $entry);
				$Amount = apply_filters(self::$author . "_gform_zibal_price_{$form['id']}", apply_filters(self::$author . "_gform_zibal_price", $Amount, $form, $entry), $form, $entry);

				if (empty($Amount) || !$Amount || $Amount == 0) {
					unset($entry["payment_status"], $entry["payment_method"], $entry["is_fulfilled"], $entry["transaction_type"], $entry["payment_amount"], $entry["payment_date"]);
					$entry["payment_method"] = "zibal";
					GFAPI::update_entry($entry);
					gform_delete_meta($entry['id'], 'zibal_requested_amount');
					gform_delete_meta($entry['id'], 'zibal_track_id');
					gform_update_meta($entry['id'], 'zibal_payment_state', 'free_pending');

					return self::redirect_confirmation(add_query_arg(array('no' => 'true'), self::Return_URL($form['id'], $entry['id'])), $ajax);
				} else {

					$Desc1 = '';
					$description_template = (string) rgar($config_meta, 'desc_pm');
					if ($description_template !== '') {
						$Desc1 = str_replace(array('{entry_id}', '{form_title}', '{form_id}'), array(
							$entry['id'],
							$form['title'],
							$form['id']
						), $description_template);
					}
					$Desc2 = '';
					$description_field = (string) rgar($config_meta, 'customer_fields_desc');
					if ($description_field !== '' && rgpost('input_' . str_replace(".", "_", $description_field))) {
						$Desc2 = rgpost('input_' . str_replace(".", "_", $description_field));
					}

					if (!empty($Desc1) && !empty($Desc2)) {
						$Description = $Desc1 . ' - ' . $Desc2;
					} else if (!empty($Desc1) && empty($Desc2)) {
						$Description = $Desc1;
					} else if (!empty($Desc2) && empty($Desc1)) {
						$Description = $Desc2;
					} else {
						$Description = ' ';
					}
					$Description = sanitize_text_field($Description);

					$Email = '';
					$email_field = (string) rgar($config_meta, 'customer_fields_email');
					if ($email_field !== '' && rgpost('input_' . str_replace(".", "_", $email_field))) {
						$Email = sanitize_text_field(rgpost('input_' . str_replace(".", "_", $email_field)));
					}

					$Mobile = '';
					$mobile_field = (string) rgar($config_meta, 'customer_fields_mobile');
					if ($mobile_field !== '' && rgpost('input_' . str_replace(".", "_", $mobile_field))) {
						$Mobile = sanitize_text_field(rgpost('input_' . str_replace(".", "_", $mobile_field)));
					}
				}
			} else {

				$Amount = gform_get_meta(rgar($entry, 'id'), 'zibal_part_price_' . $form['id']);
				$Amount = apply_filters(self::$author . "_gform_custom_gateway_price_{$form['id']}", apply_filters(self::$author . "_gform_custom_gateway_price", $Amount, $form, $entry), $form, $entry);
				$Amount = apply_filters(self::$author . "_gform_custom_zibal_price_{$form['id']}", apply_filters(self::$author . "_gform_custom_zibal_price", $Amount, $form, $entry), $form, $entry);
				$Amount = apply_filters(self::$author . "_gform_gateway_price_{$form['id']}", apply_filters(self::$author . "_gform_gateway_price", $Amount, $form, $entry), $form, $entry);
				$Amount = apply_filters(self::$author . "_gform_zibal_price_{$form['id']}", apply_filters(self::$author . "_gform_zibal_price", $Amount, $form, $entry), $form, $entry);

				$Description = gform_get_meta(rgar($entry, 'id'), 'zibal_part_desc_' . $form['id']);
				$Description = apply_filters(self::$author . '_gform_zibal_gateway_desc_', apply_filters(self::$author . '_gform_custom_gateway_desc_', $Description, $form, $entry), $form, $entry);

				$Paymenter = gform_get_meta(rgar($entry, 'id'), 'zibal_part_name_' . $form['id']);
				$Email     = gform_get_meta(rgar($entry, 'id'), 'zibal_part_email_' . $form['id']);
				$Mobile    = gform_get_meta(rgar($entry, 'id'), 'zibal_part_mobile_' . $form['id']);

				$entry_id = GFAPI::add_entry($entry);
				$entry    = GFPersian_Payments::get_entry($entry_id);

				do_action('gf_gateway_request_add_entry', $confirmation, $form, $entry, $ajax);
				do_action('gf_zibal_request_add_entry', $confirmation, $form, $entry, $ajax);

				//-----------------------------------------------------------------
				gform_update_meta($entry_id, 'payment_gateway', self::get_gname());
				gform_update_meta($entry_id, 'payment_type', 'custom');
			}

			unset($entry["payment_status"]);
			unset($entry["payment_method"]);
			unset($entry["is_fulfilled"]);
			unset($entry["transaction_type"]);
			unset($entry["payment_amount"]);
			unset($entry["payment_date"]);
			unset($entry["transaction_id"]);

			$entry["payment_status"] = "Processing";
			$entry["payment_method"] = "zibal";
			$entry["is_fulfilled"]   = 0;
			if (!empty($transaction_type)) {
				$entry["transaction_type"] = $transaction_type;
			}
			GFAPI::update_entry($entry);
			$entry = GFPersian_Payments::get_entry($entry_id);


			$ReturnPath = self::Return_URL($form['id'], $entry_id);
			$ResNumber  = apply_filters('gf_zibal_res_number', apply_filters('gf_gateway_res_number', $entry_id, $entry, $form), $entry, $form);
		} else {

			$Amount      = 2000;
			$ReturnPath  = home_url('/');
			$Email       = '';
			$Mobile      = '';
			$ResNumber   = rand(1000, 9999);
			$Description = __('جهت بررسی صحیح بودن تنظیمات درگاه گرویتی فرم زیبال', 'gravityformszibal');
		}
		$Mobile = self::normalize_mobile($Mobile);

		do_action('gf_gateway_request_2', $confirmation, $form, $entry, $ajax);
		do_action('gf_zibal_request_2', $confirmation, $form, $entry, $ajax);

		if (!$custom) {
			$Amount = self::convert_amount_to_rial($Amount, $entry, $form);
		}

		if (!$valid_checker && !empty($entry_id)) {
			gform_update_meta($entry_id, 'zibal_requested_amount', (string) $Amount);
			gform_update_meta($entry_id, 'zibal_payment_state', 'requesting');
		}


		$direct = self::get_direct();
		$merchantId = self::get_merchent();
		$order_id = sanitize_text_field((string) $ResNumber);
		$order_id = substr($order_id, 0, 128);
		if (!$valid_checker && !empty($entry_id)) {
			gform_update_meta($entry_id, 'zibal_requested_order_id', $order_id);
		}

		if (!is_numeric($Amount) || (float) $Amount <= 0 || !is_finite((float) $Amount)) {
			$Result = array(
				'result'  => 'invalid_amount',
				'message' => __('مبلغ پرداخت معتبر نیست.', 'gravityformszibal'),
			);
		} else {
			$Result = self::sendRequestToZibal(
				'request',
				array(
					'merchant'  => $merchantId,
					'amount'      => $Amount,
					'description' => $Description,
					'mobile'      => $Mobile,
					'orderId'     => $order_id,
					'callbackUrl' => $ReturnPath
				)
			);
		}

		if (!$valid_checker && !empty($entry_id)) {
			self::store_zibal_response($entry_id, 'request', $Result, array(
				'zibal_callback_url' => $ReturnPath,
			));
		}

		$result_code = self::get_zibal_result_code($Result);

		$track_id = self::get_zibal_transaction_id($Result);

		if ($result_code == 100 && $track_id !== '') {
			if (!$valid_checker && !empty($entry_id)) {
				self::persist_zibal_transaction_id($entry_id, $track_id, $entry);
				gform_update_meta($entry_id, 'zibal_payment_state', 'pending');
			}

			$Payment_URL = 'https://gateway.zibal.ir/start/' . rawurlencode($track_id);
			if ($direct == '1') $Payment_URL .= '/direct';

			if ($valid_checker) {
				return true;
			} else {
				return self::redirect_confirmation($Payment_URL, $ajax);
			}
		} else {
			$Message = $result_code == 100 ? __('شماره تراکنش زیبال در پاسخ درخواست پرداخت وجود ندارد.', 'gravityformszibal') : self::get_zibal_error_message($Result);
		}


		$Message = !empty($Message) ? $Message : __('خطایی رخ داده است.', 'gravityformszibal');

		if ($valid_checker) {
			return $Message;
		}

		$entry = self::get_entry_for_payment($entry_id);
		$request_is_retryable = self::is_retryable_zibal_result($result_code);
		$entry['payment_status'] = $request_is_retryable ? 'Processing' : 'Failed';
		$entry['payment_amount'] = 0;
		$entry['payment_date'] = '';
		$entry['is_fulfilled'] = 0;
		GFAPI::update_entry($entry);
		self::persist_payment_fields($entry_id, array(
			'payment_status' => $entry['payment_status'],
			'payment_amount' => $entry['payment_amount'],
			'payment_date'   => $entry['payment_date'],
			'is_fulfilled'   => $entry['is_fulfilled'],
		), $entry);
		gform_update_meta($entry_id, 'zibal_payment_state', $request_is_retryable ? 'pending_review' : 'failed');
		gform_update_meta($entry_id, 'zibal_payment_result', 'failure');

		GFFormsModel::add_note($entry_id, $user_id, $user_name, sprintf(__('خطا در اتصال به درگاه رخ داده است : %s', "gravityformszibal"), $Message));
		self::add_zibal_response_note($entry_id, $user_id, $user_name, __('جزئیات کامل پاسخ زیبال هنگام اتصال به درگاه', 'gravityformszibal'), $Result, $Message);

		if (!$custom) {
			GFPersian_Payments::notification($form, $entry);
		}

		return self::get_payment_confirmation_return($form, $entry, 'failed', $ajax);
	}


	// #5
	public static function Verify()
	{

		if (apply_filters('gf_gateway_zibal_return', apply_filters('gf_gateway_verify_return', false))) {
			return;
		}

		if (!self::is_gravityforms_supported()) {
			return;
		}

		if (empty($_GET['id']) || empty($_GET['entry']) || !is_numeric(rgget('id')) || !is_numeric(rgget('entry'))) {
			return;
		}

		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}
		if (function_exists('nocache_headers') && !headers_sent()) {
			nocache_headers();
		}

		$form_id  = absint(rgget('id'));
		$entry_id = absint(rgget('entry'));

		$entry = self::get_entry_for_payment($entry_id);

		if (is_wp_error($entry)) {
			return;
		}

		if (absint(rgar($entry, 'form_id')) !== $form_id) {
			return;
		}

		$stored_payment_token = (string) gform_get_meta($entry_id, 'zibal_payment_token');
		$callback_payment_token = isset($_GET['zibal_token']) ? substr(sanitize_text_field(wp_unslash($_GET['zibal_token'])), 0, 128) : '';
		if ($stored_payment_token === '' || $callback_payment_token === '' || !self::secure_equals($stored_payment_token, $callback_payment_token)) {
			return;
		}
		$callback_is_free = isset($_GET['no']) && sanitize_key(wp_unslash($_GET['no'])) === 'true';
		$callback_success = isset($_GET['success']) ? sanitize_text_field(wp_unslash($_GET['success'])) : '';
		$callback_success = substr($callback_success, 0, 16);
		$callback_track_id = isset($_GET['trackId']) ? substr(sanitize_text_field(wp_unslash($_GET['trackId'])), 0, 128) : '';

		if (isset($entry["payment_method"]) && $entry["payment_method"] == 'zibal') {

			$form = GFFormsModel::get_form_meta($form_id);

			$payment_type = gform_get_meta($entry["id"], 'payment_type');

			if ($payment_type != 'custom') {
				$config = self::get_config_by_entry($entry);
				if (empty($config)) {
					return;
				}
			} else {
				$config = apply_filters(self::$author . '_gf_zibal_config', apply_filters(self::$author . '_gf_gateway_config', array(), $form, $entry), $form, $entry);
			}


			if (in_array(rgar($entry, 'payment_status'), array('Paid', 'Active'), true)) {
				$stored_track_id = substr(sanitize_text_field((string) gform_get_meta($entry['id'], 'zibal_track_id')), 0, 128);
				$is_completed_free_payment = $callback_is_free && (float) rgar($entry, 'payment_amount') === 0.0 && (string) gform_get_meta($entry['id'], 'zibal_payment_state') === 'completed';
				$is_matching_paid_callback = !$callback_is_free && $callback_success === '1' && $stored_track_id !== '' && $callback_track_id !== '' && self::secure_equals($stored_track_id, $callback_track_id);
				if ($is_completed_free_payment || $is_matching_paid_callback) {
					self::display_payment_confirmation($form, $entry, 'completed');
				}

				return;
			}

			global $current_user;
			$user_id   = 0;
			$user_name = __("مهمان", "gravityformszibal");
			if ($current_user && $user_data = get_userdata($current_user->ID)) {
				$user_id   = $current_user->ID;
				$user_name = $user_data->display_name;
			}

			$transaction_type = 1;
			if (!empty($config["meta"]["type"]) && $config["meta"]["type"] == 'subscription') {
				$transaction_type = 2;
			}

			if ($payment_type == 'custom') {
				$Amount = $Total = gform_get_meta($entry["id"], 'zibal_part_price_' . $form_id);
			} else {
				$Amount = $Total = self::get_order_total($form, $entry);
			}
			$Total_Money = GFCommon::to_money($Total, $entry["currency"]);

			$verification_lock_acquired = self::acquire_verification_lock($entry['id']);
			if (!$verification_lock_acquired) {
				$locked_entry = self::get_entry_for_payment($entry['id']);
				if (!is_wp_error($locked_entry) && in_array(rgar($locked_entry, 'payment_status'), array('Paid', 'Active'), true)) {
					self::display_payment_confirmation($form, $locked_entry, 'completed');
				}

				return;
			}

			$free = false;
			$Result = array();
			$Transaction_ID = '';
			$Message = '';
			$Status = 'failed';
			$__params = '';
			if (!$callback_is_free) {

				//Start of Zibal
				if ($payment_type != 'custom') {
					$Amount = self::convert_amount_to_rial($Amount, $entry, $form);
				}


				if ($callback_success === '1') {

					$trackId  = $callback_track_id;
					$callback_track_id = $trackId;
					$merchantId = self::get_merchent();
					$stored_track_id = sanitize_text_field((string) gform_get_meta($entry['id'], 'zibal_track_id'));
					$stored_amount = gform_get_meta($entry['id'], 'zibal_requested_amount');

					if ($trackId === '' || $stored_track_id === '' || !self::secure_equals($stored_track_id, $trackId)) {
						$Message = __('اطلاعات بازگشت پرداخت با تراکنش ثبت‌شده مطابقت ندارد.', 'gravityformszibal');
						$Status = 'processing';
					} else if ($stored_amount === '' || !is_numeric($stored_amount) || (float) $stored_amount !== (float) $Amount) {
						$Message = __('مبلغ پرداخت با مبلغ ثبت‌شده برای این ورودی مطابقت ندارد.', 'gravityformszibal');
						$Status = 'processing';
					} else {
						$__params = $Amount . $trackId;
						if (GFPersian_Payments::check_verification($entry, __CLASS__, $__params)) {
							$Result = array(
								'result'  => 'local_already_verified',
								'message' => __('این تراکنش قبلاً در سایت ثبت تأیید شده ولی وضعیت محلی آن کامل نیست.', 'gravityformszibal'),
							);
						} else {
							$Result = self::sendRequestToZibal(
								'verify',
								array(
									'merchant' => $merchantId,
									'trackId'  => $trackId
								)
							);
						}

						self::store_zibal_response($entry["id"], 'verify', $Result, array(
							'zibal_callback_track_id' => $trackId,
							'zibal_callback_success'  => $callback_success,
						));
						self::persist_zibal_card_number($entry["id"], self::get_zibal_card_number($Result), $entry);

						$result_code = self::get_zibal_result_code($Result);
						$verified_amount = self::get_zibal_value($Result, array('amount', 'paidAmount', 'paid_amount'));
						$verified_order_id = self::get_zibal_value($Result, array('orderId', 'order_id'));
						$stored_order_id = sanitize_text_field((string) gform_get_meta($entry['id'], 'zibal_requested_order_id'));
						$order_id_matches = $verified_order_id === '' || (
							$stored_order_id !== '' &&
							is_scalar($verified_order_id) &&
							self::secure_equals($stored_order_id, sanitize_text_field((string) $verified_order_id))
						);

						if ($result_code == 100 && !$order_id_matches) {
							$Message = __('شماره سفارش تأییدشده توسط زیبال با پرداخت ثبت‌شده مطابقت ندارد.', 'gravityformszibal');
							$Status  = 'processing';
						} else if ($result_code == 100 && is_numeric($verified_amount) && (float) $verified_amount === (float) $stored_amount) {
							$Message = '';
							$Status  = 'completed';
						} else if ($result_code == 201 || $result_code === 'local_already_verified') {
							$Message = __('تراکنش قبلاً در زیبال تأیید شده ولی در این ورودی پرداخت‌شده ثبت نشده است؛ نیاز به بررسی مدیر دارد.', 'gravityformszibal');
							$Status  = 'processing';
						} else if ($result_code == 100 && $verified_amount === '') {
							$Message = __('پاسخ تأیید زیبال فاقد مبلغ پرداخت است؛ تراکنش برای بررسی مدیر در حالت پردازش باقی ماند.', 'gravityformszibal');
							$Status  = 'processing';
						} else if ($result_code == 100) {
							$Message = __('مبلغ تأییدشده توسط زیبال با مبلغ ثبت‌شده مطابقت ندارد.', 'gravityformszibal');
							$Status  = 'processing';
						} else if (self::is_retryable_zibal_result($result_code)) {
							$Message = __('ارتباط با زیبال موقتاً کامل نشد. وضعیت پرداخت برای بررسی مجدد در حالت پردازش باقی ماند.', 'gravityformszibal');
							$Status  = 'processing';
						} else {
							$Message = self::get_zibal_error_message($Result);
							$Status  = 'failed';
						}
					}
				} else {
					$stored_track_id = sanitize_text_field((string) gform_get_meta($entry['id'], 'zibal_track_id'));
					if ($stored_track_id === '' || $callback_track_id === '' || !self::secure_equals($stored_track_id, $callback_track_id)) {
						$Message = __('اطلاعات بازگشت پرداخت با تراکنش ثبت‌شده مطابقت ندارد.', 'gravityformszibal');
						$Status = 'processing';
					} else {
						$Message = '';
						$Status = 'cancelled';
					}
				}
				$local_track_id = sanitize_text_field((string) gform_get_meta($entry['id'], 'zibal_track_id'));
				$Transaction_ID = $local_track_id !== '' ? $local_track_id : (!empty($Result) ? self::get_zibal_transaction_id($Result, $callback_track_id) : $callback_track_id);
				//End of Zibal
			} else {
				$stored_payment_state = (string) gform_get_meta($entry['id'], 'zibal_payment_state');
				$stored_requested_amount = gform_get_meta($entry['id'], 'zibal_requested_amount');
				$stored_track_id = (string) gform_get_meta($entry['id'], 'zibal_track_id');
				$is_zero_amount = is_numeric($Amount) && (float) $Amount === 0.0;

				$has_requested_amount = $stored_requested_amount !== '' && $stored_requested_amount !== null && $stored_requested_amount !== false;
				if ($stored_payment_state !== 'free_pending' || $has_requested_amount || $stored_track_id !== '' || !$is_zero_amount) {
					$Message = __('درخواست پرداخت رایگان معتبر نیست.', 'gravityformszibal');
					GFFormsModel::add_note($entry['id'], $user_id, $user_name, $Message);
					self::release_verification_lock($entry['id']);
					$verification_lock_acquired = false;
					self::display_payment_confirmation($form, $entry, 'failed', $Message);

					return;
				} else {
					$Status = 'completed';
					$Message = '';
					$Transaction_ID = apply_filters(self::$author . '_gf_rand_transaction_id', GFPersian_Payments::transaction_id($entry), $form, $entry);
					$free = true;
				}
			}

			$Status         = !empty($Status) ? $Status : 'failed';
			$transaction_id = !empty($Transaction_ID) ? $Transaction_ID : '';
			$transaction_id = apply_filters(self::$author . '_gf_real_transaction_id', $transaction_id, $Status, $form, $entry);
			$transaction_id = sanitize_text_field((string) $transaction_id);

			//----------------------------------------------------------------------------------------
			$entry["transaction_id"]   = $transaction_id;
			$entry["transaction_type"] = $transaction_type;

				if ($Status == 'completed') {

					$entry["payment_date"]     = gmdate("Y-m-d H:i:s");
					$entry["is_fulfilled"]   = 1;
					$entry["payment_amount"] = $Total;

				if ($transaction_type == 2) {
					$entry["payment_status"] = "Active";
					GFFormsModel::add_note($entry["id"], $user_id, $user_name, __("تغییرات اطلاعات فیلدها فقط در همین پیام ورودی اعمال خواهد شد و بر روی وضعیت کاربر تاثیری نخواهد داشت .", "gravityformszibal"));
				} else {
					$entry["payment_status"] = "Paid";
				}

				if ($free == true) {
					$entry["payment_amount"] = 0;
					$entry["payment_method"] = "zibal";
					$entry["is_fulfilled"] = 1;
					gform_delete_meta($entry['id'], 'payment_gateway');
					$Note = sprintf(__('وضعیت پرداخت : رایگان - بدون نیاز به درگاه پرداخت', "gravityformszibal"));
				} else {
					$card_number = self::get_zibal_card_number($Result);
					if (!empty($card_number)) {
						$Note = sprintf(__('وضعیت پرداخت : موفق - مبلغ پرداختی : %s - کد تراکنش : %s - شماره کارت : %s', "gravityformszibal"), $Total_Money, $transaction_id, $card_number);
					} else {
						$Note = sprintf(__('وضعیت پرداخت : موفق - مبلغ پرداختی : %s - کد تراکنش : %s', "gravityformszibal"), $Total_Money, $transaction_id);
					}
					}

					GFAPI::update_entry($entry);
					$completed_payment_fields = array(
						'payment_status'   => $entry["payment_status"],
						'payment_amount'   => $entry["payment_amount"],
						'payment_date'     => $entry["payment_date"],
						'transaction_id'   => $entry["transaction_id"],
						'transaction_type' => $entry["transaction_type"],
						'is_fulfilled'     => $entry["is_fulfilled"],
					);
					self::persist_payment_fields($entry["id"], $completed_payment_fields, $entry);
					gform_update_meta($entry['id'], 'zibal_payment_state', 'completed');


					if (apply_filters(self::$author . '_gf_zibal_post', apply_filters(self::$author . '_gf_gateway_post', ($payment_type != 'custom'), $form, $entry), $form, $entry)) {

					$has_post = GFCommon::has_post_field($form["fields"]) ? true : false;

					if (!empty($config["meta"]["update_post_action1"]) && $config["meta"]["update_post_action1"] != 'default') {
						$new_status = $config["meta"]["update_post_action1"];
					} else {
						$new_status = rgar($form, 'postStatus');
					}

					if (empty($entry["post_id"]) && $has_post) {
						$form['postStatus'] = $new_status;
						GFFormsModel::create_post($form, $entry);
						$entry = self::get_entry_for_payment($entry_id);
					}

					if (!empty($entry["post_id"]) && $has_post) {
						$post = get_post($entry["post_id"]);
						if (is_object($post)) {
							if ($new_status != $post->post_status) {
								$post->post_status = $new_status;
								wp_update_post($post);
							}
						}
						}
					}

					self::persist_payment_fields($entry["id"], $completed_payment_fields, $entry);

					if (!empty($__params)) {
						GFPersian_Payments::set_verification($entry, __CLASS__, $__params);
				}

				$user_registration_slug = apply_filters('gf_user_registration_slug', 'gravityformsuserregistration');
				$paypal_config          = array('meta' => array());
				if (!empty($config["meta"]["addon"]) && $config["meta"]["addon"] == 'true') {
					if (class_exists('GFAddon') && method_exists('GFAddon', 'get_registered_addons')) {
						$addons = GFAddon::get_registered_addons();
						foreach ((array) $addons as $addon) {
							if (is_callable(array($addon, 'get_instance'))) {
								$addon = call_user_func(array($addon, 'get_instance'));
								if (is_object($addon) && method_exists($addon, 'get_slug')) {
									$slug = $addon->get_slug();
									if ($slug != $user_registration_slug) {
										$paypal_config['meta']['delay_' . $slug] = true;
									}
								}
							}
						}
					}
				}
				if (!empty($config["meta"]["type"]) && $config["meta"]["type"] == "subscription") {
					$paypal_config['meta']['delay_' . $user_registration_slug] = true;
				}

				do_action("gform_zibal_fulfillment", $entry, $config, $transaction_id, $Total);
				do_action("gform_gateway_fulfillment", $entry, $config, $transaction_id, $Total);
				do_action("gform_paypal_fulfillment", $entry, $paypal_config, $transaction_id, $Total);
				} else if ($Status == 'processing') {
					$entry["payment_status"] = "Processing";
					$entry["payment_amount"] = 0;
					$entry["payment_date"] = '';
					$entry["is_fulfilled"] = 0;
					GFAPI::update_entry($entry);
					self::persist_payment_fields($entry["id"], array(
						'payment_status' => $entry["payment_status"],
						'payment_amount' => $entry["payment_amount"],
						'payment_date'   => $entry["payment_date"],
						'is_fulfilled'   => $entry["is_fulfilled"],
					), $entry);
					gform_update_meta($entry['id'], 'zibal_payment_state', 'pending_review');

					$Note = sprintf(__('وضعیت پرداخت : نیازمند بررسی - مبلغ قابل پرداخت : %s - کد تراکنش : %s - توضیح : %s', "gravityformszibal"), $Total_Money, $transaction_id, $Message);
				} else if ($Status == 'cancelled') {
					$entry["payment_status"] = "Cancelled";
					$entry["payment_amount"] = 0;
					$entry["payment_date"] = '';
					$entry["is_fulfilled"]   = 0;
					GFAPI::update_entry($entry);
					self::persist_payment_fields($entry["id"], array(
						'payment_status' => $entry["payment_status"],
						'payment_amount' => $entry["payment_amount"],
						'payment_date'   => $entry["payment_date"],
						'is_fulfilled'   => $entry["is_fulfilled"],
					), $entry);
					gform_update_meta($entry['id'], 'zibal_payment_state', 'cancelled');

					$Note = sprintf(__('وضعیت پرداخت : منصرف شده - مبلغ قابل پرداخت : %s - کد تراکنش : %s', "gravityformszibal"), $Total_Money, $transaction_id);
				} else {
					$entry["payment_status"] = "Failed";
					$entry["payment_amount"] = 0;
					$entry["payment_date"] = '';
					$entry["is_fulfilled"]   = 0;
					GFAPI::update_entry($entry);
					self::persist_payment_fields($entry["id"], array(
						'payment_status' => $entry["payment_status"],
						'payment_amount' => $entry["payment_amount"],
						'payment_date'   => $entry["payment_date"],
						'is_fulfilled'   => $entry["is_fulfilled"],
					), $entry);
					gform_update_meta($entry['id'], 'zibal_payment_state', 'failed');

					$Note = sprintf(__('وضعیت پرداخت : ناموفق - مبلغ قابل پرداخت : %s - کد تراکنش : %s - علت خطا : %s', "gravityformszibal"), $Total_Money, $transaction_id, $Message);
				}

			self::persist_zibal_transaction_id($entry["id"], $transaction_id, $entry);
			GFFormsModel::add_note($entry["id"], $user_id, $user_name, $Note);
			if (!empty($Result) && self::should_add_zibal_response_note($Status)) {
				self::add_zibal_response_note($entry["id"], $user_id, $user_name, __('جزئیات کامل پاسخ زیبال پس از بازگشت کاربر', 'gravityformszibal'), $Result, $Message);
			} elseif ($Status == 'cancelled') {
				$callback_response = array(
					'result'  => 'cancelled',
					'message' => __('کاربر از پرداخت منصرف شد یا پرداخت ناموفق به سایت برگشت.', 'gravityformszibal'),
					'trackId' => $callback_track_id,
					'success' => $callback_success,
				);
				self::store_zibal_response($entry["id"], 'callback', $callback_response);
				self::add_zibal_response_note($entry["id"], $user_id, $user_name, __('جزئیات بازگشت ناموفق/انصرافی زیبال', 'gravityformszibal'), $callback_response, $Message);
			}
			do_action('gform_post_payment_status', $config, $entry, strtolower($Status), $transaction_id, '', $Total, '', '');
			do_action('gform_post_payment_status_' . __CLASS__, $config, $form, $entry, strtolower($Status), $transaction_id, '', $Total, '', '');

			if ($verification_lock_acquired) {
				self::release_verification_lock($entry['id']);
				$verification_lock_acquired = false;
			}

			if (apply_filters(self::$author . '_gf_zibal_verify', apply_filters(self::$author . '_gf_gateway_verify', ($payment_type != 'custom'), $form, $entry), $form, $entry)) {
				GFPersian_Payments::notification($form, $entry);
				self::display_payment_confirmation($form, $entry, $Status, $Message);
			}
		}
	}

	public static function sendRequestToZibal($action, $params)
	{
		$action = sanitize_key((string) $action);
		if (!in_array($action, array('request', 'verify'), true)) {
			return array(
				'result'  => 'invalid_action',
				'message' => __('عملیات درخواستی زیبال معتبر نیست.', 'gravityformszibal'),
			);
		}

		try {

			$number_of_connection_tries = 3;
			$response = null;
			$error_message = '';
			while ($number_of_connection_tries > 0) {
				$response = wp_safe_remote_post('https://gateway.zibal.ir/v1/' . $action, array(
					'body' => wp_json_encode($params),
					'headers' => self::get_zibal_request_headers(),
					'timeout' => 15,
				));
				if (is_wp_error($response)) {
					$error_message = $response->get_error_message();
					$number_of_connection_tries--;
					continue;
				} else {
					break;
				}
			}

			if (is_wp_error($response)) {
				return array(
					'result'  => 'wp_error',
					'message' => $error_message,
				);
			}

			$http_status = absint(wp_remote_retrieve_response_code($response));
			$body = wp_remote_retrieve_body($response);
			if ($http_status < 200 || $http_status >= 300) {
				return array(
					'result'      => 'http_error',
					'http_status' => $http_status,
					'message'     => sprintf(__('زیبال پاسخ HTTP نامعتبر %d برگرداند.', 'gravityformszibal'), $http_status),
				);
			}

			$decoded = json_decode($body, true);

			if (!is_array($decoded)) {
				return array(
					'result'  => 'invalid_json',
					'message' => __('پاسخ دریافتی از زیبال قابل خواندن نیست.', 'gravityformszibal'),
					'body'    => $body,
				);
			}

			return $decoded;
		} catch (Exception $ex) {
			return array(
				'result'  => 'exception',
				'message' => $ex->getMessage(),
			);
		} catch (Throwable $ex) {
			return array(
				'result'  => 'exception',
				'message' => $ex->getMessage(),
			);
		}
	}

	// #6
	private static function Fault($err_code)
	{
		$message = $err_code;
		switch ($err_code) {

			case '100':
				$message = __('اتصال با زیبال به خوبی برقرار شد و همه چیز صحیح است .', 'gravityformszibal');
				break;

			case '101':
				$message = __('تراکنش با موفقیت به پایان رسیده بود و تاییدیه آن نیز انجام شده بود .', 'gravityformszibal');
				break;

			case '102':
				$message = __('merchant یافت نشد.', 'gravityformszibal');
				break;

			case '103':
				$message = __('merchant غیرفعال', 'gravityformszibal');
				break;

			case '104':
				$message = __('merchant نامعتبر', 'gravityformszibal');
				break;

			case '201':
				$message = __('قبلا تایید شده.', 'gravityformszibal');
				break;

			case '105':
				$message = __('amount بایستی بزرگتر از 1,000 ریال باشد.', 'gravityformszibal');
				break;

			case '106':
				$message = __('callbackUrl نامعتبر می‌باشد. (شروع با http و یا https)', 'gravityformszibal');
				break;

			case '113':
				$message = __('amount مبلغ تراکنش از سقف میزان تراکنش بیشتر است.', 'gravityformszibal');
				break;
		}

		return $message;
	}
}
