<?php
/**
 * MailerLite API Settings (Administration Tools) — admin-only. Split out
 * from the general Configure page so the sync tools there can be opened up
 * to all users without also exposing the API key itself.
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>MailerLite API Settings</h2></div>
        <p class="text-muted">
            Looking for the Contact List sync tools instead? See
            <?php echo CHtml::link('MailerLite Configuration', array('configure')); ?>.
        </p>

        <?php if (Yii::app()->user->hasFlash('success')): ?>
            <div class="alert alert-success">
                <?php echo Yii::app()->user->getFlash('success'); ?>
            </div>
        <?php endif; ?>

        <?php if (Yii::app()->user->hasFlash('error')): ?>
            <div class="alert alert-danger">
                <?php echo Yii::app()->user->getFlash('error'); ?>
            </div>
        <?php endif; ?>

        <div class="panel panel-default" style="max-width: 700px;">
            <div class="panel-heading">Connection Status</div>
            <div class="panel-body">
                <?php if (empty($status['configured'])): ?>
                    <span class="label label-danger">Not configured</span>
                <?php elseif (!empty($status['connected'])): ?>
                    <span class="label label-success">Connected</span>
                    <?php if (!empty($status['keySuffix'])): ?>
                        <span class="text-muted">(key ends in &hellip;<?php echo CHtml::encode($status['keySuffix']); ?>)</span>
                    <?php endif; ?>
                    <?php if (!empty($status['accountName']) || !empty($status['accountEmail'])): ?>
                        <p class="text-muted" style="margin-top: 8px;">
                            Account:
                            <strong><?php echo !empty($status['accountName']) ? CHtml::encode($status['accountName']) : '—'; ?></strong>
                            <?php if (!empty($status['accountEmail'])): ?>
                                (<?php echo CHtml::encode($status['accountEmail']); ?>)
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="label label-danger">Error</span>
                    <?php if (!empty($status['keySuffix'])): ?>
                        <span class="text-muted">(key ends in &hellip;<?php echo CHtml::encode($status['keySuffix']); ?>)</span>
                    <?php endif; ?>
                    <p class="text-muted" style="margin-top: 8px;">
                        <?php echo CHtml::encode(isset($status['error']) ? $status['error'] : 'Could not reach MailerLite.'); ?>
                    </p>
                <?php endif; ?>

                <div style="margin-top: 16px;">
                    <?php $keyForm = $this->beginWidget('CActiveForm', array('action' => array('saveApiKey'), 'method' => 'POST')); ?>
                        <div class="form-group">
                            <label for="apiKey"><?php echo empty($status['configured']) ? 'MailerLite API Key' : 'Update MailerLite API Key'; ?></label>
                            <input type="password" id="apiKey" name="apiKey" class="form-control" style="max-width: 400px; display: inline-block;" placeholder="Paste a new API key to set or change your MailerLite account" autocomplete="off">
                            <?php echo CHtml::submitButton('Save', array('class' => 'x2-button highlight')); ?>
                        </div>
                    <?php $this->endWidget(); ?>
                    <p class="text-muted">
                        From MailerLite: Integrations &gt; API. Takes effect immediately — no restart needed.
                        The saved key is never shown here again, only the status above.
                    </p>
                </div>

                <?php if (!empty($status['configured'])): ?>
                    <div style="margin-top: 12px;">
                        <?php echo CHtml::link('Disconnect', '#', array(
                            'class' => 'x2-button urgent',
                            'submit' => array('disconnect'),
                            'csrf' => true,
                            'confirm' => 'Disconnect MailerLite? Background syncing (list auto-sync, new-contact grouping) will fail quietly until you save a new API key.',
                        )); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .panel { border: 1px solid #ddd; margin-bottom: 20px; }
    .panel-heading { background-color: #f5f5f5; padding: 15px; border-bottom: 1px solid #ddd; font-weight: 600; }
    .panel-body { padding: 15px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-danger { background-color: #dc3545; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .text-muted { color: #6c757d; }
</style>
