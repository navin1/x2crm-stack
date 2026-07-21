<?php
/**
 * Lead Forms registry (Administration Tools)
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Lead Forms</h2></div>

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

        <div class="panel panel-default" style="max-width: 700px;">
            <div class="panel-heading">Create a Pracharak's Personal Lead Form</div>
            <div class="panel-body">
                <p class="text-muted">
                    Generates a dedicated, personally-styled form for one pracharak. Every prospect
                    who submits it is sent straight to that pracharak over WhatsApp, usually within
                    about a minute of submitting. Pracharaks come from the Contacts in the
                    "Pracharak" Contact List — add or remove someone there to change who's
                    selectable here.
                </p>
                <?php $spForm = $this->beginWidget('CActiveForm', array('action' => array('createPracharakForm'), 'method' => 'POST')); ?>
                    <div class="form-group">
                        <label for="spFormName">Form Name</label>
                        <input type="text" id="spFormName" name="formName" class="form-control" placeholder="e.g., John's Lead Form" required>
                    </div>
                    <div class="form-group">
                        <label for="spPracharakId">Pracharak</label>
                        <select id="spPracharakId" name="pracharakId" class="form-control" style="max-width: 300px;">
                            <?php foreach ($pracharaks as $sp): ?>
                                <option value="<?php echo $sp['id']; ?>"><?php echo CHtml::encode($sp['name']); ?> (<?php echo CHtml::encode($sp['phone']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fields to Include</label>
                        <p class="text-muted" style="margin: 0 0 8px;">First name, last name, and email are always included.</p>
                        <?php foreach ($fieldCatalog as $key => $meta): ?>
                            <label style="display: inline-block; width: 45%; font-weight: normal; margin-bottom: 6px;">
                                <input type="checkbox" name="fields[]" value="<?php echo CHtml::encode($key); ?>">
                                <?php echo CHtml::encode($meta['label']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-group">
                        <label for="spDeactivateAt">Auto-deactivate at (optional)</label>
                        <input type="datetime-local" id="spDeactivateAt" name="deactivateAt" class="form-control" style="max-width: 260px;">
                    </div>
                    <?php echo CHtml::submitButton('Create Form & Notify Me', array('class' => 'btn btn-primary')); ?>
                <?php $this->endWidget(); ?>
            </div>
        </div>

        <div class="panel panel-default" style="max-width: 700px;">
            <div class="panel-heading">Register an Existing Form URL</div>
            <div class="panel-body">
                <p class="text-muted">
                    For a form you already built by hand (like the generic leadform.html) rather
                    than a generated pracharak form. Sends you a WhatsApp message with its URL,
                    a scannable QR code, and a tinyurl.com short link.
                </p>
                <?php $form = $this->beginWidget('CActiveForm', array('action' => array('registerLeadForm'), 'method' => 'POST')); ?>
                    <div class="form-group">
                        <label for="lfName">Form Name</label>
                        <input type="text" id="lfName" name="name" class="form-control" placeholder="e.g., Spring Promo Landing Page" required>
                    </div>
                    <div class="form-group">
                        <label for="lfUrl">Form URL</label>
                        <input type="url" id="lfUrl" name="url" class="form-control" placeholder="https://your-domain.com/leadform.html" required>
                    </div>
                    <div class="form-group">
                        <label for="lfWebFormId">Linked Web Form ID (optional)</label>
                        <input type="number" id="lfWebFormId" name="webFormId" class="form-control" placeholder="x2_web_forms.id, if this page posts to one" style="max-width: 200px;">
                    </div>
                    <div class="form-group">
                        <label for="lfDeactivateAt">Auto-deactivate at (optional)</label>
                        <input type="datetime-local" id="lfDeactivateAt" name="deactivateAt" class="form-control" style="max-width: 260px;">
                        <small class="text-muted">Leave blank to keep it active until you deactivate it manually.</small>
                    </div>
                    <?php echo CHtml::submitButton('Register & Notify Me', array('class' => 'btn btn-primary')); ?>
                <?php $this->endWidget(); ?>
            </div>
        </div>

        <?php if (!empty($forms)): ?>
            <div class="table-scroll">
            <table class="table table-striped table-hover lead-forms-table">
                <thead>
                    <tr>
                        <th>QR</th>
                        <th>Name</th>
                        <th>Pracharak</th>
                        <th>URL</th>
                        <th>Short Link</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Notified</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forms as $f):
                        $isScheduledPast = !empty($f['deactivateAt']) && $f['deactivateAt'] <= time();
                        $isActive = !empty($f['active']) && !$isScheduledPast;
                    ?>
                        <tr>
                            <td>
                                <img src="<?php echo CHtml::encode($this->createUrl('qrForUrl', array('url' => $f['url']))); ?>"
                                     alt="QR" style="width: 60px; height: 60px;">
                            </td>
                            <td><strong><?php echo CHtml::encode($f['name']); ?></strong></td>
                            <td>
                                <?php if (!empty($f['pracharakId']) && isset($pracharaksById[$f['pracharakId']])): ?>
                                    <?php echo CHtml::encode($pracharaksById[$f['pracharakId']]['name']); ?>
                                    <br><small class="text-muted"><?php echo CHtml::encode($pracharaksById[$f['pracharakId']]['phone']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo CHtml::encode($f['url']); ?>" target="_blank" rel="noopener"><?php echo CHtml::encode($f['url']); ?></a></td>
                            <td>
                                <?php if (!empty($f['tinyUrl'])): ?>
                                    <a href="<?php echo CHtml::encode($f['tinyUrl']); ?>" target="_blank" rel="noopener"><?php echo CHtml::encode($f['tinyUrl']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$isActive): ?>
                                    <span class="label label-danger"><?php echo $isScheduledPast ? 'Expired' : 'Deactivated'; ?></span>
                                <?php elseif (!empty($f['deactivateAt'])): ?>
                                    <span class="label label-warning">Active until <?php echo date('M j, g:i A', $f['deactivateAt']); ?></span>
                                <?php else: ?>
                                    <span class="label label-success">Active</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y', $f['createDate']); ?></td>
                            <td>
                                <?php if (!empty($f['notifiedAt'])): ?>
                                    <span class="label label-success">Yes</span>
                                <?php else: ?>
                                    <span class="label label-warning">Not yet</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <div class="actions-row">
                                    <?php echo CHtml::link('Notify Again', '#', array(
                                        'class' => 'btn btn-sm btn-default',
                                        'submit' => array('notifyLeadForm', 'id' => $f['id']),
                                        'csrf' => true,
                                        'confirm' => 'Re-send the WhatsApp notification for "' . CHtml::encode($f['name']) . '"?',
                                    )); ?>
                                    <?php if ($isActive): ?>
                                        <?php echo CHtml::link('Deactivate Now', '#', array(
                                            'class' => 'btn btn-sm btn-danger',
                                            'submit' => array('deactivateLeadForm', 'id' => $f['id']),
                                            'csrf' => true,
                                            'confirm' => 'Deactivate "' . CHtml::encode($f['name']) . '" immediately? Visitors will see a "no longer accepting submissions" message.',
                                        )); ?>
                                    <?php else: ?>
                                        <?php echo CHtml::link('Reactivate', '#', array(
                                            'class' => 'btn btn-sm btn-success',
                                            'submit' => array('reactivateLeadForm', 'id' => $f['id']),
                                            'csrf' => true,
                                            'confirm' => 'Reactivate "' . CHtml::encode($f['name']) . '"?',
                                        )); ?>
                                    <?php endif; ?>
                                    <?php echo CHtml::link('Delete', '#', array(
                                        'class' => 'btn btn-sm btn-default',
                                        'submit' => array('deleteLeadForm', 'id' => $f['id']),
                                        'csrf' => true,
                                        'confirm' => 'Permanently delete "' . CHtml::encode($f['name']) . '"? This removes it from this list and, if it was a generated pracharak form, deletes its page and stops the URL from working. This cannot be undone.',
                                    )); ?>
                                </div>
                                <?php $schedForm = $this->beginWidget('CActiveForm', array(
                                    'action' => array('scheduleLeadFormDeactivation', 'id' => $f['id']),
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
                No lead forms registered yet.
            </div>
        <?php endif; ?>
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
    .label-warning { background-color: #e0a800; }
    .label-danger { background-color: #dc3545; }
    .btn-success { background-color: #28a745; color: #fff; border-color: #28a745; }
    .btn-xs { padding: 3px 8px; font-size: 12px; }

    .table-scroll { overflow-x: auto; margin-bottom: 20px; }
    .lead-forms-table { min-width: 1150px; border-collapse: separate; border-spacing: 0; }
    .lead-forms-table th,
    .lead-forms-table td {
        padding: 14px 16px;
        vertical-align: top;
        white-space: nowrap;
    }
    .lead-forms-table td.actions-cell { white-space: normal; }
    .actions-cell { min-width: 260px; }
    .actions-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
    .actions-row .btn { margin: 0; }
    .schedule-row { display: flex; align-items: center; gap: 8px; }
    .schedule-input { font-size: 12px; padding: 5px 8px; border: 1px solid #ccc; border-radius: 4px; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .alert-info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; }
    .text-muted { color: #6c757d; }
</style>
