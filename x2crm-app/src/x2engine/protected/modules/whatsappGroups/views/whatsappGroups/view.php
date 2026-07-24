<?php
/**
 * View WhatsApp Group Details
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2><?php echo CHtml::encode($group['groupName']); ?></h2></div>

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

        <div class="panel panel-default" style="margin-bottom: 20px;">
            <div class="panel-heading">Group Information</div>
            <div class="panel-body">
                <dl class="dl-horizontal">
                    <dt>Group ID:</dt>
                    <dd><code><?php echo CHtml::encode($group['groupId']); ?></code></dd>
                    
                    <dt>Subject:</dt>
                    <dd><?php echo CHtml::encode($group['subject'] ?: 'N/A'); ?></dd>
                    
                    <dt>Members:</dt>
                    <dd><?php echo $group['memberCount'] ?? 0; ?></dd>
                    
                    <dt>Synced:</dt>
                    <dd>
                        <?php if ($group['isSynced']): ?>
                            <span class="label label-success">Yes</span>
                        <?php else: ?>
                            <span class="label label-warning">No</span>
                        <?php endif; ?>
                    </dd>
                    
                    <dt>Last Synced:</dt>
                    <dd><?php echo $group['lastSyncedAt'] ? date('M j, Y H:i', strtotime($group['lastSyncedAt'])) : 'Never'; ?></dd>
                    
                    <dt>Created:</dt>
                    <dd><?php echo date('M j, Y H:i', strtotime($group['createdAt'])); ?></dd>

                    <dt>Linked List:</dt>
                    <dd>
                        <?php if ($linkedList): ?>
                            <?php echo CHtml::link(CHtml::encode($linkedList->name), array('/contacts/contacts/list', 'id' => $linkedList->id)); ?>
                            &nbsp;
                            <?php echo CHtml::link('Sync Now', array('syncMembers', 'groupId' => $groupId), array(
                                'class' => 'x2-button blue',
                                'data-confirm' => 'Add/remove WhatsApp members to match this list\'s current contacts?',
                            )); ?>
                        <?php else: ?>
                            <span class="text-muted">None</span>
                        <?php endif; ?>
                    </dd>

                    <?php if ($linkedList): ?>
                    <dt>Auto-sync:</dt>
                    <dd>
                        <?php $autoSyncForm = $this->beginWidget('CActiveForm', array('action' => array('toggleAutoSync'), 'method' => 'POST')); ?>
                            <input type="hidden" name="groupId" value="<?php echo CHtml::encode($groupId); ?>">
                            <input type="hidden" name="enabled" value="<?php echo !empty($group['autoSync']) ? '0' : '1'; ?>">
                            <?php if (!empty($group['autoSync'])): ?>
                                <span class="label label-success">ON</span>
                                &nbsp;
                                <?php echo CHtml::submitButton('Turn off', array('class' => 'x2-button')); ?>
                            <?php else: ?>
                                <span class="label label-default">OFF</span>
                                &nbsp;
                                <?php echo CHtml::submitButton('Turn on', array('class' => 'x2-button')); ?>
                            <?php endif; ?>
                            <span class="text-muted" style="margin-left: 8px;">When on, this group's members are automatically re-synced from the linked list every few minutes.</span>
                        <?php $this->endWidget(); ?>
                    </dd>
                    <?php endif; ?>

                    <dt>New-lead notifications:</dt>
                    <dd>
                        <?php $notifyForm = $this->beginWidget('CActiveForm', array('action' => array('toggleNotifyNewLead'), 'method' => 'POST')); ?>
                            <input type="hidden" name="groupId" value="<?php echo CHtml::encode($groupId); ?>">
                            <input type="hidden" name="enabled" value="<?php echo $group['notifyOnNewLead'] ? '0' : '1'; ?>">
                            <?php if ($group['notifyOnNewLead']): ?>
                                <span class="label label-success">ON</span>
                                &nbsp;
                                <?php echo CHtml::submitButton('Turn off', array('class' => 'x2-button')); ?>
                            <?php else: ?>
                                <span class="label label-default">OFF</span>
                                &nbsp;
                                <?php echo CHtml::submitButton('Turn on', array('class' => 'x2-button')); ?>
                            <?php endif; ?>
                            <span class="text-muted" style="margin-left: 8px;">When on, every new lead notification (the same one sent to the assigned pracharak) is also posted into this group, from the WhatsApp number paired to this app. Requires that number to already be a member of this group.</span>
                        <?php $this->endWidget(); ?>
                    </dd>

                    <dt>Link to list:</dt>
                    <dd>
                        <?php $linkForm = $this->beginWidget('CActiveForm', array('action' => array('linkList'), 'method' => 'POST')); ?>
                            <input type="hidden" name="groupId" value="<?php echo CHtml::encode($groupId); ?>">
                            <select id="linkListId" name="listId" class="form-control" style="display: inline-block; width: auto;">
                                <option value="">-- None --</option>
                                <?php foreach ($lists as $list): ?>
                                    <option value="<?php echo $list->id; ?>" <?php echo ($linkedList && $linkedList->id == $list->id) ? 'selected' : ''; ?>>
                                        <?php echo CHtml::encode($list->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php echo CHtml::submitButton('Save', array('class' => 'x2-button')); ?>
                        <?php $this->endWidget(); ?>
                    </dd>

                    <dt>Rename group:</dt>
                    <dd>
                        <?php $renameForm = $this->beginWidget('CActiveForm', array('action' => array('rename'), 'method' => 'POST')); ?>
                            <input type="hidden" name="groupId" value="<?php echo CHtml::encode($groupId); ?>">
                            <input type="text" id="renameGroupName" name="groupName" class="form-control" style="display: inline-block; width: auto;" value="<?php echo CHtml::encode($group['groupName']); ?>" required>
                            <?php echo CHtml::submitButton('Save', array('class' => 'x2-button')); ?>
                        <?php $this->endWidget(); ?>
                    </dd>
                </dl>

                <div style="margin-top: 20px;">
                    <?php echo CHtml::link('Back to Groups', array('index'), array('class' => 'x2-button')); ?>
                    <?php echo CHtml::link('Delete Group', '#', array(
                        'class' => 'x2-button urgent',
                        'submit' => array('delete', 'groupId' => $groupId),
                        'csrf' => true,
                        'confirm' => "Delete \"" . CHtml::encode($group['groupName']) . "\"? WhatsApp has no way to delete a group for everyone — this leaves the group (which removes it entirely if wa-hub's account is the only member) and stops tracking it in X2CRM either way.",
                    )); ?>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
    /* dt/dd are block-level by default — without this, "label: value" pairs
       just stack vertically instead of sitting on one line. (This is
       Bootstrap's own dl-horizontal recipe; only the font-weight/margin
       half of it was carried over here before, not the actual layout.) */
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
    .panel {
        border: 1px solid #ddd;
        margin-bottom: 20px;
    }
    .panel-heading {
        background-color: #f5f5f5;
        padding: 15px;
        border-bottom: 1px solid #ddd;
    }
    .panel-body {
        padding: 15px;
    }
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
    /* Not defined natively anywhere in the app's own theme (confirmed) —
       same gap as the Synced Yes/No labels on the groups list page, fixed
       there the same way. */
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-warning { background-color: #e0a800; }
    .label-default { background-color: #6c757d; }
    /* x2-button has no built-in spacing for sitting next to another
       button/link inline the way this page uses them (Bootstrap's .btn had
       its own margin/float baked in, which these replaced) */
    .x2-button {
        float: none !important;
        display: inline-block !important;
        vertical-align: middle;
        margin: 0 4px 4px 0 !important;
    }
</style>
