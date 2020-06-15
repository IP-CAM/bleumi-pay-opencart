<?= $header; ?><?= $column_left; ?>
<div id="content">
    <div class="page-header">
        <div class="container-fluid">
            <div class="pull-right">
                <button type="submit" form="form-bleumipay" data-toggle="tooltip" title="<?= $button_save; ?>" class="btn btn-primary"><i class="fa fa-save"></i></button>
                <a href="<?= $url_cancel; ?>" data-toggle="tooltip" title="<?= $button_cancel; ?>" class="btn btn-default"><i class="fa fa-reply"></i></a>
            </div>
            <h1><?= $heading_title; ?></h1>
            <ul class="breadcrumb">
                <?php foreach ($breadcrumbs as $breadcrumb) { ?>
                <li><a href="<?= $breadcrumb['href']; ?>"><?= $breadcrumb['text']; ?></a></li>
                <?php } ?>
            </ul>
        </div>
    </div>
    <div class="container-fluid">
        <?php if (isset($error_warning)) { ?>
        <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= $error_warning; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php } ?>
        <?php if (isset($success) && ! empty($success)) { ?>
        <div class="alert alert-success"><i class="fa fa-check-circle"></i> <?= $success; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php } ?>
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-pencil"></i> <?= $label_edit; ?></h3>
            </div>
            <div class="panel-body">
                <form action="<?= $url_action; ?>" method="post" enctype="multipart/form-data" id="form-bleumipay" class="form-horizontal">
                    <input type="hidden" name="action" value="save">
                    <ul class="nav nav-tabs">
                        <li class="active"><a href="#tab-settings" data-toggle="tab"><?= $tab_settings; ?></a></li>
                        <li><a href="#tab-status" data-toggle="tab"><?= $tab_order_status; ?></a></li>
                        <li><a href="#tab-log" data-toggle="tab"><?= $tab_log; ?></a></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane active" id="tab-settings">

                            <div class="form-group">
                                <label class="col-sm-2 control-label" for="input-status">
                                <span data-toggle="tooltip" title="Use this option to enable or disable AtomicPay plugin"><?= $label_enabled; ?></span>
                                </label>
                                <div class="col-sm-10">
                                    <select name="bleumipay_status" id="input-status" class="form-control">
                                        <option value="1" <?php if ($value_enabled == 1) { ?> selected="selected" <?php } ?>><?= $text_enabled; ?></option>
                                        <option value="0" <?php if ($value_enabled == 0) { ?> selected="selected" <?php } ?>><?= $text_disabled; ?></option>
                                    </select>
                                    <?php if (isset($error_enabled)) { ?>
                                    <div class="text-danger"><?= $error_enabled; ?></div>
                                    <?php } ?>
                                </div>
                            </div>


                            <div class="form-group">
                                <label class="col-sm-2 control-label" for="input-payment-api-key">
                                <span data-toggle="tooltip" title="This is your unique Merchant API Key that can be obtained at your Bleumipay Merchant Account under API Integration. Sign up at https://pay.bleumi.com/app/"><?= $label_payment_api_key; ?></span>
                                </label>
                                <div class="col-sm-10">
                                    <input type="text" name="bleumipay_payment_api_key" id="input-payment-api-key" value="<?= $value_payment_api_key; ?>" class="form-control" />
                                    <?php if (isset($error_payment_api_key)) { ?>
                                    <div class="text-danger"><?= $error_payment_api_key; ?></div>
                                    <?php } ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-2 control-label" for="input-debug">
                                <span data-toggle="tooltip" title="Log BleumiPay plugin events for debugging and troubleshooting"><?= $label_debugging; ?></span>
                                </label>
                                <div class="col-sm-10">
                                    <select name="bleumipay_logging" id="input-debugging" class="form-control">
                                        <option value="1" <?php if ($value_debugging == 1) { ?> selected="selected" <?php } ?>><?= $text_enabled; ?></option>
                                        <option value="0" <?php if ($value_debugging == 0) { ?> selected="selected" <?php } ?>><?= $text_disabled; ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane" id="tab-status">	
                            <div class="form-group">	
                                <label class="col-sm-2 control-label">	
                                <span data-toggle="tooltip" title="The invoice has been pending. Awaiting network confirmation status."><?= $label_pending_status; ?></span>	
                                </label>	
                                <div class="col-sm-10">	
                                    <select name="bleumipay_pending_status" class="form-control">	
                                        <?php foreach ($order_statuses as $order_status): ?>	
                                        <?php $selected = ($order_status['order_status_id'] == $value_pending_status) ? 'selected' : ''; ?>	
                                        <option value="<?php echo $order_status['order_status_id']; ?>" <?php echo $selected; ?>>	
                                        <?php echo $order_status['name']; ?>	
                                        </option>	
                                        <?php endforeach; ?>	
                                    </select>	
                                </div>	
                            </div>	
                            <div class="form-group">	
                                <label class="col-sm-2 control-label">	
                                <span data-toggle="tooltip" title="The invoice has been failed."><?= $label_failed_status; ?></span>	
                                </label>	
                                <div class="col-sm-10">	
                                    <select name="bleumipay_failed_status" class="form-control">	
                                        <?php foreach ($order_statuses as $order_status): ?>	
                                        <?php $selected = ($order_status['order_status_id'] == $value_failed_status) ? 'selected' : ''; ?>	
                                        <option value="<?php echo $order_status['order_status_id']; ?>" <?php echo $selected; ?>>	
                                        <?php echo $order_status['name']; ?>	
                                        </option>	
                                        <?php endforeach; ?>	
                                    </select>	
                                </div>	
                            </div>
                             <div class="form-group">	
                                <label class="col-sm-2 control-label">	
                                <span data-toggle="tooltip" title="The invoice has been awaiting payment confirmation."><?= $label_awaiting_payment_status; ?></span>	
                                </label>	
                                <div class="col-sm-10">	
                                    <select name="bleumipay_awaiting_payment_status" class="form-control">	
                                        <?php foreach ($order_statuses as $order_status): ?>	
                                        <?php $selected = ($order_status['order_status_id'] == $value_awaiting_payment_status) ? 'selected' : ''; ?>	
                                        <option value="<?php echo $order_status['order_status_id']; ?>" <?php echo $selected; ?>>	
                                        <?php echo $order_status['name']; ?>	
                                        </option>	
                                        <?php endforeach; ?>	
                                    </select>	
                                </div>	
                            </div>
                            <div class="form-group">	
                                <label class="col-sm-2 control-label">	
                                <span data-toggle="tooltip" title="The invoice is cancelled. Awaiting network confirmation status. Please contact customer on refund matters."><?= $label_canceled_status; ?></span>	
                                </label>	
                                <div class="col-sm-10">	
                                    <select name="bleumipay_canceled_status" class="form-control">	
                                        <?php foreach ($order_statuses as $order_status): ?>	
                                        <?php $selected = ($order_status['order_status_id'] == $value_canceled_status) ? 'selected' : ''; ?>	
                                        <option value="<?php echo $order_status['order_status_id']; ?>" <?php echo $selected; ?>>	
                                        <?php echo $order_status['name']; ?>	
                                        </option>	
                                        <?php endforeach; ?>	
                                    </select>	
                                </div>	
                            </div>
                            <div class="form-group">	
                                <label class="col-sm-2 control-label">	
                                <span data-toggle="tooltip" title="The invoice is Completed. Please kindly contact your customer."><?= $label_completed_status; ?></span>	
                                </label>	
                                <div class="col-sm-10">	
                                    <select name="bleumipay_completed_status" class="form-control">	
                                        <?php foreach ($order_statuses as $order_status): ?>	
                                        <?php $selected = ($order_status['order_status_id'] == $value_completed_status) ? 'selected' : ''; ?>	
                                        <option value="<?php echo $order_status['order_status_id']; ?>" <?php echo $selected; ?>>	
                                        <?php echo $order_status['name']; ?>	
                                        </option>	
                                        <?php endforeach; ?>	
                                    </select>	
                                </div>	
                            </div>
                            <div class="form-group">	
                                <label class="col-sm-2 control-label">	
                                <span data-toggle="tooltip" title="The invoice has been Multi Token Payment for this order. The payment was not confirmed by the network. Do not process this order."><?= $label_multi_token_status; ?></span>	
                                </label>	
                                <div class="col-sm-10">	
                                    <select name="bleumipay_multi_token_status" class="form-control">	
                                        <?php foreach ($order_statuses as $order_status): ?>	
                                        <?php $selected = ($order_status['order_status_id'] == $value_multi_token_status) ? 'selected' : ''; ?>	
                                        <option value="<?php echo $order_status['order_status_id']; ?>" <?php echo $selected; ?>>	
                                        <?php echo $order_status['name']; ?>	
                                        </option>	
                                        <?php endforeach; ?>	
                                    </select>	
                                </div>	
                            </div>
                            <div class="form-group">	
                                <label class="col-sm-2 control-label">	
                                <span data-toggle="tooltip" title="The invoice payment has been processing for this order."><?= $label_processing_status; ?></span>	
                                </label>	
                                <div class="col-sm-10">	
                                    <select name="bleumipay_processing_status" class="form-control">	
                                        <?php foreach ($order_statuses as $order_status): ?>	
                                        <?php $selected = ($order_status['order_status_id'] == $value_processing_status) ? 'selected' : ''; ?>	
                                        <option value="<?php echo $order_status['order_status_id']; ?>" <?php echo $selected; ?>>	
                                        <?php echo $order_status['name']; ?>	
                                        </option>	
                                        <?php endforeach; ?>	
                                    </select>	
                                </div>	
                            </div>	
                        </div>
                                              
                        <div class="tab-pane" id="tab-log">
                            <pre><?= $log; ?></pre>
                            <div class="text-right">
                                <a href="<?= $url_clear; ?>" class="btn btn-danger"><i class="fa fa-eraser"></i> <?= $button_clear; ?></a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $footer; ?>
