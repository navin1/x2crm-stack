<?php
/**
 * Web Form Notifications (Administration Tools) — compact list of every
 * native Web Lead Form with a read-only notification summary. Click
 * "View" for one form's full detail (iframe URL, short link, QR code,
 * status/schedule management, and the pracharak + WhatsApp-group
 * notification editor) — mirrors how WhatsApp Groups itself splits its
 * own index()/view() pages.
 */
$deleteWebFormUrl = $this->createUrl('/marketing/marketing/deleteWebForm');
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Web Form Notifications</h2></div>

        <div class="panel panel-default">
            <div class="panel-body">
                <p class="text-muted" style="margin: 0;">
                    Every form built at <a href="<?php echo CHtml::encode($this->createUrl('/marketing/marketing/webleadForm')); ?>">Marketing &gt; Web Lead Form</a>
                    is listed below. Click <strong>View</strong> on a form to see its iframe URL, short
                    link, QR code, and to manage its pracharak and WhatsApp group notifications.
                </p>
            </div>
        </div>

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

        <?php if (!empty($forms)): ?>
            <div class="panel panel-default">
                <div class="panel-body">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Notifications</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $f):
                                $isScheduledPast = !empty($f['deactivateAt']) && $f['deactivateAt'] <= time();
                                $isActive = !empty($f['active']) && !$isScheduledPast;
                                $hasPracharak = isset($notifyMap[$f['id']]) && $notifyMap[$f['id']] !== '';
                                $groupCount = isset($groupNotifyMap[$f['id']]) ? count($groupNotifyMap[$f['id']]) : 0;
                            ?>
                                <tr id="webform-row-<?php echo (int) $f['id']; ?>">
                                    <td><strong><?php echo CHtml::encode($f['name']); ?></strong></td>
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
                                        Pracharak:
                                        <span class="label <?php echo $hasPracharak ? 'label-success' : 'label-default'; ?>">
                                            <?php echo $hasPracharak ? 'On' : 'Off'; ?>
                                        </span>
                                        &nbsp;
                                        Groups:
                                        <?php if ($groupCount > 0): ?>
                                            <span class="label label-success"><?php echo $groupCount; ?> picked</span>
                                        <?php else: ?>
                                            <span class="text-muted">default pool</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions-cell">
                                        <?php echo CHtml::link('View', array('webFormNotifyView', 'webFormId' => $f['id']), array('class' => 'x2-button')); ?>
                                        <button type="button" class="btn btn-sm btn-default webform-delete-btn"
                                                data-id="<?php echo (int) $f['id']; ?>"
                                                data-name="<?php echo CHtml::encode($f['name']); ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
    .panel { border: 1px solid #ddd; margin-bottom: 20px; }
    .panel-body { padding: 15px; }
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-warning { background-color: #e0a800; }
    .label-danger { background-color: #dc3545; }
    .label-default { background-color: #6c757d; }
    .actions-cell { display: flex; gap: 8px; align-items: center; }
    .actions-cell .x2-button { float: none !important; margin: 0 !important; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .alert-info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; }
    .text-muted { color: #6c757d; }
</style>
