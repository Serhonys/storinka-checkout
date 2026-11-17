<?php
class ControllerExtensionModuleStorinka extends Controller {
	public function eventCheckout(&$route, &$data) {
		if (!$this->config->get('module_storinka_status')) {
			return null;
		}
		if (!empty($this->request->get['storinka_off'])) {
			return null;
		}
		if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
			return null;
		}
		$html = $this->load->controller('checkout/storinka');
		if ($html) {
			return $html;
		}
		return null;
	}

	public function render() {
		if (!$this->config->get('module_storinka_status') || !empty($this->request->get['storinka_off'])) {
			return '';
		}
		return $this->load->controller('checkout/storinka');
	}
}
