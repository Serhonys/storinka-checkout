#!/usr/bin/env python3
"""Assemble the Storinka ocmod zip for ocStore 3.0.4.1 only."""
import re
import shutil
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SRC = ROOT / "src"
DIST = ROOT / "dist"
VERSION = "1.0.1"

ZIPS = {
    "oc3": f"storinka-v{VERSION}-ocstore-3.0.4.1.ocmod.zip",
}


RAW_ECHO = {
    "header", "footer", "content_top", "content_bottom", "column_left", "column_right",
    "shipping_html", "payment_html", "items_html", "totals_html", "agree", "config_json", "custom_css",
}


def php_path(expr: str) -> str:
    expr = expr.strip()
    bits = expr.split(".")
    php = "$" + bits[0]
    for bit in bits[1:]:
        php += "['" + bit + "']"
    return php


def twig_expr(expr: str) -> str:
    expr = expr.strip()
    if expr.endswith("|raw"):
        expr = expr[: -len("|raw")].strip()
    if re.fullmatch(r"[A-Za-z_][\w]*(\.[A-Za-z_][\w]*)*", expr):
        return php_path(expr)
    text = expr
    text = text.replace(" and ", " && ").replace(" or ", " || ")
    text = re.sub(r"\bnot\s+", "!", text)
    text = re.sub(r"([A-Za-z_][\w]*)\.([A-Za-z_][\w]*)", r"$\1['\2']", text)
    text = re.sub(r"(?<![\$'\"])\b([A-Za-z_][\w]*)\b", r"$\1", text)
    text = re.sub(r"\$'([^']*)'", r"'\1'", text)
    text = text.replace("$true", "true").replace("$false", "false").replace("$null", "null")
    return text


def convert_twig(src: str) -> str:
    out = []
    i = 0
    while i < len(src):
        if src.startswith("{%", i):
            end = src.find("%}", i)
            tag = src[i + 2 : end].strip()
            i = end + 2
            if tag.startswith("for "):
                pair = re.match(r"for\s+([\w]+)\s*,\s*([\w]+)\s+in\s+(\S+)", tag)
                one = re.match(r"for\s+([\w]+)\s+in\s+(\S+)", tag)
                if pair:
                    out.append(f"<?php foreach ({php_path(pair.group(3))} as ${pair.group(1)} => ${pair.group(2)}) {{ ?>")
                elif one:
                    out.append(f"<?php foreach ({php_path(one.group(2))} as ${one.group(1)}) {{ ?>")
            elif tag.startswith("if "):
                out.append("<?php if (" + twig_expr(tag[3:]) + ") { ?>")
            elif tag.startswith("elseif "):
                out.append("<?php } elseif (" + twig_expr(tag[7:]) + ") { ?>")
            elif tag == "else":
                out.append("<?php } else { ?>")
            elif tag in {"endif", "endfor"}:
                out.append("<?php } ?>")
        elif src.startswith("{{", i):
            end = src.find("}}", i)
            expr = src[i + 2 : end].strip()
            raw = expr.endswith("|raw") or expr in RAW_ECHO
            php = twig_expr(expr)
            if raw:
                out.append(f"<?php echo {php}; ?>")
            else:
                out.append(f"<?php echo htmlspecialchars((string)({php}), ENT_QUOTES, 'UTF-8'); ?>")
            i = end + 2
        else:
            nxt = len(src)
            for mark in ("{%", "{{"):
                p = src.find(mark, i)
                if p != -1:
                    nxt = min(nxt, p)
            out.append(src[i:nxt])
            i = nxt
    return "".join(out)


def copy_tree(upload: Path) -> None:
    target = upload / "catalog/controller/extension/module/storinka.php"
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy(SRC / "catalog/controller/extension/module/storinka.php", target)
    admin = upload / "admin/controller/extension/module/storinka.php"
    admin.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy(SRC / "admin/controller/extension/module/storinka.php", admin)

    for lang in ("uk-ua", "ru-ru", "en-gb"):
        cat = upload / f"catalog/language/{lang}/extension/module"
        cat.mkdir(parents=True, exist_ok=True)
        shutil.copy(SRC / f"language/{lang}/checkout/storinka.php", cat / "storinka.php")
        adm = upload / f"admin/language/{lang}/extension/module"
        adm.mkdir(parents=True, exist_ok=True)
        shutil.copy(SRC / f"language/{lang}/admin/storinka.php", adm / "storinka.php")

    js = upload / "catalog/view/javascript/storinka"
    js.mkdir(parents=True, exist_ok=True)
    shutil.copy(ROOT / "assets/storinka.css", js / "storinka.css")
    shutil.copy(ROOT / "assets/storinka.js", js / "storinka.js")

    view = upload / "catalog/view/theme/default/template/extension/module"
    view.mkdir(parents=True, exist_ok=True)
    admin_view = upload / "admin/view/template/extension/module"
    admin_view.mkdir(parents=True, exist_ok=True)
    for twig in (SRC / "catalog/view/checkout").glob("*.twig"):
        shutil.copy(twig, view / twig.name)
    shutil.copy(SRC / "admin/view/extension/module/storinka.twig", admin_view / "storinka.twig")


def zip_dir(folder: Path, zip_path: Path) -> None:
    if zip_path.exists():
        zip_path.unlink()
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(folder.rglob("*")):
            if path.is_file():
                zf.write(path, path.relative_to(folder).as_posix())


def build_oc4(stage: Path) -> None:
    # OC4 keeps the same storefront assets and a note-plus-installer that hooks the 4.x event.
    # The catalog controller is the 3.x engine adapted only where 4.x route style differs:
    # merchants on 4.1 should use this package; order rows follow the 4.x shape in README.
    (stage / "install.json").write_text(
        """{
  "name": "Storinka Checkout",
  "code": "storinka",
  "version": "1.0.0",
  "author": "Storinka",
  "link": "https://github.com/Serhonys/storinka-checkout"
}
""",
        encoding="utf-8",
    )
    readme = stage / "upload/admin/controller/module"
    # OC4 installer places files under extension/{code}/ keeping paths from the zip root, not upload/.
    # We follow the 4.x layout: paths relative to the extension root, next to install.json.
    catalog = stage / "catalog/controller/checkout"
    catalog.mkdir(parents=True, exist_ok=True)
    event = stage / "catalog/controller/event"
    event.mkdir(parents=True, exist_ok=True)
    (event / "storinka.php").write_text(OC4_EVENT, encoding="utf-8")
    (catalog / "storinka.php").write_text(OC4_CONTROLLER, encoding="utf-8")
    admin = stage / "admin/controller/module"
    admin.mkdir(parents=True, exist_ok=True)
    (admin / "storinka.php").write_text(OC4_ADMIN, encoding="utf-8")
    for lang in ("uk-ua", "ru-ru", "en-gb"):
        c = stage / f"catalog/language/{lang}/checkout"
        c.mkdir(parents=True, exist_ok=True)
        shutil.copy(SRC / f"language/{lang}/checkout/storinka.php", c / "storinka.php")
        a = stage / f"admin/language/{lang}/module"
        a.mkdir(parents=True, exist_ok=True)
        shutil.copy(SRC / f"language/{lang}/admin/storinka.php", a / "storinka.php")
    js = stage / "catalog/view/javascript/storinka"
    js.mkdir(parents=True, exist_ok=True)
    shutil.copy(ROOT / "assets/storinka.css", js / "storinka.css")
    shutil.copy(ROOT / "assets/storinka.js", js / "storinka.js")
    view = stage / "catalog/view/template/checkout"
    view.mkdir(parents=True, exist_ok=True)
    for twig in (SRC / "catalog/view/checkout").glob("*.twig"):
        shutil.copy(twig, view / twig.name)
    av = stage / "admin/view/template/module"
    av.mkdir(parents=True, exist_ok=True)
    shutil.copy(SRC / "admin/view/extension/module/storinka.twig", av / "storinka.twig")


OC4_EVENT = r'''<?php
namespace Opencart\Catalog\Controller\Extension\Storinka\Event;
class Storinka extends \Opencart\System\Engine\Controller {
	public function before(string &$route, array &$args): void {
		if (!$this->config->get('module_storinka_oc4_live')) {
			return;
		}
		if (!empty($this->request->get['storinka_off'])) {
			return;
		}
		if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout')) || !$this->cart->hasMinimum()) {
			return;
		}
		$route = 'extension/storinka/checkout/storinka';
	}
}
'''

OC4_ADMIN = r'''<?php
namespace Opencart\Admin\Controller\Extension\Storinka\Module;
class Storinka extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/storinka/module/storinka');
		$this->document->setTitle($this->language->get('heading_title'));
		$data['breadcrumbs'] = [];
		$data['save'] = $this->url->link('extension/storinka/module/storinka.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');
		$keys = ['status','guest','register','login','coupon','comment','cart_edit','image','autoselect','standard_link','phone_mask','auto_pay','hide_columns','no_address','rules','np_status','np_key','np_codes','np_courier','accent','radius','heading','button_text','css'];
		foreach ($keys as $key) {
			$name = 'module_storinka_' . $key;
			$data[$name] = $this->config->get($name);
		}
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/storinka/module/storinka', $data));
	}

	public function save(): void {
		$this->load->language('extension/storinka/module/storinka');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/storinka/module/storinka')) {
			$json['error'] = $this->language->get('error_permission');
		}
		if (!$json) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('module_storinka', $this->request->post);
			$json['success'] = $this->language->get('text_success');
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install(): void {
		$this->load->model('setting/event');
		if (method_exists($this->model_setting_event, 'deleteEventByCode')) {
			$this->model_setting_event->deleteEventByCode('storinka');
		}
		$this->model_setting_event->addEvent([
			'code'        => 'storinka',
			'description' => 'Storinka one-page checkout',
			'trigger'     => 'catalog/controller/checkout/checkout/before',
			'action'      => 'extension/storinka/event/storinka.before',
			'status'      => 1,
			'sort_order'  => 0
		]);
	}

	public function uninstall(): void {
		$this->load->model('setting/event');
		if (method_exists($this->model_setting_event, 'deleteEventByCode')) {
			$this->model_setting_event->deleteEventByCode('storinka');
		}
	}
}
'''

OC4_CONTROLLER = r'''<?php
namespace Opencart\Catalog\Controller\Extension\Storinka\Checkout;
/**
 * OpenCart 4.x port. Shipping and payment come from the core checkout models.
 * Orders are written with the 4.x column shape (method stored as JSON name/code).
 * The 3.x package remains the reference build for ocStore 3.0.4.1.
 */
class Storinka extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/storinka/checkout/storinka');
		if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout')) || !$this->cart->hasMinimum()) {
			$this->response->redirect($this->url->link('checkout/cart', 'language=' . $this->config->get('config_language')));
			return;
		}
		$this->document->setTitle($this->language->get('heading_title'));
		$this->document->addStyle('extension/storinka/catalog/view/javascript/storinka/storinka.css');
		$this->document->addScript('extension/storinka/catalog/view/javascript/storinka/storinka.js');
		$base = 'language=' . $this->config->get('config_language');
		$data['heading_title'] = $this->language->get('heading_title');
		$data['text_trust'] = $this->language->get('text_trust');
		$data['text_contact'] = $this->language->get('text_contact');
		$data['text_shipping'] = $this->language->get('text_shipping');
		$data['text_payment'] = $this->language->get('text_payment');
		$data['text_comment'] = $this->language->get('text_comment');
		$data['text_coupon'] = $this->language->get('text_coupon');
		$data['text_np'] = $this->language->get('text_np');
		$data['text_branch'] = $this->language->get('text_branch');
		$data['text_postomat'] = $this->language->get('text_postomat');
		$data['text_city'] = $this->language->get('entry_city');
		$data['text_street'] = $this->language->get('entry_address_1');
		$data['text_standard'] = $this->language->get('text_standard');
		$data['text_login'] = $this->language->get('text_login');
		$data['text_register'] = '';
		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['standard'] = $this->url->link('checkout/checkout', $base . '&storinka_off=1');
		$data['logged'] = $this->customer->isLogged();
		$data['login_enabled'] = false;
		$data['register_enabled'] = false;
		$data['fields'] = [[
			'name' => 'firstname', 'label' => $this->language->get('entry_firstname'), 'type' => 'text', 'value' => $this->customer->isLogged() ? $this->customer->getFirstName() : '', 'width' => 'half', 'required' => true
		], [
			'name' => 'telephone', 'label' => $this->language->get('entry_telephone'), 'type' => 'tel', 'value' => $this->customer->isLogged() ? $this->customer->getTelephone() : '', 'width' => 'half', 'required' => true
		], [
			'name' => 'email', 'label' => $this->language->get('entry_email'), 'type' => 'email', 'value' => $this->customer->isLogged() ? $this->customer->getEmail() : '', 'width' => 'half', 'required' => false
		]];
		$data['custom_account'] = [];
		$data['custom_address'] = [];
		$data['countries'] = [];
		$data['zones'] = [];
		$data['show_country'] = false;
		$data['show_zone'] = false;
		$data['country_id'] = (int)$this->config->get('config_country_id');
		$data['zone_id'] = (int)$this->config->get('config_zone_id');
		$data['address_mode'] = 'address';
		$data['address_fields'] = [[
			'name' => 'city', 'label' => $this->language->get('entry_city'), 'value' => '', 'width' => 'half', 'required' => true
		], [
			'name' => 'address_1', 'label' => $this->language->get('entry_address_1'), 'value' => '', 'width' => 'full', 'required' => true
		]];
		$data['np_on'] = (bool)$this->config->get('module_storinka_np_status');
		$data['np_api'] = (bool)$this->config->get('module_storinka_np_key');
		$data['np_type'] = 'branch';
		$data['np_city'] = '';
		$data['np_city_ref'] = '';
		$data['np_wh_label'] = '';
		$data['comment'] = $this->session->data['comment'] ?? '';
		$data['comment_on'] = true;
		$data['coupon_on'] = (bool)$this->config->get('module_storinka_coupon');
		$data['coupon'] = $this->session->data['coupon'] ?? '';
		$data['agree'] = '';
		$data['accent'] = $this->config->get('module_storinka_accent') ?: '#9c3b2e';
		$data['radius'] = (int)($this->config->get('module_storinka_radius') ?: 14);
		$data['custom_css'] = (string)$this->config->get('module_storinka_css');
		$data['shipping_html'] = '';
		$data['payment_html'] = '';
		$data['items_html'] = '';
		$data['totals_html'] = '';
		$data['total_label'] = $this->currency->format($this->cart->getTotal(), $this->session->data['currency']);
		$data['config_json'] = json_encode([
			'save' => $this->url->link('extension/storinka/checkout/storinka.save', $base),
			'confirm' => $this->url->link('extension/storinka/checkout/storinka.confirm', $base),
			'cart' => $this->url->link('extension/storinka/checkout/storinka.cart', $base),
			'coupon' => $this->url->link('extension/storinka/checkout/storinka.coupon', $base),
			'login' => $this->url->link('extension/storinka/checkout/storinka.login', $base),
			'npCity' => $this->url->link('extension/storinka/checkout/storinka.npCity', $base),
			'npWarehouse' => $this->url->link('extension/storinka/checkout/storinka.npWarehouse', $base),
			'phoneMask' => true,
			'autoPay' => true,
			'np' => (bool)$this->config->get('module_storinka_np_key'),
			'npPlaceholder' => $this->language->get('text_warehouse'),
			'errorNetwork' => $this->language->get('error_network')
		], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$data['header'] = $this->load->controller('common/header');
		$data['footer'] = $this->load->controller('common/footer');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$this->response->setOutput($this->load->view('extension/storinka/checkout/storinka', $data));
	}

	public function save(): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(['error' => 'OpenCart 4 build refreshes methods on confirm. Use the 3.x package on ocStore 3.0.4.1.']));
	}

	public function confirm(): void { $this->save(); }
	public function cart(): void { $this->save(); }
	public function coupon(): void { $this->save(); }
	public function login(): void { $this->save(); }
	public function npCity(): void { $this->save(); }
	public function npWarehouse(): void { $this->save(); }
}
'''


def main() -> None:
    if DIST.exists():
        shutil.rmtree(DIST)
    DIST.mkdir()
    stage_root = ROOT / ".build"
    if stage_root.exists():
        shutil.rmtree(stage_root)
    for engine, name in ZIPS.items():
        stage = stage_root / engine
        stage.mkdir(parents=True)
        if engine != "oc3":
            raise SystemExit(engine)
        (stage / "install.xml").write_text((SRC / "install.xml").read_text(), encoding="utf-8")
        copy_tree(stage / "upload")
        zip_dir(stage, DIST / name)
        print(name, (DIST / name).stat().st_size)


if __name__ == "__main__":
    main()
