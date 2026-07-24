<?php
/**
 * One Web Lead Form's full detail — iframe URL, short link, QR code,
 * status/schedule management, and the pracharak + WhatsApp-group
 * notification editor. Styled directly on whatsappGroups/view.php (one
 * WhatsApp group's own detail page) for a consistent look and feel.
 */
$deleteWebFormUrl = $this->createUrl('/marketing/marketing/deleteWebForm');
$isScheduledPast = !empty($form['deactivateAt']) && $form['deactivateAt'] <= time();
$isActive = !empty($form['active']) && !$isScheduledPast;
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2><?php echo CHtml::encode($form['name']); ?></h2></div>

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

        <div id="webform-delete-flash"></div>

        <div class="panel panel-default" style="margin-bottom: 20px;">
            <div class="panel-heading">Web Lead Form Details</div>
            <div class="panel-body">
                <dl class="dl-horizontal">
                    <dt>Iframe URL:</dt>
                    <dd><code><?php echo CHtml::encode($iframeUrl); ?></code></dd>

                    <dt>Live Preview:</dt>
                    <dd>
                        <iframe src="<?php echo CHtml::encode($iframeUrl); ?>" frameborder="0"
                                allowtransparency="true" scrolling="auto"
                                style="width: 100%; max-width: 420px; height: 480px; border: 1px solid #ddd;"></iframe>
                    </dd>

                    <dt>Short Link:</dt>
                    <dd>
                        <?php if (!empty($form['tinyUrl'])): ?>
                            <a href="<?php echo CHtml::encode($form['tinyUrl']); ?>" target="_blank" rel="noopener"><?php echo CHtml::encode($form['tinyUrl']); ?></a>
                        <?php else: ?>
                            <span class="text-muted">Unavailable (tinyurl.com request failed or is unreachable)</span>
                        <?php endif; ?>
                    </dd>

                    <dt>QR Code:</dt>
                    <dd>
                        <img src="<?php echo CHtml::encode($this->createUrl('qrForUrl', array('url' => $iframeUrl))); ?>"
                             alt="QR code for iframe URL" style="width: 160px; height: 160px;">
                    </dd>

                    <dt>Status:</dt>
                    <dd>
                        <?php if (!$isActive): ?>
                            <span class="label label-danger"><?php echo $isScheduledPast ? 'Expired' : 'Deactivated'; ?></span>
                            &nbsp;
                            <?php echo CHtml::link('Reactivate', '#', array(
                                'class' => 'x2-button',
                                'submit' => array('reactivateWebForm', 'id' => $form['id']),
                                'csrf' => true,
                                'confirm' => 'Reactivate "' . CHtml::encode($form['name']) . '"?',
                            )); ?>
                        <?php else: ?>
                            <span class="label label-success"><?php echo !empty($form['deactivateAt']) ? 'Active until ' . date('M j, g:i A', $form['deactivateAt']) : 'Active'; ?></span>
                            &nbsp;
                            <?php echo CHtml::link('Deactivate Now', '#', array(
                                'class' => 'x2-button',
                                'submit' => array('deactivateWebForm', 'id' => $form['id']),
                                'csrf' => true,
                                'confirm' => 'Deactivate "' . CHtml::encode($form['name']) . '" immediately? Its iframe will stop accepting submissions wherever it is embedded.',
                            )); ?>
                        <?php endif; ?>
                    </dd>

                    <dt>Scheduled deactivation:</dt>
                    <dd>
                        <?php $schedForm = $this->beginWidget('CActiveForm', array(
                            'action' => array('scheduleWebFormDeactivation', 'id' => $form['id']),
                            'method' => 'POST',
                        )); ?>
                            <input type="datetime-local" name="deactivateAt"
                                   class="form-control" style="display: inline-block; width: auto;"
                                   value="<?php echo !empty($form['deactivateAt']) ? date('Y-m-d\TH:i', $form['deactivateAt']) : ''; ?>">
                            <?php echo CHtml::submitButton('Set Schedule', array('class' => 'x2-button')); ?>
                        <?php $this->endWidget(); ?>
                        <div class="text-muted" style="margin-top: 4px;">Leave blank and save to clear a scheduled deactivation.</div>
                    </dd>

                    <dt>Notify Pracharak &amp; Groups:</dt>
                    <dd>
                        <?php $notifyForm = $this->beginWidget('CActiveForm', array(
                            'action' => array('saveWebFormNotify'),
                            'method' => 'POST',
                        )); ?>
                            <input type="hidden" name="webFormId" value="<?php echo (int) $form['id']; ?>">
                            <div style="margin-bottom: 10px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Pracharak</label>
                                <select name="pracharakId" class="form-control" style="max-width: 300px; display: inline-block;">
                                    <option value=""<?php echo $currentPracharak === '' ? ' selected' : ''; ?>>&mdash; Off &mdash;</option>
                                    <?php foreach ($pracharaks as $sp): ?>
                                        <option value="<?php echo (int) $sp['id']; ?>"<?php echo (string) $currentPracharak === (string) $sp['id'] ? ' selected' : ''; ?>>
                                            <?php echo CHtml::encode($sp['name']); ?> (<?php echo CHtml::encode($sp['phone']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$hasPracharakList): ?>
                                    <div class="text-muted" style="margin-top: 4px;">
                                        No "Pracharak" contact list found — create a Contact List named exactly
                                        <strong>Pracharak</strong> and add people with phone numbers to it to populate this.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 4px;">WhatsApp Groups</label>
                                <select name="groupIds[]" multiple class="form-control" style="max-width: 300px; height: 100px;">
                                    <?php foreach ($groups as $g): ?>
                                        <option value="<?php echo CHtml::encode($g['groupId']); ?>"
                                            <?php echo in_array($g['groupId'], $currentGroups, true) ? ' selected' : ''; ?>>
                                            <?php echo CHtml::encode($g['groupName']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="text-muted" style="margin-top: 4px;">
                                    If none are picked, this form's leads fall back to every group with its own
                                    "New-lead notifications" toggle on (set from that group's own page).
                                </div>
                            </div>
                            <?php echo CHtml::submitButton('Save', array('class' => 'x2-button highlight')); ?>
                        <?php $this->endWidget(); ?>
                    </dd>
                </dl>

                <div style="margin-top: 20px;">
                    <?php echo CHtml::link('Back to Web Form Notifications', array('webFormNotify'), array('class' => 'x2-button')); ?>
                    <button type="button" class="x2-button urgent webform-delete-btn"
                            data-id="<?php echo (int) $form['id']; ?>"
                            data-name="<?php echo CHtml::encode($form['name']); ?>">Delete</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var deleteUrl = <?php echo CJSON::encode($deleteWebFormUrl); ?>;
    var listUrl = <?php echo CJSON::encode($this->createUrl('webFormNotify')); ?>;
    document.querySelectorAll('.webform-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            var name = btn.getAttribute('data-name');
            if (!confirm('Delete "' + name + '" permanently? This removes the form and its iframe stops working everywhere it is embedded. This cannot be undone.')) {
                return;
            }
            fetch(deleteUrl + '?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data[0]) {
                        window.location = listUrl;
                    } else {
                        document.getElementById('webform-delete-flash').innerHTML =
                            '<div class="alert alert-danger">' + data[1] + '</div>';
                    }
                })
                .catch(function () {
                    document.getElementById('webform-delete-flash').innerHTML =
                        '<div class="alert alert-danger">Delete request failed.</div>';
                });
        });
    });
})();
</script>

<style>
    /* dt/dd are block-level by default — without this, "label: value" pairs
       just stack vertically instead of sitting on one line. (Same
       Bootstrap-derived dl-horizontal recipe as whatsappGroups/view.php.) */
    .dl-horizontal dt {
        float: left;
        width: 160px;
        text-align: right;
        clear: left;
        font-weight: bold;
        margin-top: 10px;
        padding-right: 10px;
        box-sizing: border-box;
    }
    .dl-horizontal dd {
        margin-left: 170px;
        margin-top: 10px;
        min-height: 1px;
    }
    .panel { border: 1px solid #ddd; margin-bottom: 20px; }
    .panel-heading { background-color: #f5f5f5; padding: 15px; border-bottom: 1px solid #ddd; font-weight: 600; }
    .panel-body { padding: 15px; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-warning { background-color: #e0a800; }
    .label-danger { background-color: #dc3545; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .text-muted { color: #6c757d; }
    /* x2-button has no built-in spacing for sitting next to another
       button/link inline the way this page uses them. */
    .x2-button {
        float: none !important;
        display: inline-block !important;
        vertical-align: middle;
        margin: 0 4px 4px 0 !important;
    }
</style>
