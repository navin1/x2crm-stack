<?php
/**
 * List WhatsApp Groups View
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>WhatsApp Groups</h2></div>

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
                <dl class="wa-status-dl">
                    <dt>WhatsApp Connection Status:</dt>
                    <dd>
                        <?php if (!empty($waStatus['connected'])): ?>
                            <span class="label label-success">Connected</span>
                        <?php elseif (!empty($waStatus['connecting'])): ?>
                            <span class="label label-warning">Connecting&hellip;</span>
                        <?php elseif (empty($waStatus)): ?>
                            <span class="label label-default">Unknown (couldn't reach wa-hub)</span>
                        <?php else: ?>
                            <span class="label label-danger">Disconnected</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Connected Phone Number:</dt>
                    <dd>
                        <?php echo !empty($waStatus['phoneNumber']) ? '+' . CHtml::encode($waStatus['phoneNumber']) : '—'; ?>
                    </dd>
                    <dt>Profile Name:</dt>
                    <dd>
                        <?php echo !empty($waStatus['pushName']) ? CHtml::encode($waStatus['pushName']) : '—'; ?>
                    </dd>
                </dl>
                <?php if (Yii::app()->params->isAdmin): ?>
                    <?php echo CHtml::link('Manage connection / re-pair', array('configure')); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="btn-group" style="margin-bottom: 15px; padding-left: 15px;">
            <?php echo CHtml::link('Create Group', array('create'), array('class' => 'x2-button highlight')); ?>
            <?php echo CHtml::link('Sync from WhatsApp', array('sync'), array('class' => 'x2-button blue', 'confirm' => 'Sync all groups from WhatsApp?')); ?>
            <?php echo CHtml::link('Edit New-Lead Message', array('editNotifyTemplate'), array('class' => 'x2-button orange')); ?>
            <?php echo CHtml::link('Web Form Notifications', array('webFormNotify'), array('class' => 'x2-button grey')); ?>
            <?php echo CHtml::link('Lead Forms', array('/marketing/marketing/webleadForm'), array('class' => 'x2-button purple')); ?>
        </div>

        <?php if (!empty($groups)): ?>
            <div class="panel panel-default">
                <div class="panel-body">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Group Name</th>
                        <th>Members</th>
                        <th>Linked Contact List</th>
                        <th>Synced</th>
                        <th>Actions</th>
                        <th>Last Synced</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group): ?>
                        <tr>
                            <td>
                                <strong><?php echo CHtml::encode($group['groupName']); ?></strong>
                                <?php if ($group['subject'] && $group['subject'] !== $group['groupName']): ?>
                                    <br><small><?php echo CHtml::encode($group['subject']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo isset($group['memberCount']) ? $group['memberCount'] : 0; ?></td>
                            <td>
                                <?php if (!empty($group['listId']) && isset($listNames[$group['listId']])): ?>
                                    <?php echo CHtml::encode($listNames[$group['listId']]); ?>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($group['isSynced']): ?>
                                    <span class="label label-success">Yes</span>
                                <?php else: ?>
                                    <span class="label label-warning">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <?php echo CHtml::link('View', array('view', 'groupId' => $group['groupId']), array('class' => 'x2-button blue')); ?>
                                <?php echo CHtml::link('Delete', '#', array(
                                    'class' => 'x2-button urgent',
                                    'submit' => array('delete', 'groupId' => $group['groupId']),
                                    'csrf' => true,
                                    'confirm' => "Delete \"" . CHtml::encode($group['groupName']) . "\"? WhatsApp has no way to delete a group for everyone — this leaves the group and stops tracking it in X2CRM.",
                                )); ?>
                            </td>
                            <td>
                                <?php if (!empty($group['lastSyncedAt'])): ?>
                                    <?php echo date('M j, Y g:i A', strtotime($group['lastSyncedAt'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">Never</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y g:i A', strtotime($group['createdAt'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                No WhatsApp groups found. <?php echo CHtml::link('Create one', array('create')); ?> or <?php echo CHtml::link('sync from WhatsApp', array('sync')); ?>.
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    /* The theme's own .x2-button rule floats it right by default (fine
       inside its usual single-button contexts); neutralized here so
       multiple buttons in a row lay out left-to-right instead. */
    .btn-group, .actions-cell {
        display: flex;
        gap: 5px;
    }
    .btn-group .x2-button, .actions-cell .x2-button {
        float: none !important;
        margin: 0 !important;
    }
    /* No "orange" variant exists in the app's stock button classes (only
       .highlight/green and .blue) — added here to match .blue's shape. */
    .x2-button.orange {
        background-color: #e8830f;
        border-color: #c56d0a;
        color: #fff;
    }
    .x2-button.orange:hover {
        background-color: #cc7208;
    }
    .x2-button.orange:active, .x2-button.orange.clicked {
        background-color: #a85e07;
        box-shadow: inset 0 1px 1px 0 #8a4d05;
    }
    /* Grey/purple variants, same shape as .orange above — used to
       visually distinguish the Web Form Notifications / Lead Forms
       buttons from the WhatsApp-specific actions to their left. */
    .x2-button.grey {
        background-color: #6c757d;
        border-color: #5a6268;
        color: #fff;
    }
    .x2-button.grey:hover {
        background-color: #5a6268;
    }
    .x2-button.grey:active, .x2-button.grey.clicked {
        background-color: #494f54;
        box-shadow: inset 0 1px 1px 0 #3a3f43;
    }
    .x2-button.purple {
        background-color: #6f42c1;
        border-color: #59339d;
        color: #fff;
    }
    .x2-button.purple:hover {
        background-color: #59339d;
    }
    .x2-button.purple:active, .x2-button.purple.clicked {
        background-color: #472980;
        box-shadow: inset 0 1px 1px 0 #38206b;
    }
    /* Wasn't defined anywhere on this page before — the "Synced" column's
       Yes/No labels were rendering as plain unstyled text with no colored
       pill background, same underlying gap as the button issue fixed
       earlier on this page. */
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-warning { background-color: #e0a800; }
    .label-danger { background-color: #dc3545; }
    .label-default { background-color: #6c757d; }
    .panel { border: 1px solid #ddd; margin: 0 15px 20px; }
    .panel-body { padding: 15px; }
    .wa-status-dl { margin: 0 0 12px; overflow: hidden; }
    .wa-status-dl dt { float: left; clear: left; width: 220px; font-weight: 600; }
    .wa-status-dl dd { margin-left: 220px; margin-bottom: 8px; }
    .alert {
        padding: 12px 15px;
        margin: 0 15px 20px;
        border: 1px solid transparent;
        border-radius: 4px;
    }
    .alert-success {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }
    .alert-danger {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }
    .alert-info {
        color: #0c5460;
        background-color: #d1ecf1;
        border-color: #bee5eb;
    }
</style>
