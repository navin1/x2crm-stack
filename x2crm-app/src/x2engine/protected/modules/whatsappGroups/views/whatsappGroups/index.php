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

        <div class="btn-group" style="margin-bottom: 15px;">
            <?php echo CHtml::link('Create Group', array('create'), array('class' => 'x2-button highlight')); ?>
            <?php echo CHtml::link('Sync from WhatsApp', array('sync'), array('class' => 'x2-button blue', 'confirm' => 'Sync all groups from WhatsApp?')); ?>
        </div>

        <?php if (!empty($groups)): ?>
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Group Name</th>
                        <th>Members</th>
                        <th>Linked List</th>
                        <th>Synced</th>
                        <th>Created</th>
                        <th>Actions</th>
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
                            <td><?php echo date('M j, Y', strtotime($group['createdAt'])); ?></td>
                            <td class="actions-cell">
                                <?php echo CHtml::link('View', array('view', 'groupId' => $group['groupId']), array('class' => 'x2-button')); ?>
                                <?php echo CHtml::link('Delete', '#', array(
                                    'class' => 'x2-button urgent',
                                    'submit' => array('delete', 'groupId' => $group['groupId']),
                                    'csrf' => true,
                                    'confirm' => "Delete \"" . CHtml::encode($group['groupName']) . "\"? WhatsApp has no way to delete a group for everyone — this leaves the group and stops tracking it in X2CRM.",
                                )); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
    /* Wasn't defined anywhere on this page before — the "Synced" column's
       Yes/No labels were rendering as plain unstyled text with no colored
       pill background, same underlying gap as the button issue fixed
       earlier on this page. */
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-warning { background-color: #e0a800; }
    .label-danger { background-color: #dc3545; }
    .label-default { background-color: #6c757d; }
    .panel { border: 1px solid #ddd; margin-bottom: 20px; }
    .panel-body { padding: 15px; }
    .wa-status-dl { margin: 0 0 12px; overflow: hidden; }
    .wa-status-dl dt { float: left; clear: left; width: 220px; font-weight: 600; }
    .wa-status-dl dd { margin-left: 220px; margin-bottom: 8px; }
    .alert {
        padding: 12px 15px;
        margin-bottom: 20px;
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
