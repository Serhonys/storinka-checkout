<?php
class ControllerExtensionModuleStorinka extends Controller {
	private $error = array();

	public function index() {
		$loaded = $this->load->language('extension/module/storinka');
		$data = is_array($loaded) ? $loaded : array();
		if (!$data) {
			foreach (array('heading_title','text_extension','text_success','text_edit','text_enabled','text_disabled','text_home','tab_general','tab_fields','tab_rules','tab_np','tab_look','entry_status','entry_replace','entry_guest','entry_register','entry_login','entry_coupon','entry_comment','entry_cart_edit','entry_image','entry_autoselect','entry_standard','entry_phone_mask','entry_auto_pay','entry_hide_columns','entry_no_address','entry_rules','entry_np_status','entry_np_key','entry_np_codes','entry_np_courier','entry_accent','entry_radius','entry_heading','entry_button','entry_css','help_rules','help_no_address','help_np','column_field','column_status','column_required','column_width','column_sort','button_save','button_cancel','error_permission') as $language_key) {
				$data[$language_key] = $this->language->get($language_key);
			}
		}
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_storinka', $this->request->post);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('extension/module/storinka', $this->token(), true));
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$extension = version_compare(VERSION, '3.0.2.0', '>=') ? 'marketplace/extension' : 'extension/extension';
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $this->token(), true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link($extension, $this->token() . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/module/storinka', $this->token(), true))
		);
		$data['action'] = $this->url->link('extension/module/storinka', $this->token(), true);
		$data['cancel'] = $this->url->link($extension, $this->token() . '&type=module', true);
		$data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
		unset($this->session->data['success']);

		$defaults = array(
			'module_storinka_status' => 0,
			'module_storinka_replace' => 1,
			'module_storinka_guest' => 1,
			'module_storinka_register' => 1,
			'module_storinka_login' => 1,
			'module_storinka_coupon' => 1,
			'module_storinka_comment' => 1,
			'module_storinka_cart_edit' => 1,
			'module_storinka_image' => 1,
			'module_storinka_autoselect' => 1,
			'module_storinka_standard_link' => 1,
			'module_storinka_phone_mask' => 1,
			'module_storinka_auto_pay' => 1,
			'module_storinka_hide_columns' => 1,
			'module_storinka_no_address' => 'pickup',
			'module_storinka_rules' => "pickup.pickup = cod\n* = *",
			'module_storinka_np_status' => 0,
			'module_storinka_np_key' => '',
			'module_storinka_np_codes' => 'novaposhta,nova_poshta,np',
			'module_storinka_np_courier' => 'courier,doors,address',
			'module_storinka_accent' => '#9c3b2e',
			'module_storinka_radius' => 14,
			'module_storinka_heading' => '',
			'module_storinka_button_text' => '',
			'module_storinka_css' => ''
		);
		foreach ($defaults as $key => $default) {
			if (isset($this->request->post[$key])) {
				$data[$key] = $this->request->post[$key];
			} else {
				$value = $this->config->get($key);
				$data[$key] = ($value === null || $value === '') ? $default : $value;
			}
		}
		$field_defaults = array(
			'firstname' => array('status' => 1, 'required' => 1, 'sort' => 1, 'width' => 'half'),
			'lastname' => array('status' => 0, 'required' => 0, 'sort' => 2, 'width' => 'half'),
			'telephone' => array('status' => 1, 'required' => 1, 'sort' => 3, 'width' => 'half'),
			'email' => array('status' => 1, 'required' => 0, 'sort' => 4, 'width' => 'half'),
			'company' => array('status' => 0, 'required' => 0, 'sort' => 5, 'width' => 'full'),
			'country_id' => array('status' => 0, 'required' => 0, 'sort' => 6, 'width' => 'half'),
			'zone_id' => array('status' => 0, 'required' => 0, 'sort' => 7, 'width' => 'half'),
			'city' => array('status' => 1, 'required' => 1, 'sort' => 8, 'width' => 'half'),
			'address_1' => array('status' => 1, 'required' => 1, 'sort' => 9, 'width' => 'full'),
			'address_2' => array('status' => 0, 'required' => 0, 'sort' => 10, 'width' => 'full'),
			'postcode' => array('status' => 0, 'required' => 0, 'sort' => 11, 'width' => 'half')
		);
		$saved = $this->config->get('module_storinka_field');
		if (!is_array($saved)) $saved = array();
		if (isset($this->request->post['module_storinka_field'])) $saved = $this->request->post['module_storinka_field'];
		foreach ($field_defaults as $name => $field) {
			if (isset($saved[$name])) $field_defaults[$name] = array_merge($field, $saved[$name]);
		}
		$data['fields'] = $field_defaults;
		$data['field_labels'] = array(
			'firstname' => 'Ім\'я / First name',
			'lastname' => 'Прізвище / Last name',
			'telephone' => 'Телефон',
			'email' => 'Email',
			'company' => 'Компанія',
			'country_id' => 'Країна',
			'zone_id' => 'Регіон',
			'city' => 'Місто',
			'address_1' => 'Адреса',
			'address_2' => 'Адреса 2',
			'postcode' => 'Індекс'
		);

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/module/storinka', $data));
	}

	public function install() {
		$this->load->model('user/user_group');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/module/storinka');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/module/storinka');
		if (version_compare(VERSION, '3.0.0.0', '>=')) {
			$this->load->model('setting/event');
			$this->model_setting_event->deleteEventByCode('storinka');
			$this->model_setting_event->addEvent('storinka', 'catalog/controller/checkout/checkout/before', 'extension/module/storinka/eventCheckout', 1, 0);
		} else {
			$this->load->model('extension/event');
			$this->model_extension_event->deleteEvent('storinka');
			$this->model_extension_event->addEvent('storinka', 'catalog/controller/checkout/checkout/before', 'extension/module/storinka/eventCheckout');
		}
	}

	public function uninstall() {
		if (version_compare(VERSION, '3.0.0.0', '>=')) {
			$this->load->model('setting/event');
			$this->model_setting_event->deleteEventByCode('storinka');
		} else {
			$this->load->model('extension/event');
			$this->model_extension_event->deleteEvent('storinka');
		}
	}

	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/storinka')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		return !$this->error;
	}

	private function token() {
		if (version_compare(VERSION, '3.0.0.0', '>=')) {
			return 'user_token=' . $this->session->data['user_token'];
		}
		return 'token=' . $this->session->data['token'];
	}
}
