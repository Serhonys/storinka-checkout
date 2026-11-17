<?php echo $header; ?><?php echo $column_left; ?>
<div id="content">
  <div class="page-header">
    <div class="container-fluid">
      <div class="pull-right">
        <button type="submit" form="form-storinka" class="btn btn-primary"><?php echo $button_save; ?></button>
        <a href="<?php echo $cancel; ?>" class="btn btn-default"><?php echo $button_cancel; ?></a>
      </div>
      <h1><?php echo $heading_title; ?></h1>
    </div>
  </div>
  <div class="container-fluid">
    <?php if ($error_warning) { ?><div class="alert alert-danger"><?php echo $error_warning; ?></div><?php } ?>
    <?php if ($success) { ?><div class="alert alert-success"><?php echo $success; ?></div><?php } ?>
    <form action="<?php echo $action; ?>" method="post" id="form-storinka" class="form-horizontal">
      <ul class="nav nav-tabs">
        <li class="active"><a href="#tab-general" data-toggle="tab"><?php echo $tab_general; ?></a></li>
        <li><a href="#tab-fields" data-toggle="tab"><?php echo $tab_fields; ?></a></li>
        <li><a href="#tab-rules" data-toggle="tab"><?php echo $tab_rules; ?></a></li>
        <li><a href="#tab-np" data-toggle="tab"><?php echo $tab_np; ?></a></li>
        <li><a href="#tab-look" data-toggle="tab"><?php echo $tab_look; ?></a></li>
      </ul>
      <div class="tab-content">
        <div class="tab-pane active" id="tab-general">
          <?php
          $checks = array(
            'status' => $entry_status,
            'replace' => $entry_replace,
            'guest' => $entry_guest,
            'register' => $entry_register,
            'login' => $entry_login,
            'coupon' => $entry_coupon,
            'comment' => $entry_comment,
            'cart_edit' => $entry_cart_edit,
            'image' => $entry_image,
            'autoselect' => $entry_autoselect,
            'standard_link' => $entry_standard,
            'phone_mask' => $entry_phone_mask,
            'auto_pay' => $entry_auto_pay,
            'hide_columns' => $entry_hide_columns
          );
          foreach ($checks as $key => $label) {
            $val = ${'module_storinka_' . $key};
          ?>
          <div class="form-group">
            <label class="col-sm-3 control-label"><?php echo $label; ?></label>
            <div class="col-sm-9">
              <input type="hidden" name="module_storinka_<?php echo $key; ?>" value="0">
              <input type="checkbox" name="module_storinka_<?php echo $key; ?>" value="1" <?php echo $val ? 'checked' : ''; ?>>
            </div>
          </div>
          <?php } ?>
        </div>
        <div class="tab-pane" id="tab-fields">
          <table class="table table-bordered">
            <thead><tr><th><?php echo $column_field; ?></th><th><?php echo $column_status; ?></th><th><?php echo $column_required; ?></th><th><?php echo $column_width; ?></th><th><?php echo $column_sort; ?></th></tr></thead>
            <tbody>
            <?php foreach ($fields as $key => $field) { ?>
              <tr>
                <td><?php echo isset($field_labels[$key]) ? $field_labels[$key] : $key; ?></td>
                <td><input type="hidden" name="module_storinka_field[<?php echo $key; ?>][status]" value="0"><input type="checkbox" name="module_storinka_field[<?php echo $key; ?>][status]" value="1" <?php echo !empty($field['status']) ? 'checked' : ''; ?>></td>
                <td><input type="hidden" name="module_storinka_field[<?php echo $key; ?>][required]" value="0"><input type="checkbox" name="module_storinka_field[<?php echo $key; ?>][required]" value="1" <?php echo !empty($field['required']) ? 'checked' : ''; ?>></td>
                <td>
                  <select name="module_storinka_field[<?php echo $key; ?>][width]" class="form-control">
                    <option value="full" <?php echo $field['width'] == 'full' ? 'selected' : ''; ?>>full</option>
                    <option value="half" <?php echo $field['width'] == 'half' ? 'selected' : ''; ?>>half</option>
                  </select>
                </td>
                <td><input type="number" name="module_storinka_field[<?php echo $key; ?>][sort]" value="<?php echo (int)$field['sort']; ?>" class="form-control"></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
        <div class="tab-pane" id="tab-rules">
          <div class="form-group">
            <label class="col-sm-3 control-label"><?php echo $entry_no_address; ?></label>
            <div class="col-sm-9"><input type="text" name="module_storinka_no_address" value="<?php echo $module_storinka_no_address; ?>" class="form-control"><p class="help-block"><?php echo $help_no_address; ?></p></div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label"><?php echo $entry_rules; ?></label>
            <div class="col-sm-9"><textarea name="module_storinka_rules" rows="8" class="form-control"><?php echo $module_storinka_rules; ?></textarea><p class="help-block"><?php echo $help_rules; ?></p></div>
          </div>
        </div>
        <div class="tab-pane" id="tab-np">
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_np_status; ?></label><div class="col-sm-9"><input type="hidden" name="module_storinka_np_status" value="0"><input type="checkbox" name="module_storinka_np_status" value="1" <?php echo $module_storinka_np_status ? 'checked' : ''; ?>></div></div>
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_np_key; ?></label><div class="col-sm-9"><input type="text" name="module_storinka_np_key" value="<?php echo $module_storinka_np_key; ?>" class="form-control"><p class="help-block"><?php echo $help_np; ?></p></div></div>
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_np_codes; ?></label><div class="col-sm-9"><input type="text" name="module_storinka_np_codes" value="<?php echo $module_storinka_np_codes; ?>" class="form-control"></div></div>
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_np_courier; ?></label><div class="col-sm-9"><input type="text" name="module_storinka_np_courier" value="<?php echo $module_storinka_np_courier; ?>" class="form-control"></div></div>
        </div>
        <div class="tab-pane" id="tab-look">
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_accent; ?></label><div class="col-sm-9"><input type="color" name="module_storinka_accent" value="<?php echo $module_storinka_accent; ?>"></div></div>
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_radius; ?></label><div class="col-sm-9"><input type="number" name="module_storinka_radius" value="<?php echo (int)$module_storinka_radius; ?>" class="form-control" style="max-width:120px"></div></div>
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_heading; ?></label><div class="col-sm-9"><input type="text" name="module_storinka_heading" value="<?php echo $module_storinka_heading; ?>" class="form-control"></div></div>
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_button; ?></label><div class="col-sm-9"><input type="text" name="module_storinka_button_text" value="<?php echo $module_storinka_button_text; ?>" class="form-control"></div></div>
          <div class="form-group"><label class="col-sm-3 control-label"><?php echo $entry_css; ?></label><div class="col-sm-9"><textarea name="module_storinka_css" rows="6" class="form-control"><?php echo $module_storinka_css; ?></textarea></div></div>
        </div>
      </div>
    </form>
  </div>
</div>
<?php echo $footer; ?>
