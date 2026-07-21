<?php
/**
 * MailerLite Configuration — Contact List sync tools, open to any
 * logged-in user. API key management lives on the separate admin-only
 * apiSettings.php page.
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>MailerLite Configuration</h2></div>
        <?php if (Yii::app()->params->isAdmin): ?>
            <p class="text-muted">
                Want to send a one-time email to a list on a schedule? See
                <?php echo CHtml::link('Schedule MailerLite Campaign', array('scheduleCampaign')); ?>.
                Manage the API key/account connection at
                <?php echo CHtml::link('MailerLite API Settings', array('apiSettings')); ?>.
            </p>
        <?php endif; ?>

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

        <div class="panel panel-default" style="max-width: 500px;">
            <div class="panel-body">
                <dl class="ml-status-dl">
                    <dt>MailerLite Connection Status:</dt>
                    <dd>
                        <?php if (empty($status['configured'])): ?>
                            <span class="label label-danger">Not configured</span>
                        <?php elseif (!empty($status['connected'])): ?>
                            <span class="label label-success">Connected</span>
                        <?php else: ?>
                            <span class="label label-danger">Error</span>
                        <?php endif; ?>
                    </dd>
                    <dt>MailerLite Account:</dt>
                    <dd>
                        <?php if (!empty($status['accountName']) || !empty($status['accountEmail'])): ?>
                            <?php echo !empty($status['accountName']) ? CHtml::encode($status['accountName']) : '—'; ?>
                            <?php if (!empty($status['accountEmail'])): ?>
                                (<?php echo CHtml::encode($status['accountEmail']); ?>)
                            <?php endif; ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </dd>
                </dl>
                <?php if (empty($status['configured'])): ?>
                    <p class="text-muted" style="margin: 0;">
                        Not connected yet<?php echo Yii::app()->params->isAdmin ? '' : ' — ask an admin to set it up'; ?>.
                        <?php if (Yii::app()->params->isAdmin): ?>
                            <?php echo CHtml::link('Configure it here', array('apiSettings')); ?>.
                        <?php endif; ?>
                    </p>
                <?php elseif (Yii::app()->params->isAdmin): ?>
                    <?php echo CHtml::link('Manage API key / disconnect', array('apiSettings')); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel panel-default" style="max-width: 700px;">
            <div class="panel-heading">Sync a Contact List to MailerLite</div>
            <div class="panel-body">
                <p class="text-muted">
                    Pushes every Contact in the selected list who has an email address into a
                    MailerLite group named "X2CRM - &lt;list name&gt;" (created automatically the first
                    time). Safe to re-run any time — existing subscribers are updated, not duplicated.
                </p>
                <?php if (!empty($lists)): ?>
                    <?php $form = $this->beginWidget('CActiveForm', array('action' => array('syncList'), 'method' => 'POST')); ?>
                        <div class="form-group">
                            <label for="listId">Contact List</label>
                            <select id="listId" name="listId" class="form-control" style="max-width: 320px;">
                                <?php foreach ($lists as $list): ?>
                                    <option value="<?php echo $list->id; ?>"><?php echo CHtml::encode($list->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label style="font-weight: normal;">
                                <input type="checkbox" name="autoSync" value="1">
                                Keep this list automatically synced from now on
                            </label>
                            <p class="text-muted" style="margin: 4px 0 0;">
                                Re-checks list membership and updates the MailerLite group on its own —
                                you don't need to click "Sync to MailerLite" again. You can turn this on
                                or off later from the table below.
                            </p>
                        </div>
                        <?php echo CHtml::submitButton('Sync to MailerLite', array('class' => 'x2-button highlight')); ?>
                    <?php $this->endWidget(); ?>
                <?php else: ?>
                    <p class="text-muted">
                        No Contact Lists yet — create one at
                        <?php echo CHtml::link('Contact Lists', array('/contacts/contacts/lists')); ?> first.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">Synced Lists</div>
            <div class="panel-body">
                <?php if (!empty($syncedLists)): ?>
                    <div class="table-scroll">
                    <table class="table table-striped table-hover synced-lists-table">
                        <thead>
                            <tr>
                                <th>List</th>
                                <th>MailerLite Group</th>
                                <th>Last Synced</th>
                                <th>Last Count</th>
                                <th>Auto-Sync</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($syncedLists as $row): ?>
                                <tr>
                                    <td>
                                        <?php if ($row['currentName'] !== null): ?>
                                            <?php echo CHtml::encode($row['currentName']); ?>
                                        <?php else: ?>
                                            <span class="text-muted"><?php echo CHtml::encode($row['listName']); ?> (list deleted)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo CHtml::encode($row['groupName']); ?></code></td>
                                    <td><?php echo $row['lastSyncedAt'] ? date('M j, Y g:i A', strtotime($row['lastSyncedAt'])) : '—'; ?></td>
                                    <td><?php echo $row['lastSyncCount'] !== null ? (int) $row['lastSyncCount'] : '—'; ?></td>
                                    <td>
                                        <?php if ($row['autoSync']): ?>
                                            <span class="label label-success">On</span>
                                        <?php else: ?>
                                            <span class="label label-default">Off</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions-cell">
                                        <?php echo CHtml::link($row['autoSync'] ? 'Turn Off Auto-Sync' : 'Turn On Auto-Sync', '#', array(
                                            'class' => 'x2-button' . ($row['autoSync'] ? '' : ' highlight'),
                                            'submit' => array('toggleAutoSync', 'id' => $row['id']),
                                            'csrf' => true,
                                        )); ?>
                                        <?php echo CHtml::link('Remove from MailerLite', '#', array(
                                            'class' => 'x2-button urgent',
                                            'submit' => array('removeSync', 'id' => $row['id']),
                                            'csrf' => true,
                                            'confirm' => 'Remove the MailerLite group "' . CHtml::encode($row['groupName']) . '" and stop syncing "' . CHtml::encode($row['listName']) . '"? Per MailerLite\'s own API docs, this removes the group; whether the subscribers themselves are also deleted isn\'t documented by MailerLite.',
                                        )); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No lists synced yet — use the tool above to sync your first one.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .panel { border: 1px solid #ddd; margin-bottom: 20px; }
    .panel-heading { background-color: #f5f5f5; padding: 15px; border-bottom: 1px solid #ddd; font-weight: 600; }
    .panel-body { padding: 15px; }
    .ml-status-dl { margin: 0 0 12px; overflow: hidden; }
    .ml-status-dl dt { float: left; clear: left; width: 220px; font-weight: 600; }
    .ml-status-dl dd { margin-left: 220px; margin-bottom: 8px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-danger { background-color: #dc3545; }
    .label-default { background-color: #6c757d; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .text-muted { color: #6c757d; }
    .table-scroll { overflow-x: auto; }
    .synced-lists-table { min-width: 900px; border-collapse: separate; border-spacing: 0; }
    .synced-lists-table th,
    .synced-lists-table td { padding: 12px 16px; vertical-align: middle; white-space: nowrap; }
    .synced-lists-table td.actions-cell { display: flex; gap: 8px; white-space: normal; }
    .synced-lists-table td.actions-cell .x2-button { float: none !important; margin: 0 !important; }
</style>
