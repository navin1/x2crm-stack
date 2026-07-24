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
                                'class' => 'btn btn-xs btn-info',
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
                                <?php echo CHtml::submitButton('Turn off', array('class' => 'btn btn-xs btn-default')); ?>
                            <?php else: ?>
                                <span class="label label-default">OFF</span>
                                &nbsp;
                                <?php echo CHtml::submitButton('Turn on', array('class' => 'btn btn-xs btn-default')); ?>
                            <?php endif; ?>
                            <span class="text-muted" style="margin-left: 8px;">When on, this group's members are automatically re-synced from the linked list every few minutes.</span>
                        <?php $this->endWidget(); ?>
                    </dd>
                    <?php endif; ?>
                </dl>

                <div class="form-group" style="margin-top: 10px;">
                    <?php $linkForm = $this->beginWidget('CActiveForm', array('action' => array('linkList'), 'method' => 'POST')); ?>
                        <input type="hidden" name="groupId" value="<?php echo CHtml::encode($groupId); ?>">
                        <label for="linkListId" style="font-weight: normal;">Link to list:</label>
                        <select id="linkListId" name="listId" class="form-control" style="display: inline-block; width: auto;">
                            <option value="">-- None --</option>
                            <?php foreach ($lists as $list): ?>
                                <option value="<?php echo $list->id; ?>" <?php echo ($linkedList && $linkedList->id == $list->id) ? 'selected' : ''; ?>>
                                    <?php echo CHtml::encode($list->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo CHtml::submitButton('Save', array('class' => 'btn btn-sm btn-default')); ?>
                    <?php $this->endWidget(); ?>
                </div>

                <div class="form-group">
                    <?php $notifyForm = $this->beginWidget('CActiveForm', array('action' => array('toggleNotifyNewLead'), 'method' => 'POST')); ?>
                        <input type="hidden" name="groupId" value="<?php echo CHtml::encode($groupId); ?>">
                        <input type="hidden" name="enabled" value="<?php echo $group['notifyOnNewLead'] ? '0' : '1'; ?>">
                        <?php if ($group['notifyOnNewLead']): ?>
                            <span class="label label-success">New-lead notifications: ON</span>
                            &nbsp;
                            <?php echo CHtml::submitButton('Turn off', array('class' => 'btn btn-sm btn-default')); ?>
                        <?php else: ?>
                            <span class="label label-default">New-lead notifications: OFF</span>
                            &nbsp;
                            <?php echo CHtml::submitButton('Turn on', array('class' => 'btn btn-sm btn-default')); ?>
                        <?php endif; ?>
                        <p class="help-block" style="margin: 6px 0 0;">When on, every new lead notification (the same one sent to the assigned pracharak) is also posted into this group, from the WhatsApp number paired to this app. Requires that number to already be a member of this group.</p>
                    <?php $this->endWidget(); ?>
                </div>

                <div class="form-group">
                    <?php $renameForm = $this->beginWidget('CActiveForm', array('action' => array('rename'), 'method' => 'POST')); ?>
                        <input type="hidden" name="groupId" value="<?php echo CHtml::encode($groupId); ?>">
                        <label for="renameGroupName" style="font-weight: normal;">Rename group:</label>
                        <input type="text" id="renameGroupName" name="groupName" class="form-control" style="display: inline-block; width: auto;" value="<?php echo CHtml::encode($group['groupName']); ?>" required>
                        <?php echo CHtml::submitButton('Save', array('class' => 'btn btn-sm btn-default')); ?>
                    <?php $this->endWidget(); ?>
                </div>

                <div style="margin-top: 20px;">
                    <?php echo CHtml::link('Back to Groups', array('index'), array('class' => 'btn btn-default')); ?>
                    <?php echo CHtml::link('Delete Group', '#', array(
                        'class' => 'btn btn-danger',
                        'submit' => array('delete', 'groupId' => $groupId),
                        'csrf' => true,
                        'confirm' => "Delete \"" . CHtml::encode($group['groupName']) . "\"? WhatsApp has no way to delete a group for everyone — this leaves the group (which removes it entirely if wa-hub's account is the only member) and stops tracking it in X2CRM either way.",
                    )); ?>
                </div>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">
                    Members (<?php echo $group['memberCount'] ?? 0; ?>)
                    <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#addMembersModal" style="float: right; margin-top: -2px;">
                        Add Members
                    </button>
                </h3>
            </div>
            <div class="panel-body">
                <?php if (!empty($group['members'])): ?>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Admin</th>
                                <th>Joined</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['members'] as $member): ?>
                                <tr>
                                    <td><?php echo CHtml::encode($member['name']); ?></td>
                                    <td><?php echo CHtml::encode($member['phone']); ?></td>
                                    <td>
                                        <?php if ($member['isAdmin']): ?>
                                            <span class="label label-primary">Admin</span>
                                        <?php else: ?>
                                            <span class="label label-default">Member</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($member['joinedAt'])); ?></td>
                                    <td>
                                        <?php echo CHtml::link(
                                            'Remove',
                                            array('removeMember', 'groupId' => $groupId, 'phone' => $member['phone']),
                                            array(
                                                'class' => 'btn btn-sm btn-danger',
                                                'data-confirm' => 'Remove ' . CHtml::encode($member['name']) . ' from group?'
                                            )
                                        ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">No members found. <?php echo CHtml::link('Add some', '#addMembersModal', array('data-toggle' => 'modal')); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Members Modal -->
<div class="modal fade" id="addMembersModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Add Members to Group</h4>
            </div>
            <?php $form = $this->beginWidget('CActiveForm', array('action' => array('addMembers'), 'method' => 'POST')); ?>
            <div class="modal-body">
                <p>Select contacts to add to this WhatsApp group. Only contacts with phone numbers will be added.</p>

                <div class="form-group">
                    <label>Contacts</label>
                    <input type="hidden" name="groupId" value="<?php echo CHtml::encode($groupId); ?>">
                    <div style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                        <input type="text" id="modalContactFilter" class="form-control" placeholder="Search contacts..." style="max-width: 250px;">
                        <a href="#" id="modalSelectAll">Select all</a>
                        <a href="#" id="modalSelectNone">Select none</a>
                    </div>
                    <div id="contactsContainer" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                        <?php
                        $allContacts = Contacts::model()->findAll(array('limit' => 500));
                        foreach ($allContacts as $contact):
                            if ($contact->phone):
                        ?>
                            <div class="checkbox modal-contact-item" data-name="<?php echo strtolower(CHtml::encode($contact->name)); ?>">
                                <label>
                                    <input type="checkbox" name="contacts[]" value="<?php echo $contact->id; ?>">
                                    <?php echo CHtml::encode($contact->name); ?> (<?php echo CHtml::encode($contact->phone); ?>)
                                </label>
                            </div>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Members</button>
            </div>
            <?php $this->endWidget(); ?>
        </div>
    </div>
</div>

<script>
    document.getElementById('modalContactFilter').addEventListener('keyup', function() {
        var filter = this.value.toLowerCase();
        document.querySelectorAll('.modal-contact-item').forEach(function(item) {
            item.style.display = item.dataset.name.indexOf(filter) > -1 ? '' : 'none';
        });
    });
    document.getElementById('modalSelectAll').addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.modal-contact-item:not([style*="display: none"]) input[type=checkbox]').forEach(function(cb) {
            cb.checked = true;
        });
    });
    document.getElementById('modalSelectNone').addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.modal-contact-item input[type=checkbox]').forEach(function(cb) {
            cb.checked = false;
        });
    });
</script>

<style>
    .dl-horizontal dt {
        font-weight: bold;
        margin-top: 10px;
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
</style>
