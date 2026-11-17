<?php
// Storinka Checkout 1.0.0 — one-page checkout for ocStore 3 / OpenCart 2.3 and 3.x. GPL-3.0-or-later.
class ControllerCheckoutStorinka extends Controller {
	public function index() {
		$this->prepareLanguage();

		if ($redirect = $this->cartRedirect()) {
			$this->response->redirect($redirect);
			return '';
		}

		$this->ensureSession();
		$this->document->setTitle($this->language->get('heading_title'));
		$this->document->setRobots('noindex,follow');
		$this->document->addStyle('catalog/view/javascript/storinka/storinka.css');
		$this->document->addScript('catalog/view/javascript/storinka/storinka.js');

		$data = $this->pageData();
		$data['header'] = $this->load->controller('common/header');
		$data['footer'] = $this->load->controller('common/footer');
		if ($this->cfg('hide_columns', 1)) {
			$data['column_left'] = '';
			$data['column_right'] = '';
		} else {
			$data['column_left'] = $this->load->controller('common/column_left');
			$data['column_right'] = $this->load->controller('common/column_right');
		}
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');

		$html = $this->load->view('checkout/storinka', $data);
		$this->response->setOutput($html);
		return $html;
	}

	public function save() {
		$this->prepareLanguage();
		$this->json($this->state(false));
	}

	public function cart() {
		$this->prepareLanguage();
		$cart_id = isset($this->request->post['cart_id']) ? (int)$this->request->post['cart_id'] : 0;
		$quantity = isset($this->request->post['quantity']) ? (int)$this->request->post['quantity'] : 1;
		if ($cart_id) {
			if ($quantity < 1) {
				$this->cart->remove($cart_id);
			} else {
				$this->cart->update($cart_id, $quantity);
			}
		}
		$this->json($this->state(false));
	}

	public function coupon() {
		$this->prepareLanguage();
		$code = isset($this->request->post['coupon']) ? trim($this->request->post['coupon']) : '';
		$json = array();
		if ($code === '') {
			unset($this->session->data['coupon']);
		} else {
			$this->load->model('extension/total/coupon');
			$coupon = $this->model_extension_total_coupon->getCoupon($code);
			if ($coupon) {
				$this->session->data['coupon'] = $code;
			} else {
				$json['coupon_error'] = $this->language->get('error_coupon');
			}
		}
		$state = $this->state(false);
		$this->json(array_merge($state, $json));
	}

	public function login() {
		$this->prepareLanguage();
		$json = array();
		$email = isset($this->request->post['email']) ? trim($this->request->post['email']) : '';
		$password = isset($this->request->post['password']) ? $this->request->post['password'] : '';
		if (!$this->customer->login($email, $password)) {
			$json['error'] = $this->language->get('error_login');
		} else {
			unset($this->session->data['guest']);
			$json['redirect'] = $this->url->link('checkout/checkout', '', true);
		}
		$this->json($json);
	}

	public function npCity() {
		$this->prepareLanguage();
		$q = isset($this->request->get['q']) ? trim($this->request->get['q']) : '';
		$cities = array();
		if (utf8_strlen($q) >= 2 && $this->cfg('np_key', '')) {
			$res = $this->npCall('Address', 'searchSettlements', array(
				'CityName' => $q,
				'Limit'    => 12,
				'Language' => $this->npLang()
			));
			if (!empty($res['data'][0]['Addresses']) && is_array($res['data'][0]['Addresses'])) {
				foreach ($res['data'][0]['Addresses'] as $row) {
					$ref = !empty($row['DeliveryCity']) ? $row['DeliveryCity'] : '';
					if (!$ref) continue;
					$cities[] = array(
						'ref'   => $ref,
						'name'  => $row['MainDescription'],
						'label' => $row['Present']
					);
				}
			}
		}
		$this->json(array('cities' => $cities));
	}

	public function npWarehouse() {
		$this->prepareLanguage();
		$ref = isset($this->request->get['ref']) ? $this->request->get['ref'] : '';
		$type = isset($this->request->get['type']) ? $this->request->get['type'] : 'branch';
		$warehouses = array();
		if ($ref && $this->cfg('np_key', '')) {
			$props = array(
				'CityRef' => $ref,
				'Limit'   => 150,
				'Language'=> $this->npLang()
			);
			$props['CategoryOfWarehouse'] = ($type === 'postomat') ? 'Postomat' : 'Branch';
			$res = $this->npCall('Address', 'getWarehouses', $props);
			if (!empty($res['data']) && is_array($res['data'])) {
				foreach ($res['data'] as $row) {
					$warehouses[] = array(
						'ref'   => $row['Ref'],
						'label' => $row['Description']
					);
				}
			}
		}
		$this->json(array('warehouses' => $warehouses));
	}

	public function zone() {
		$this->load->model('localisation/zone');
		$country_id = isset($this->request->get['country_id']) ? (int)$this->request->get['country_id'] : 0;
		$json = array('zone' => $this->model_localisation_zone->getZonesByCountryId($country_id));
		$this->json($json);
	}

	public function confirm() {
		$this->prepareLanguage();
		if (!empty($this->session->data['storinka_lock']) && (time() - (int)$this->session->data['storinka_lock']) < 12) {
			$this->json(array('error' => $this->language->get('error_lock')));
			return;
		}
		$this->session->data['storinka_lock'] = time();
		$state = $this->state(true);
		if (!empty($state['redirect']) || !empty($state['error']) || !empty($state['errors'])) {
			unset($this->session->data['storinka_lock']);
			$this->json($state);
			return;
		}

		$order_id = $this->writeOrder();
		$code = $this->session->data['payment_method']['code'];
		$payment = $this->load->controller('extension/payment/' . $code);
		unset($this->session->data['storinka_lock']);
		$this->json(array(
			'ok'       => true,
			'order_id' => $order_id,
			'payment'  => $payment ? $payment : ''
		));
	}

	private function state($strict) {
		if ($redirect = $this->cartRedirect()) {
			return array('redirect' => $redirect);
		}
		$this->capture();
		$errors = $strict ? $this->validate() : array();
		$this->syncAddresses();
		$methods = $this->shippingMethods();
		$totals = $this->collectTotals();
		$payments = $this->paymentMethods($totals['total']);
		if ($strict && $this->cart->hasShipping() && empty($this->session->data['shipping_method'])) {
			$errors['shipping'] = $this->language->get('error_shipping');
		}
		if ($strict && empty($this->session->data['payment_method'])) {
			$errors['payment'] = $this->language->get('error_payment');
		}
		if ($strict && $this->needsAgree() && empty($this->request->post['agree'])) {
			$errors['agree'] = $this->agreeError();
		}

		$payload = array(
			'errors'        => $errors,
			'address_mode'  => $this->addressMode(),
			'total_label'   => $this->currency->format($totals['total'], $this->session->data['currency']),
			'html'          => array(
				'shipping' => $this->load->view('checkout/storinka_shipping', array(
					'methods'  => $methods,
					'selected' => isset($this->session->data['shipping_method']['code']) ? $this->session->data['shipping_method']['code'] : '',
					'empty'    => $this->language->get('text_empty_shipping')
				)),
				'payment' => $this->load->view('checkout/storinka_payment', array(
					'methods'  => $payments,
					'selected' => isset($this->session->data['payment_method']['code']) ? $this->session->data['payment_method']['code'] : '',
					'empty'    => $this->language->get('text_empty_payment')
				)),
				'items' => $this->load->view('checkout/storinka_items', array(
					'products' => $this->productsView(),
					'edit'     => $this->cfg('cart_edit', 1)
				)),
				'totals' => $this->load->view('checkout/storinka_totals', array(
					'totals' => $this->totalsView($totals['totals'])
				))
			)
		);
		if ($errors) {
			$payload['error'] = $this->language->get('error_form');
		}
		return $payload;
	}

	private function pageData() {
		$this->syncAddresses();
		$methods = $this->shippingMethods();
		$totals = $this->collectTotals();
		$payments = $this->paymentMethods($totals['total']);
		$fields = $this->fieldConfig();

		$ship_data = array(
			'methods'  => $methods,
			'selected' => isset($this->session->data['shipping_method']['code']) ? $this->session->data['shipping_method']['code'] : '',
			'empty'    => $this->language->get('text_empty_shipping')
		);
		$pay_data = array(
			'methods'  => $payments,
			'selected' => isset($this->session->data['payment_method']['code']) ? $this->session->data['payment_method']['code'] : '',
			'empty'    => $this->language->get('text_empty_payment')
		);

		$mode = $this->addressMode();
		$config = array(
			'save'          => $this->url->link('checkout/storinka/save', '', true),
			'confirm'       => $this->url->link('checkout/storinka/confirm', '', true),
			'cart'          => $this->url->link('checkout/storinka/cart', '', true),
			'coupon'        => $this->url->link('checkout/storinka/coupon', '', true),
			'login'         => $this->url->link('checkout/storinka/login', '', true),
			'npCity'        => $this->url->link('checkout/storinka/npCity', '', true),
			'npWarehouse'   => $this->url->link('checkout/storinka/npWarehouse', '', true),
			'phoneMask'     => (bool)$this->cfg('phone_mask', 1),
			'autoPay'       => (bool)$this->cfg('auto_pay', 1),
			'np'            => (bool)$this->cfg('np_key', ''),
			'npPlaceholder' => $this->language->get('text_warehouse'),
			'errorNetwork'  => $this->language->get('error_network')
		);

		return array(
			'heading_title'  => $this->settingText('heading', 'heading_title'),
			'text_trust'     => $this->language->get('text_trust'),
			'text_contact'   => $this->language->get('text_contact'),
			'text_shipping'  => $this->language->get('text_shipping'),
			'text_payment'   => $this->language->get('text_payment'),
			'text_comment'   => $this->language->get('text_comment'),
			'text_coupon'    => $this->language->get('text_coupon'),
			'text_login'     => $this->language->get('text_login'),
			'text_register'  => $this->language->get('text_register'),
			'text_np'        => $this->language->get('text_np'),
			'text_branch'    => $this->language->get('text_branch'),
			'text_postomat'  => $this->language->get('text_postomat'),
			'text_city'      => $this->language->get('entry_city'),
			'text_street'    => $this->language->get('entry_address_1'),
			'text_standard'  => $this->language->get('text_standard'),
			'button_confirm' => $this->settingText('button_text', 'button_confirm'),
			'standard'       => $this->cfg('standard_link', 1) ? $this->url->link('checkout/checkout', 'storinka_off=1', true) : '',
			'logged'         => $this->customer->isLogged(),
			'login_enabled'  => $this->cfg('login', 1) && !$this->customer->isLogged(),
			'register_enabled' => $this->cfg('register', 1) && !$this->customer->isLogged() && $this->cfg('guest', 1),
			'fields'         => $this->fieldsView($fields),
			'address_fields'=> $this->addressFieldsView($fields),
			'custom_account' => $this->customFieldsView('account'),
			'custom_address' => $this->customFieldsView('address'),
			'countries'      => $this->countries(),
			'zones'          => $this->zonesFor((int)$this->value('country_id')),
			'show_country'   => !empty($fields['country_id']['status']),
			'show_zone'      => !empty($fields['zone_id']['status']),
			'country_id'     => (int)$this->value('country_id'),
			'zone_id'        => (int)$this->value('zone_id'),
			'address_mode'   => $mode,
			'np_on'          => ($mode === 'np' || $mode === 'np_courier'),
			'np_api'         => (bool)$this->cfg('np_key', ''),
			'np_type'        => $this->value('np_type') ? $this->value('np_type') : 'branch',
			'np_city'        => $this->value('np_city'),
			'np_city_ref'    => $this->value('np_city_ref'),
			'np_wh_label'    => $this->value('np_wh_label'),
			'comment'        => isset($this->session->data['comment']) ? $this->session->data['comment'] : '',
			'comment_on'     => $this->cfg('comment', 1),
			'coupon_on'      => $this->cfg('coupon', 1),
			'coupon'         => isset($this->session->data['coupon']) ? $this->session->data['coupon'] : '',
			'agree'          => $this->agreeLabel(),
			'accent'         => $this->safeColor($this->cfg('accent', '#9c3b2e')),
			'radius'         => (int)$this->cfg('radius', 14),
			'custom_css'     => (string)$this->cfg('css', ''),
			'shipping_html'  => $this->load->view('checkout/storinka_shipping', $ship_data),
			'payment_html'   => $this->load->view('checkout/storinka_payment', $pay_data),
			'items_html'     => $this->load->view('checkout/storinka_items', array('products' => $this->productsView(), 'edit' => $this->cfg('cart_edit', 1))),
			'totals_html'    => $this->load->view('checkout/storinka_totals', array('totals' => $this->totalsView($totals['totals']))),
			'total_label'    => $this->currency->format($totals['total'], $this->session->data['currency']),
			'config_json'    => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
			'action'         => $this->url->link('checkout/storinka/confirm', '', true)
		);
	}

	private function capture() {
		$map = array('firstname', 'lastname', 'email', 'telephone', 'company', 'address_1', 'address_2', 'city', 'postcode', 'comment', 'np_city', 'np_city_ref', 'np_wh', 'np_wh_label', 'np_type', 'password');
		foreach ($map as $key) {
			if (isset($this->request->post[$key])) {
				$this->session->data['storinka'][$key] = is_array($this->request->post[$key]) ? '' : trim($this->request->post[$key]);
			}
		}
		if (isset($this->request->post['country_id'])) {
			$this->session->data['storinka']['country_id'] = (int)$this->request->post['country_id'];
		}
		if (isset($this->request->post['zone_id'])) {
			$this->session->data['storinka']['zone_id'] = (int)$this->request->post['zone_id'];
		}
		if (isset($this->request->post['comment'])) {
			$this->session->data['comment'] = trim($this->request->post['comment']);
		}
		if (!isset($this->session->data['comment'])) {
			$this->session->data['comment'] = '';
		}
		if (isset($this->request->post['shipping_method'])) {
			$this->selectShipping($this->request->post['shipping_method']);
		}
		if (isset($this->request->post['payment_method'])) {
			$this->selectPayment($this->request->post['payment_method']);
		}
		if (isset($this->request->post['custom_field']) && is_array($this->request->post['custom_field'])) {
			$this->session->data['storinka']['custom_field'] = $this->request->post['custom_field'];
		}
	}

	private function ensureSession() {
		if (!isset($this->session->data['storinka']) || !is_array($this->session->data['storinka'])) {
			$this->session->data['storinka'] = array();
		}
		if ($this->customer->isLogged()) {
			if ($this->value('firstname') === '') $this->session->data['storinka']['firstname'] = $this->customer->getFirstName();
			if ($this->value('lastname') === '') $this->session->data['storinka']['lastname'] = $this->customer->getLastName();
			if ($this->value('email') === '') $this->session->data['storinka']['email'] = $this->customer->getEmail();
			if ($this->value('telephone') === '') $this->session->data['storinka']['telephone'] = $this->customer->getTelephone();
			$this->load->model('account/address');
			$address = $this->model_account_address->getAddress($this->customer->getAddressId());
			if ($address) {
				foreach (array('company', 'address_1', 'address_2', 'city', 'postcode') as $key) {
					if ($this->value($key) === '' && !empty($address[$key])) {
						$this->session->data['storinka'][$key] = $address[$key];
					}
				}
				if (!(int)$this->value('country_id') && !empty($address['country_id'])) {
					$this->session->data['storinka']['country_id'] = (int)$address['country_id'];
				}
				if (!(int)$this->value('zone_id') && !empty($address['zone_id'])) {
					$this->session->data['storinka']['zone_id'] = (int)$address['zone_id'];
				}
			}
		}
		if (!(int)$this->value('country_id')) {
			$this->session->data['storinka']['country_id'] = (int)$this->config->get('config_country_id');
		}
		if (!(int)$this->value('zone_id')) {
			$this->session->data['storinka']['zone_id'] = (int)$this->config->get('config_zone_id');
		}
		if (!isset($this->session->data['comment'])) {
			$this->session->data['comment'] = '';
		}
		$this->syncAddresses();
	}

	private function syncAddresses() {
		$country = $this->countryInfo((int)$this->value('country_id'));
		$zone = $this->zoneInfo((int)$this->value('zone_id'));
		$mode = $this->addressMode();
		$firstname = $this->value('firstname');
		$lastname = $this->value('lastname');
		if ($lastname === '') $lastname = '';
		$city = $this->value('city');
		$address_1 = $this->value('address_1');
		$address_2 = $this->value('address_2');
		if ($mode === 'np' || $mode === 'np_courier') {
			if ($this->value('np_city') !== '') $city = $this->value('np_city');
			if ($mode === 'np' && $this->value('np_wh_label') !== '') {
				$address_1 = $this->value('np_wh_label');
				$address_2 = $this->value('np_wh');
			}
		}
		if ($mode === 'none') {
			if ($city === '') $city = $country ? $country['name'] : '-';
			if ($address_1 === '') $address_1 = $this->language->get('text_pickup');
		}
		$address = array(
			'firstname'      => $firstname,
			'lastname'       => $lastname,
			'company'        => $this->value('company'),
			'address_1'      => $address_1,
			'address_2'      => $address_2,
			'postcode'       => $this->value('postcode'),
			'city'           => $city,
			'zone_id'        => $zone ? (int)$zone['zone_id'] : 0,
			'zone'           => $zone ? $zone['name'] : '',
			'zone_code'      => $zone ? $zone['code'] : '',
			'country_id'     => $country ? (int)$country['country_id'] : 0,
			'country'        => $country ? $country['name'] : '',
			'iso_code_2'     => $country ? $country['iso_code_2'] : '',
			'iso_code_3'     => $country ? $country['iso_code_3'] : '',
			'address_format' => $country ? $country['address_format'] : '',
			'custom_field'   => $this->customValues('address')
		);
		$this->session->data['payment_address'] = $address;
		if ($this->cart->hasShipping()) {
			$this->session->data['shipping_address'] = $address;
		}
		$email = $this->resolveEmail($this->value('email'), $this->value('telephone'));
		$guest = array(
			'customer_group_id' => $this->customerGroupId(),
			'firstname'          => $firstname,
			'lastname'           => $lastname,
			'email'              => $email,
			'telephone'          => $this->value('telephone'),
			'custom_field'       => $this->customValues('account')
		);
		if (!$this->customer->isLogged()) {
			$this->session->data['guest'] = $guest;
			$this->session->data['account'] = !empty($this->request->post['register']) ? 'register' : 'guest';
		}
		$np_label = '';
		if ($mode === 'np' || $mode === 'np_courier') {
			$np_label = trim($this->language->get('text_np') . ': ' . $city . ($address_1 ? ', ' . $address_1 : ''));
		}
		$this->session->data['storinka_np'] = array('label' => $np_label);
	}

	private function validate() {
		$errors = array();
		$fields = $this->fieldConfig();
		$mode = $this->addressMode();
		foreach (array('firstname', 'lastname', 'email', 'telephone') as $key) {
			if (empty($fields[$key]['status'])) continue;
			if (!empty($fields[$key]['required']) && utf8_strlen($this->value($key)) < 1) {
				$errors[$key] = $this->language->get('error_' . $key);
			}
		}
		if (!empty($fields['email']['status']) && $this->value('email') !== '' && !filter_var($this->value('email'), FILTER_VALIDATE_EMAIL)) {
			$errors['email'] = $this->language->get('error_email');
		}
		if (!empty($fields['telephone']['status']) && !empty($fields['telephone']['required'])) {
			$digits = preg_replace('/\D/', '', $this->value('telephone'));
			if (utf8_strlen($digits) < 10 || utf8_strlen($digits) > 15) {
				$errors['telephone'] = $this->language->get('error_telephone');
			}
		}
		if ($mode === 'address' || $mode === 'np_courier') {
			foreach (array('city', 'address_1', 'address_2', 'postcode', 'company') as $key) {
				if ($mode === 'np_courier' && ($key === 'city')) {
					if ($this->value('np_city') === '' && $this->value('city') === '') {
						$errors['np_city'] = $this->language->get('error_city');
					}
					continue;
				}
				if ($mode === 'np_courier' && $key === 'address_1') {
					if (utf8_strlen($this->value('address_1')) < 1) $errors['address_1'] = $this->language->get('error_address_1');
					continue;
				}
				if (empty($fields[$key]['status']) || empty($fields[$key]['required'])) continue;
				if (utf8_strlen($this->value($key)) < 1) {
					$errors[$key] = $this->language->get('error_' . $key);
				}
			}
		}
		if ($mode === 'np' && $this->value('np_wh_label') === '' && $this->value('np_wh') === '') {
			$errors['np_wh'] = $this->language->get('error_warehouse');
		}
		if ($mode === 'np' && $this->value('np_city') === '' && $this->value('city') === '') {
			$errors['np_city'] = $this->language->get('error_city');
		}
		if (!empty($this->request->post['register'])) {
			if (utf8_strlen($this->request->post['password']) < 4) {
				$errors['password'] = $this->language->get('error_password');
			}
			$email = $this->resolveEmail($this->value('email'), $this->value('telephone'));
			$this->load->model('account/customer');
			if ($this->model_account_customer->getTotalCustomersByEmail($email)) {
				$errors['email'] = $this->language->get('error_exists');
			}
		}
		return $errors;
	}

	private function writeOrder() {
		if (!empty($this->request->post['register']) && !$this->customer->isLogged()) {
			$this->registerCustomer();
		}
		$totals = $this->collectTotals();
		$order_data = $this->orderData($totals);
		$this->load->model('checkout/order');
		$order_id = 0;
		if (!empty($this->session->data['order_id'])) {
			$existing = $this->model_checkout_order->getOrder($this->session->data['order_id']);
			if ($existing && empty($existing['order_status_id'])) {
				$this->model_checkout_order->editOrder($this->session->data['order_id'], $order_data);
				$order_id = (int)$this->session->data['order_id'];
			}
		}
		if (!$order_id) {
			$order_id = (int)$this->model_checkout_order->addOrder($order_data);
			$this->session->data['order_id'] = $order_id;
		}
		return $order_id;
	}

	private function orderData($totals) {
		$this->load->language('checkout/checkout');
		$order_data = array();
		$order_data['totals'] = $totals['totals'];
		$order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
		$order_data['store_id'] = $this->config->get('config_store_id');
		$order_data['store_name'] = $this->config->get('config_name');
		if ($order_data['store_id']) {
			$order_data['store_url'] = $this->config->get('config_url');
		} else {
			$order_data['store_url'] = !empty($this->request->server['HTTPS']) ? HTTPS_SERVER : HTTP_SERVER;
		}
		$this->load->model('account/customer');
		if ($this->customer->isLogged()) {
			$customer_info = $this->model_account_customer->getCustomer($this->customer->getId());
			$order_data['customer_id'] = $this->customer->getId();
			$order_data['customer_group_id'] = $customer_info['customer_group_id'];
			$order_data['firstname'] = $this->value('firstname') !== '' ? $this->value('firstname') : $customer_info['firstname'];
			$order_data['lastname'] = $this->value('lastname') !== '' ? $this->value('lastname') : $customer_info['lastname'];
			$order_data['email'] = $customer_info['email'];
			$order_data['telephone'] = $this->value('telephone') !== '' ? $this->value('telephone') : $customer_info['telephone'];
			$order_data['custom_field'] = $this->customValues('account');
		} else {
			$order_data['customer_id'] = 0;
			$order_data['customer_group_id'] = $this->session->data['guest']['customer_group_id'];
			$order_data['firstname'] = $this->session->data['guest']['firstname'];
			$order_data['lastname'] = $this->session->data['guest']['lastname'];
			$order_data['email'] = $this->session->data['guest']['email'];
			$order_data['telephone'] = $this->session->data['guest']['telephone'];
			$order_data['custom_field'] = $this->session->data['guest']['custom_field'];
		}
		$pay = $this->session->data['payment_address'];
		foreach (array('firstname', 'lastname', 'company', 'address_1', 'address_2', 'city', 'postcode', 'zone', 'zone_id', 'country', 'country_id', 'address_format') as $key) {
			$order_data['payment_' . $key] = isset($pay[$key]) ? $pay[$key] : '';
		}
		$order_data['payment_custom_field'] = isset($pay['custom_field']) ? $pay['custom_field'] : array();
		$order_data['payment_method'] = isset($this->session->data['payment_method']['title']) ? $this->session->data['payment_method']['title'] : '';
		$order_data['payment_code'] = isset($this->session->data['payment_method']['code']) ? $this->session->data['payment_method']['code'] : '';
		if ($this->cart->hasShipping()) {
			$ship = $this->session->data['shipping_address'];
			foreach (array('firstname', 'lastname', 'company', 'address_1', 'address_2', 'city', 'postcode', 'zone', 'zone_id', 'country', 'country_id', 'address_format') as $key) {
				$order_data['shipping_' . $key] = isset($ship[$key]) ? $ship[$key] : '';
			}
			$order_data['shipping_custom_field'] = isset($ship['custom_field']) ? $ship['custom_field'] : array();
			$order_data['shipping_method'] = isset($this->session->data['shipping_method']['title']) ? $this->session->data['shipping_method']['title'] : '';
			$order_data['shipping_code'] = isset($this->session->data['shipping_method']['code']) ? $this->session->data['shipping_method']['code'] : '';
		} else {
			foreach (array('firstname', 'lastname', 'company', 'address_1', 'address_2', 'city', 'postcode', 'zone', 'zone_id', 'country', 'country_id', 'address_format', 'method', 'code') as $key) {
				$order_data['shipping_' . $key] = '';
			}
			$order_data['shipping_custom_field'] = array();
		}
		$order_data['products'] = array();
		foreach ($this->cart->getProducts() as $product) {
			$option_data = array();
			foreach ($product['option'] as $option) {
				$option_data[] = array(
					'product_option_id'       => $option['product_option_id'],
					'product_option_value_id' => $option['product_option_value_id'],
					'option_id'               => $option['option_id'],
					'option_value_id'         => $option['option_value_id'],
					'name'                    => $option['name'],
					'value'                   => $option['value'],
					'type'                    => $option['type']
				);
			}
			$order_data['products'][] = array(
				'product_id' => $product['product_id'],
				'name'       => $product['name'],
				'model'      => $product['model'],
				'option'     => $option_data,
				'download'   => $product['download'],
				'quantity'   => $product['quantity'],
				'subtract'   => $product['subtract'],
				'price'      => $product['price'],
				'total'      => $product['total'],
				'tax'        => $this->tax->getTax($product['price'], $product['tax_class_id']),
				'reward'     => $product['reward']
			);
		}
		$order_data['vouchers'] = array();
		if (!empty($this->session->data['vouchers'])) {
			foreach ($this->session->data['vouchers'] as $voucher) {
				$order_data['vouchers'][] = array(
					'description'      => $voucher['description'],
					'code'             => token(10),
					'to_name'          => $voucher['to_name'],
					'to_email'         => $voucher['to_email'],
					'from_name'        => $voucher['from_name'],
					'from_email'       => $voucher['from_email'],
					'voucher_theme_id' => $voucher['voucher_theme_id'],
					'message'          => $voucher['message'],
					'amount'           => $voucher['amount']
				);
			}
		}
		$comment = isset($this->session->data['comment']) ? $this->session->data['comment'] : '';
		if (!empty($this->session->data['storinka_np']['label'])) {
			$comment = trim($comment . "\n" . $this->session->data['storinka_np']['label']);
		}
		$order_data['comment'] = $comment;
		$order_data['total'] = $totals['total'];
		$order_data['affiliate_id'] = 0;
		$order_data['commission'] = 0;
		$order_data['marketing_id'] = 0;
		$order_data['tracking'] = '';
		if (isset($this->request->cookie['tracking'])) {
			$order_data['tracking'] = $this->request->cookie['tracking'];
			$subtotal = $this->cart->getSubTotal();
			$affiliate_info = $this->model_account_customer->getAffiliateByTracking($this->request->cookie['tracking']);
			if ($affiliate_info) {
				$order_data['affiliate_id'] = $affiliate_info['customer_id'];
				$order_data['commission'] = ($subtotal / 100) * $affiliate_info['commission'];
			}
			$this->load->model('checkout/marketing');
			$marketing_info = $this->model_checkout_marketing->getMarketingByCode($this->request->cookie['tracking']);
			if ($marketing_info) {
				$order_data['marketing_id'] = $marketing_info['marketing_id'];
			}
		}
		$order_data['language_id'] = $this->config->get('config_language_id');
		$order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
		$order_data['currency_code'] = $this->session->data['currency'];
		$order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
		$order_data['ip'] = isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : '';
		if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
			$order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
		} elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
			$order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
		} else {
			$order_data['forwarded_ip'] = '';
		}
		$order_data['user_agent'] = isset($this->request->server['HTTP_USER_AGENT']) ? $this->request->server['HTTP_USER_AGENT'] : '';
		$order_data['accept_language'] = isset($this->request->server['HTTP_ACCEPT_LANGUAGE']) ? $this->request->server['HTTP_ACCEPT_LANGUAGE'] : '';
		return $order_data;
	}

	private function registerCustomer() {
		$this->load->model('account/customer');
		$email = $this->resolveEmail($this->value('email'), $this->value('telephone'));
		$customer_id = $this->model_account_customer->addCustomer(array(
			'customer_group_id' => $this->customerGroupId(),
			'firstname'          => $this->value('firstname'),
			'lastname'           => $this->value('lastname') !== '' ? $this->value('lastname') : '-',
			'email'              => $email,
			'telephone'          => $this->value('telephone'),
			'custom_field'       => array('account' => $this->customValues('account')),
			'password'           => $this->request->post['password'],
			'newsletter'         => 0,
			'company'            => $this->value('company'),
			'address_1'          => $this->session->data['payment_address']['address_1'],
			'address_2'          => $this->session->data['payment_address']['address_2'],
			'city'               => $this->session->data['payment_address']['city'],
			'postcode'           => $this->session->data['payment_address']['postcode'],
			'country_id'         => $this->session->data['payment_address']['country_id'],
			'zone_id'            => $this->session->data['payment_address']['zone_id'],
			'status'             => 1
		));
		if ($customer_id) {
			$this->customer->login($email, $this->request->post['password']);
			unset($this->session->data['guest']);
		}
	}

	private function shippingMethods() {
		if (!$this->cart->hasShipping()) {
			unset($this->session->data['shipping_method']);
			unset($this->session->data['shipping_methods']);
			return array();
		}
		if (empty($this->session->data['shipping_address'])) {
			$this->syncAddresses();
		}
		$method_data = array();
		foreach ($this->getExtensions('shipping') as $result) {
			if (!$this->isEnabled('shipping', $result['code'])) continue;
			$this->load->model('extension/shipping/' . $result['code']);
			$quote = $this->{'model_extension_shipping_' . $result['code']}->getQuote($this->session->data['shipping_address']);
			if ($quote) {
				$method_data[$result['code']] = array(
					'title'      => isset($quote['title']) ? $quote['title'] : $result['code'],
					'quote'      => isset($quote['quote']) ? $quote['quote'] : array(),
					'sort_order' => isset($quote['sort_order']) ? $quote['sort_order'] : 0,
					'error'      => !empty($quote['error']) ? $quote['error'] : ''
				);
			}
		}
		$sort = array();
		foreach ($method_data as $key => $value) $sort[$key] = $value['sort_order'];
		array_multisort($sort, SORT_ASC, $method_data);
		$this->session->data['shipping_methods'] = $method_data;
		$flat = $this->flattenQuotes($method_data);
		$selected = isset($this->session->data['shipping_method']['code']) ? $this->session->data['shipping_method']['code'] : '';
		if ($selected && !isset($flat[$selected])) {
			unset($this->session->data['shipping_method']);
			$selected = '';
		}
		if (!$selected && $this->cfg('autoselect', 1) && count($flat) === 1) {
			$this->session->data['shipping_method'] = reset($flat);
		}
		return $method_data;
	}

	private function selectShipping($code) {
		if (empty($this->session->data['shipping_methods'])) return;
		foreach ($this->session->data['shipping_methods'] as $method) {
			if (empty($method['quote'])) continue;
			foreach ($method['quote'] as $quote) {
				if (isset($quote['code']) && $quote['code'] === $code) {
					$this->session->data['shipping_method'] = $quote;
					return;
				}
			}
		}
	}

	private function paymentMethods($total) {
		$method_data = array();
		$address = isset($this->session->data['payment_address']) ? $this->session->data['payment_address'] : array();
		$rows = array();
		foreach ($this->getExtensions('payment') as $result) {
			if (!$this->isEnabled('payment', $result['code'])) continue;
			$this->load->model('extension/payment/' . $result['code']);
			$method = $this->{'model_extension_payment_' . $result['code']}->getMethod($address, $total);
			if ($method) $rows[] = $method;
		}
		usort($rows, function ($a, $b) {
			$as = isset($a['sort_order']) ? (int)$a['sort_order'] : 0;
			$bs = isset($b['sort_order']) ? (int)$b['sort_order'] : 0;
			if ($as == $bs) return 0;
			return ($as < $bs) ? -1 : 1;
		});
		$ship = isset($this->session->data['shipping_method']['code']) ? $this->session->data['shipping_method']['code'] : '';
		foreach ($rows as $method) {
			if (!$this->paymentAllowed($ship, $method['code'])) continue;
			$method_data[$method['code']] = $method;
		}
		$this->session->data['payment_methods'] = $method_data;
		$selected = isset($this->session->data['payment_method']['code']) ? $this->session->data['payment_method']['code'] : '';
		if ($selected && !isset($method_data[$selected])) {
			unset($this->session->data['payment_method']);
			$selected = '';
		}
		if (!$selected && $this->cfg('autoselect', 1) && count($method_data) === 1) {
			$this->session->data['payment_method'] = reset($method_data);
		}
		return $method_data;
	}

	private function selectPayment($code) {
		if (!empty($this->session->data['payment_methods'][$code])) {
			$this->session->data['payment_method'] = $this->session->data['payment_methods'][$code];
		}
	}

	private function collectTotals() {
		$totals = array();
		$taxes = $this->cart->getTaxes();
		$total = 0;
		$total_data = array(
			'totals' => &$totals,
			'taxes'  => &$taxes,
			'total'  => &$total
		);
		$results = $this->getExtensions('total');
		$sort = array();
		foreach ($results as $key => $value) {
			$sort[$key] = (int)$this->config->get($this->sortKey('total', $value['code']));
		}
		array_multisort($sort, SORT_ASC, $results);
		foreach ($results as $result) {
			if (!$this->isEnabled('total', $result['code'])) continue;
			$this->load->model('extension/total/' . $result['code']);
			$this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
		}
		$sort = array();
		foreach ($totals as $key => $value) $sort[$key] = $value['sort_order'];
		array_multisort($sort, SORT_ASC, $totals);
		return array('totals' => $totals, 'total' => $total);
	}

	private function productsView() {
		$this->load->model('tool/image');
		$this->load->model('catalog/product');
		$rows = array();
		foreach ($this->cart->getProducts() as $product) {
			$info = $this->model_catalog_product->getProduct($product['product_id']);
			$thumb = '';
			if ($this->cfg('image', 1) && $info && !empty($info['image']) && is_file(DIR_IMAGE . $info['image'])) {
				$thumb = $this->model_tool_image->resize($info['image'], 120, 120);
			}
			$options = array();
			foreach ($product['option'] as $option) {
				$options[] = $option['name'] . ': ' . $option['value'];
			}
			$rows[] = array(
				'cart_id' => $product['cart_id'],
				'name'    => $product['name'],
				'option'  => implode(', ', $options),
				'quantity'=> $product['quantity'],
				'thumb'   => $thumb,
				'total'   => $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')) * $product['quantity'], $this->session->data['currency']),
				'href'    => $this->url->link('product/product', 'product_id=' . $product['product_id'])
			);
		}
		return $rows;
	}

	private function totalsView($totals) {
		$rows = array();
		foreach ($totals as $total) {
			$rows[] = array(
				'title' => $total['title'],
				'text'  => $this->currency->format($total['value'], $this->session->data['currency']),
				'code'  => $total['code']
			);
		}
		return $rows;
	}

	private function fieldsView($fields) {
		$labels = array(
			'firstname' => 'entry_firstname',
			'lastname'  => 'entry_lastname',
			'email'     => 'entry_email',
			'telephone' => 'entry_telephone',
			'company'   => 'entry_company',
			'address_1' => 'entry_address_1',
			'address_2' => 'entry_address_2',
			'city'      => 'entry_city',
			'postcode'  => 'entry_postcode'
		);
		$contact = array('firstname', 'lastname', 'email', 'telephone');
		$rows = array();
		foreach ($fields as $key => $field) {
			if (empty($field['status'])) continue;
			if (!isset($labels[$key])) continue;
			if (!in_array($key, $contact, true) && !in_array($key, array('company'), true)) continue;
			$type = 'text';
			if ($key === 'email') $type = 'email';
			if ($key === 'telephone') $type = 'tel';
			$rows[] = array(
				'name'     => $key,
				'label'    => $this->language->get($labels[$key]),
				'type'     => $type,
				'value'    => $this->value($key),
				'width'    => !empty($field['width']) ? $field['width'] : 'full',
				'required' => !empty($field['required'])
			);
		}
		return $rows;
	}

	private function addressFieldsView($fields) {
		$labels = array(
			'address_1' => 'entry_address_1',
			'address_2' => 'entry_address_2',
			'city'      => 'entry_city',
			'postcode'  => 'entry_postcode'
		);
		$rows = array();
		foreach ($fields as $key => $field) {
			if (empty($field['status']) || !isset($labels[$key])) continue;
			$rows[] = array(
				'name'     => $key,
				'label'    => $this->language->get($labels[$key]),
				'value'    => $this->value($key),
				'width'    => !empty($field['width']) ? $field['width'] : 'full',
				'required' => !empty($field['required'])
			);
		}
		return $rows;
	}

	private function customFieldsView($location) {
		$this->load->model('account/custom_field');
		$rows = array();
		$fields = $this->model_account_custom_field->getCustomFields($this->customerGroupId());
		$values = $this->customValues($location);
		foreach ($fields as $field) {
			if ($field['location'] !== $location) continue;
			if ($field['type'] === 'file') continue;
			$rows[] = array(
				'id'       => $field['custom_field_id'],
				'name'     => $field['name'],
				'type'     => $field['type'],
				'required' => $field['required'],
				'options'  => isset($field['custom_field_value']) ? $field['custom_field_value'] : array(),
				'value'    => isset($values[$field['custom_field_id']]) ? $values[$field['custom_field_id']] : ''
			);
		}
		return $rows;
	}

	private function customValues($location) {
		$all = array();
		if (!empty($this->session->data['storinka']['custom_field'][$location]) && is_array($this->session->data['storinka']['custom_field'][$location])) {
			$all = $this->session->data['storinka']['custom_field'][$location];
		}
		return $all;
	}

	private function countries() {
		$this->load->model('localisation/country');
		return $this->model_localisation_country->getCountries();
	}

	private function zonesFor($country_id) {
		$this->load->model('localisation/zone');
		return $this->model_localisation_zone->getZonesByCountryId($country_id);
	}

	private function countryInfo($country_id) {
		$this->load->model('localisation/country');
		$info = $this->model_localisation_country->getCountry($country_id);
		return $info ? $info : array();
	}

	private function zoneInfo($zone_id) {
		$this->load->model('localisation/zone');
		$info = $this->model_localisation_zone->getZone($zone_id);
		return $info ? $info : array();
	}

	private function addressMode() {
		if (!$this->cart->hasShipping()) return 'none';
		$code = isset($this->session->data['shipping_method']['code']) ? $this->session->data['shipping_method']['code'] : '';
		if ($code && $this->matchList($code, $this->cfg('no_address', 'pickup'))) return 'none';
		if ($this->cfg('np_status', 0) && $code && $this->matchList($code, $this->cfg('np_codes', 'novaposhta,nova_poshta,np'))) {
			if ($this->matchList($code, $this->cfg('np_courier', 'courier,doors,address'))) return 'np_courier';
			return 'np';
		}
		return 'address';
	}

	private function paymentAllowed($shipping_code, $payment_code) {
		$rules = $this->parseRules((string)$this->cfg('rules', ''));
		if (!$rules) return true;
		foreach ($rules as $rule) {
			if ($this->ruleMatches($rule['shipping'], $shipping_code)) {
				return in_array('*', $rule['payments'], true) || in_array($payment_code, $rule['payments'], true);
			}
		}
		return true;
	}

	private function ruleMatches($rule, $code) {
		if ($rule === '*' || $rule === '') return $rule === '*';
		if ($code === $rule) return true;
		if (strpos($code, $rule . '.') === 0) return true;
		$base = $code;
		if (strpos($code, '.') !== false) {
			$parts = explode('.', $code);
			$base = $parts[0];
		}
		return $base === $rule;
	}

	private function parseRules($text) {
		$rules = array();
		$lines = preg_split('/\r\n|\r|\n/', $text);
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') continue;
			$parts = explode('=', $line, 2);
			if (count($parts) < 2) continue;
			$payments = array();
			foreach (explode(',', $parts[1]) as $payment) {
				$payment = trim($payment);
				if ($payment !== '') $payments[] = $payment;
			}
			$rules[] = array('shipping' => trim($parts[0]), 'payments' => $payments);
		}
		return $rules;
	}

	private function matchList($code, $list) {
		foreach (explode(',', (string)$list) as $item) {
			$item = trim($item);
			if ($item === '') continue;
			if ($code === $item || strpos($code, $item) !== false) return true;
		}
		return false;
	}

	private function fieldConfig() {
		$defaults = array(
			'firstname'  => array('status' => 1, 'required' => 1, 'sort' => 1, 'width' => 'half'),
			'lastname'   => array('status' => 0, 'required' => 0, 'sort' => 2, 'width' => 'half'),
			'telephone'  => array('status' => 1, 'required' => 1, 'sort' => 3, 'width' => 'half'),
			'email'      => array('status' => 1, 'required' => 0, 'sort' => 4, 'width' => 'half'),
			'company'    => array('status' => 0, 'required' => 0, 'sort' => 5, 'width' => 'full'),
			'country_id' => array('status' => 0, 'required' => 0, 'sort' => 6, 'width' => 'half'),
			'zone_id'    => array('status' => 0, 'required' => 0, 'sort' => 7, 'width' => 'half'),
			'city'       => array('status' => 1, 'required' => 1, 'sort' => 8, 'width' => 'half'),
			'address_1'  => array('status' => 1, 'required' => 1, 'sort' => 9, 'width' => 'full'),
			'address_2'  => array('status' => 0, 'required' => 0, 'sort' => 10, 'width' => 'full'),
			'postcode'   => array('status' => 0, 'required' => 0, 'sort' => 11, 'width' => 'half')
		);
		$saved = $this->config->get('module_storinka_field');
		if (!is_array($saved)) return $defaults;
		foreach ($defaults as $key => $field) {
			if (isset($saved[$key]) && is_array($saved[$key])) {
				$defaults[$key] = array_merge($field, $saved[$key]);
			}
		}
		uasort($defaults, function ($a, $b) {
			$as = isset($a['sort']) ? (int)$a['sort'] : 0;
			$bs = isset($b['sort']) ? (int)$b['sort'] : 0;
			if ($as == $bs) return 0;
			return ($as < $bs) ? -1 : 1;
		});
		return $defaults;
	}

	private function flattenQuotes($methods) {
		$out = array();
		foreach ($methods as $method) {
			if (empty($method['quote']) || !is_array($method['quote'])) continue;
			foreach ($method['quote'] as $quote) {
				if (isset($quote['code'])) $out[$quote['code']] = $quote;
			}
		}
		return $out;
	}

	private function getExtensions($type) {
		if (version_compare(VERSION, '3.0.0.0', '>=')) {
			$this->load->model('setting/extension');
			return $this->model_setting_extension->getExtensions($type);
		}
		$this->load->model('extension/extension');
		return $this->model_extension_extension->getExtensions($type);
	}

	private function isEnabled($type, $code) {
		if (version_compare(VERSION, '3.0.0.0', '>=')) {
			return (bool)$this->config->get($type . '_' . $code . '_status');
		}
		return (bool)$this->config->get($code . '_status');
	}

	private function sortKey($type, $code) {
		if (version_compare(VERSION, '3.0.0.0', '>=')) {
			return $type . '_' . $code . '_sort_order';
		}
		return $code . '_sort_order';
	}

	private function cfg($key, $default = null) {
		$value = $this->config->get('module_storinka_' . $key);
		if ($value === null || $value === '') return $default;
		return $value;
	}

	private function value($key) {
		if (isset($this->session->data['storinka'][$key])) return (string)$this->session->data['storinka'][$key];
		return '';
	}

	private function customerGroupId() {
		if ($this->customer->isLogged()) return (int)$this->customer->getGroupId();
		return (int)$this->config->get('config_customer_group_id');
	}

	private function resolveEmail($email, $telephone) {
		$email = trim((string)$email);
		if ($email !== '') return $email;
		$digits = preg_replace('/\D/', '', (string)$telephone);
		if ($digits === '') $digits = 'guest';
		return $digits . '@guest.storinka';
	}

	private function needsAgree() {
		return (bool)$this->config->get('config_checkout_id');
	}

	private function agreeLabel() {
		if (!$this->needsAgree()) return '';
		$this->load->model('catalog/information');
		$info = $this->model_catalog_information->getInformation($this->config->get('config_checkout_id'));
		if (!$info) return '';
		$href = $this->url->link('information/information/agree', 'information_id=' . (int)$info['information_id'], true);
		$title = htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8');
		return sprintf($this->language->get('text_agree'), $href, $title);
	}

	private function agreeError() {
		$this->load->model('catalog/information');
		$info = $this->model_catalog_information->getInformation($this->config->get('config_checkout_id'));
		$title = $info ? $info['title'] : '';
		return sprintf($this->language->get('error_agree'), $title);
	}

	private function cartRedirect() {
		if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
			return $this->url->link('checkout/cart');
		}
		$products = $this->cart->getProducts();
		foreach ($products as $product) {
			$total = 0;
			foreach ($products as $product_2) {
				if ($product_2['product_id'] == $product['product_id']) $total += $product_2['quantity'];
			}
			if ($product['minimum'] > $total) return $this->url->link('checkout/cart');
		}
		return '';
	}

	private function settingText($key, $language_key) {
		$text = trim((string)$this->cfg($key, ''));
		if ($text !== '') return $text;
		return $this->language->get($language_key);
	}

	private function safeColor($color) {
		$color = trim((string)$color);
		if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) return $color;
		return '#9c3b2e';
	}

	private function npLang() {
		$code = (string)$this->config->get('config_language');
		if (strpos($code, 'ru') !== false) return 'RU';
		if (strpos($code, 'en') !== false) return 'EN';
		return 'UA';
	}

	private function npCall($model, $method, $props) {
		$key = (string)$this->cfg('np_key', '');
		if ($key === '' || !function_exists('curl_init')) return array();
		$body = json_encode(array(
			'apiKey'           => $key,
			'modelName'        => $model,
			'calledMethod'     => $method,
			'methodProperties' => $props
		));
		$ch = curl_init('https://api.novaposhta.ua/v2.0/json/');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
		curl_setopt($ch, CURLOPT_TIMEOUT, 12);
		$raw = curl_exec($ch);
		curl_close($ch);
		if (!$raw) return array();
		$data = json_decode($raw, true);
		return is_array($data) ? $data : array();
	}

	private function prepareLanguage() {
		$this->load->language('checkout/storinka');
	}

	private function json($data) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
}
