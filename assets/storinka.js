/* Storinka checkout front. Depends on jQuery, which OpenCart already ships. */
(function ($) {
  'use strict';

  function cfg() {
    return window.StorinkaConfig || {};
  }

  function mount(el, html) {
    el.html(html);
    el.find('script').each(function () {
      var old = this;
      var s = document.createElement('script');
      if (old.src) {
        s.src = old.src;
      } else {
        s.text = old.text || old.textContent || '';
      }
      old.parentNode.replaceChild(s, old);
    });
  }

  function showErrors(json) {
    $('.sk-field').removeClass('has-error');
    $('.sk-err').remove();
    $('#sk-alert').remove();
    if (!json) return;
    var errors = json.errors || {};
    $.each(errors, function (key, message) {
      var $field = $('.sk-field[data-field="' + key + '"]');
      if ($field.length) {
        $field.addClass('has-error');
        $field.append('<p class="sk-err"></p>');
        $field.find('.sk-err').last().text(message);
      }
    });
    if (json.error) {
      $('.sk-main').prepend('<div id="sk-alert" class="sk-alert"></div>');
      $('#sk-alert').text(json.error);
    }
    var $first = $('.has-error').first();
    if ($first.length && $first.offset()) {
      $('html, body').animate({ scrollTop: $first.offset().top - 24 }, 180);
    }
  }

  function applyState(json) {
    if (!json) return;
    if (json.redirect) {
      window.location = json.redirect;
      return;
    }
    if (json.html) {
      if (typeof json.html.shipping !== 'undefined') {
        $('#sk-shipping-box').html(json.html.shipping);
      }
      if (typeof json.html.payment !== 'undefined') {
        $('#sk-payment-box').html(json.html.payment);
      }
      if (typeof json.html.items !== 'undefined') {
        $('#sk-items').html(json.html.items);
      }
      if (typeof json.html.totals !== 'undefined') {
        $('#sk-totals').html(json.html.totals);
      }
      if (typeof json.html.address !== 'undefined') {
        $('#sk-address').html(json.html.address);
      }
    }
    if (json.total_label && $('#sk-submit-sum').length) {
      $('#sk-submit-sum').text(json.total_label);
    }
    showErrors(json);
    var mode = json.address_mode || '';
    $('#sk-address').toggle(mode === 'address' || mode === 'np_courier');
    $('#sk-np').toggle(mode === 'np' || mode === 'np_courier');
    $('#sk-np-warehouse').toggle(mode === 'np');
    $('#sk-np-street').toggle(mode === 'np_courier');
  }

  function post(url, extra) {
    var data = $('#sk-form').serialize();
    if (extra) data += '&' + extra;
    return $.ajax({
      url: url,
      type: 'post',
      data: data,
      dataType: 'json'
    });
  }

  var timer = null;
  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      post(cfg().save).done(applyState);
    }, 650);
  }

  function digitsPhone(value) {
    var d = (value || '').replace(/\D/g, '');
    if (d.indexOf('380') === 0) d = d.substring(3);
    if (d.indexOf('80') === 0) d = d.substring(2);
    if (d.indexOf('0') === 0) d = d.substring(1);
    return d.substring(0, 9);
  }

  function formatPhone(value) {
    var d = digitsPhone(value);
    var out = '+380';
    if (d.length > 0) out += ' ' + d.substring(0, 2);
    if (d.length > 2) out += ' ' + d.substring(2, 5);
    if (d.length > 5) out += ' ' + d.substring(5, 7);
    if (d.length > 7) out += ' ' + d.substring(7, 9);
    return out;
  }

  $(function () {
    if (!$('#sk-form').length) return;

    $('#sk-form').on('submit', function (e) {
      e.preventDefault();
      var $btn = $('#sk-submit');
      $btn.prop('disabled', true);
      post(cfg().confirm).done(function (json) {
        if (json.redirect) {
          window.location = json.redirect;
          return;
        }
        if (json.error || (json.errors && !$.isEmptyObject(json.errors))) {
          showErrors(json);
          $btn.prop('disabled', false);
          return;
        }
        if (json.payment) {
          var $box = $('#sk-paymount');
          $box.show();
          mount($box, json.payment);
          var auto = shouldAuto($box.get(0));
          var $confirm = $box.find('#button-confirm');
          var $form = $box.find('form').first();
          if (auto && $confirm.length) {
            $confirm.trigger('click');
          } else if (auto && $form.length) {
            $form.get(0).submit();
          } else {
            $btn.prop('disabled', false);
            if ($box.offset()) {
              $('html, body').animate({ scrollTop: $box.offset().top - 20 }, 180);
            }
          }
        } else {
          $btn.prop('disabled', false);
        }
      }).fail(function () {
        $btn.prop('disabled', false);
        showErrors({ error: cfg().errorNetwork || 'Network error' });
      });
    });

    $('#sk-form').on('change', 'input, select, textarea', function () {
      var name = $(this).attr('name') || '';
      if (name === 'comment' || name === 'agree' || name === 'password') return;
      schedule();
    });

    $('#sk-form').on('click', 'input[name="shipping_method"], input[name="payment_method"]', function () {
      schedule();
    });

    if (cfg().phoneMask) {
      $('#sk-form').on('input', 'input[name="telephone"]', function () {
        var next = formatPhone(this.value);
        if (this.value !== next) this.value = next;
      });
    }

    $(document).on('click', '[data-sk-qty]', function () {
      var cartId = $(this).attr('data-cart');
      var dir = parseInt($(this).attr('data-sk-qty'), 10);
      var $qty = $(this).siblings('[data-sk-qval]');
      var current = parseInt($qty.text(), 10) || 1;
      var next = Math.max(0, current + dir);
      post(cfg().cart, 'cart_id=' + encodeURIComponent(cartId) + '&quantity=' + next).done(applyState);
    });

    $(document).on('click', '[data-sk-remove]', function () {
      var cartId = $(this).attr('data-cart');
      post(cfg().cart, 'cart_id=' + encodeURIComponent(cartId) + '&quantity=0').done(applyState);
    });

    $('#sk-coupon-btn').on('click', function () {
      post(cfg().coupon, 'coupon=' + encodeURIComponent($('#sk-coupon').val() || '')).done(function (json) {
        applyState(json);
        if (json.coupon_error) {
          showErrors({ errors: { coupon: json.coupon_error } });
        }
      });
    });

    var npTimer = null;
    $('#sk-form').on('input', '#sk-np-city', function () {
      var q = $(this).val();
      clearTimeout(npTimer);
      if (!cfg().np || q.length < 2) {
        $('#sk-np-cities').empty().hide();
        return;
      }
      npTimer = setTimeout(function () {
        $.getJSON(cfg().npCity, { q: q }, function (json) {
          var $list = $('#sk-np-cities').empty();
          if (!json.cities || !json.cities.length) {
            $list.hide();
            return;
          }
          $.each(json.cities, function (_, city) {
            var $b = $('<button type="button"></button>').text(city.label);
            $b.attr('data-ref', city.ref).attr('data-name', city.name);
            $list.append($b);
          });
          $list.show();
        });
      }, 280);
    });

    $(document).on('click', '#sk-np-cities button', function () {
      $('#sk-np-city').val($(this).attr('data-name'));
      $('#sk-np-city-ref').val($(this).attr('data-ref'));
      $('#sk-np-cities').empty().hide();
      loadWarehouses();
      schedule();
    });

    $('#sk-form').on('click', '[data-np-type]', function () {
      $('[data-np-type]').removeClass('is-on');
      $(this).addClass('is-on');
      $('#sk-np-type').val($(this).attr('data-np-type'));
      loadWarehouses();
    });

    function loadWarehouses() {
      var ref = $('#sk-np-city-ref').val();
      if (!ref || !cfg().np) return;
      $.getJSON(cfg().npWarehouse, { ref: ref, type: $('#sk-np-type').val() || 'branch' }, function (json) {
        var $sel = $('#sk-np-wh');
        if (!$sel.length) return;
        var current = $sel.val();
        $sel.empty();
        $sel.append($('<option></option>').val('').text(cfg().npPlaceholder || '—'));
        $.each(json.warehouses || [], function (_, wh) {
          $sel.append($('<option></option>').val(wh.ref).text(wh.label).attr('data-label', wh.label));
        });
        if (current) $sel.val(current);
      });
    }

    $('#sk-form').on('change', '#sk-np-wh', function () {
      var label = $(this).find('option:selected').text();
      $('#sk-np-wh-label').val($(this).val() ? label : '');
      schedule();
    });

    $('#sk-login-btn').on('click', function () {
      $.ajax({
        url: cfg().login,
        type: 'post',
        dataType: 'json',
        data: {
          email: $('#sk-login-email').val(),
          password: $('#sk-login-password').val()
        }
      }).done(function (json) {
        if (json.redirect) window.location = json.redirect;
        else showErrors(json);
      });
    });
  });

  function shouldAuto(el) {
    if (!el || !cfg().autoPay) return false;
    var inputs = el.querySelectorAll('input, select, textarea');
    for (var i = 0; i < inputs.length; i++) {
      var t = (inputs[i].type || '').toLowerCase();
      if (t !== 'hidden' && t !== 'submit' && t !== 'button') return false;
    }
    return true;
  }
})(window.jQuery);
