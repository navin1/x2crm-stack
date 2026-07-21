<?php
/**
 * Web Form Notifications (Administration Tools) — assign or change which
 * pracharak gets a WhatsApp message for each native Web Lead Form's
 * (marketing/webleadForm) submissions, and manage the form itself:
 * activate/deactivate now, schedule a future deactivation, or delete it.
 */
$deleteWebFormUrl = $this->createUrl('/marketing/marketing/deleteWebForm');
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Web Form Notifications</h2></div>
        <p class="text-muted">
            Every form built at <a href="<?php echo CHtml::encode($this->createUrl('/marketing/marketing/webleadForm')); ?>">Marketing &gt; Web Lead Form</a>
            is listed below with its iframe embed URL. Pick a pracharak for a form and every
            submission through that form's iframe gets WhatsApped to them, usually within about a
            minute. Leave it set to "&mdash; Off &mdash;" to disable notifications for that form.
            You can change the assigned pracharak, activation state, or schedule at any time.
            The pracharak choices come from the Contacts in a Contact List named exactly
            <strong>Pracharak</strong> — add or remove someone there to change who's assignable
            here.
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

        <div id="webform-delete-flash"></div>

        <?php if (!$hasPracharakList): ?>
            <div class="alert alert-danger">
                <strong>No "Pracharak" contact list found.</strong> The pracharak dropdown below
                will stay empty until one exists. Create a Contact List named exactly
                <strong>Pracharak</strong> and add the people who should receive WhatsApp
                notifications to it (as Contacts, each with a phone number) —
                <a href="<?php echo CHtml::encode(Yii::app()->createUrl('/contacts/contacts/lists')); ?>">go to Contact Lists &rarr;</a>
            </div>
        <?php elseif (empty($pracharaks)): ?>
            <div class="alert alert-danger">
                <strong>The "Pracharak" list exists but has no assignable contacts.</strong>
                Add Contacts to it, and make sure each one has a phone number —
                <a href="<?php echo CHtml::encode(Yii::app()->createUrl('/contacts/contacts/lists')); ?>">go to Contact Lists &rarr;</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($forms)): ?>
            <div class="table-scroll">
            <table class="table table-striped table-hover webform-notify-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Iframe URL</th>
                        <th>Status</th>
                        <th>Notify Pracharak</th>
                        <th>Manage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forms as $f):
                        $iframeUrl = $hostInfo . '/index.php/contacts/contacts/weblead?webFormId=' . $f['id'];
                        $current = isset($notifyMap[$f['id']]) ? $notifyMap[$f['id']] : '';
                        $isScheduledPast = !empty($f['deactivateAt']) && $f['deactivateAt'] <= time();
                        $isActive = !empty($f['active']) && !$isScheduledPast;
                    ?>
                        <tr id="webform-row-<?php echo (int) $f['id']; ?>">
                            <td><strong><?php echo CHtml::encode($f['name']); ?></strong></td>
                            <td><code><?php echo CHtml::encode($iframeUrl); ?></code></td>
                            <td>
                                <?php if (!$isActive): ?>
                                    <span class="label label-danger"><?php echo $isScheduledPast ? 'Expired' : 'Deactivated'; ?></span>
                                <?php elseif (!empty($f['deactivateAt'])): ?>
                                    <span class="label label-warning">Active until <?php echo date('M j, g:i A', $f['deactivateAt']); ?></span>
                                <?php else: ?>
                                    <span class="label label-success">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $rowForm = $this->beginWidget('CActiveForm', array(
                                    'action' => array('saveWebFormNotify'),
                                    'method' => 'POST',
                                    'htmlOptions' => array('class' => 'notify-row'),
                                )); ?>
                                    <input type="hidden" name="webFormId" value="<?php echo (int) $f['id']; ?>">
                                    <select name="pracharakId" class="form-control" style="max-width: 240px; display: inline-block;">
                                        <option value=""<?php echo $current === '' ? ' selected' : ''; ?>>&mdash; Off &mdash;</option>
                                        <?php foreach ($pracharaks as $sp): ?>
                                            <option value="<?php echo (int) $sp['id']; ?>"<?php echo (string) $current === (string) $sp['id'] ? ' selected' : ''; ?>>
                                                <?php echo CHtml::encode($sp['name']); ?> (<?php echo CHtml::encode($sp['phone']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php echo CHtml::submitButton('Save', array('class' => 'btn btn-sm btn-primary')); ?>
                                <?php $this->endWidget(); ?>
                            </td>
                            <td class="actions-cell">
                                <div class="actions-row">
                                    <?php if ($isActive): ?>
                                        <?php echo CHtml::link('Deactivate Now', '#', array(
                                            'class' => 'btn btn-sm btn-danger',
                                            'submit' => array('deactivateWebForm', 'id' => $f['id']),
                                            'csrf' => true,
                                            'confirm' => 'Deactivate "' . CHtml::encode($f['name']) . '" immediately? Its iframe will stop accepting submissions wherever it is embedded.',
                                        )); ?>
                                    <?php else: ?>
                                        <?php echo CHtml::link('Reactivate', '#', array(
                                            'class' => 'btn btn-sm btn-success',
                                            'submit' => array('reactivateWebForm', 'id' => $f['id']),
                                            'csrf' => true,
                                            'confirm' => 'Reactivate "' . CHtml::encode($f['name']) . '"?',
                                        )); ?>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-default webform-delete-btn"
                                            data-id="<?php echo (int) $f['id']; ?>"
                                            data-name="<?php echo CHtml::encode($f['name']); ?>">Delete</button>
                                </div>
                                <?php $schedForm = $this->beginWidget('CActiveForm', array(
                                    'action' => array('scheduleWebFormDeactivation', 'id' => $f['id']),
                                    'method' => 'POST',
                                    'htmlOptions' => array('class' => 'schedule-row'),
                                )); ?>
                                    <input type="datetime-local" name="deactivateAt"
                                           class="schedule-input"
                                           value="<?php echo !empty($f['deactivateAt']) ? date('Y-m-d\TH:i', $f['deactivateAt']) : ''; ?>">
                                    <?php echo CHtml::submitButton('Set Schedule', array('class' => 'btn btn-xs btn-default')); ?>
                                <?php $this->endWidget(); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info" style="max-width: 900px;">
                No Web Lead Forms yet — build one at Marketing &gt; Web Lead Form first.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    // Reuses X2CRM's own native form-deletion endpoint (the one the
    // Web Lead Form designer's own JS calls) rather than re-implementing
    // delete — it returns JSON [success, message].
    var deleteUrl = <?php echo CJSON::encode($deleteWebFormUrl); ?>;
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
                    var flash = document.getElementById('webform-delete-flash');
                    if (data[0]) {
                        var row = document.getElementById('webform-row-' + id);
                        if (row) row.remove();
                        flash.innerHTML = '<div class="alert alert-success">' + data[1] + '</div>';
                    } else {
                        flash.innerHTML = '<div class="alert alert-danger">' + data[1] + '</div>';
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
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-warning { background-color: #e0a800; }
    .label-danger { background-color: #dc3545; }
    .btn-success { background-color: #28a745; color: #fff; border-color: #28a745; }
    .btn-xs { padding: 3px 8px; font-size: 12px; }
    .table-scroll { overflow-x: auto; margin-bottom: 20px; }
    .webform-notify-table { min-width: 1150px; border-collapse: separate; border-spacing: 0; }
    .webform-notify-table th,
    .webform-notify-table td { padding: 14px 16px; vertical-align: top; white-space: nowrap; }
    .webform-notify-table td.actions-cell { white-space: normal; }
    .actions-cell { min-width: 260px; }
    .actions-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
    .actions-row .btn { margin: 0; }
    .notify-row { display: flex; align-items: center; gap: 8px; margin: 0; }
    .schedule-row { display: flex; align-items: center; gap: 8px; }
    .schedule-input { font-size: 12px; padding: 5px 8px; border: 1px solid #ccc; border-radius: 4px; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .alert-info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; }
    .text-muted { color: #6c757d; }
</style>
