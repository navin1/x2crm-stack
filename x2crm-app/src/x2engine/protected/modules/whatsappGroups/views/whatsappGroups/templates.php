<?php
/**
 * Message Templates — a small reusable library of canned WhatsApp
 * messages (with an optional image/PDF attachment) that any of the
 * three Send Message tools can pick from. See
 * WhatsappGroupsController::actionTemplates() for why this is one flat
 * list shared across Individual/Group/Broadcast rather than per-tool.
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Message Templates</h2></div>

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

        <div class="panel panel-default">
            <div class="panel-heading">New Template</div>
            <div class="panel-body">
                <?php $form = $this->beginWidget('CActiveForm', array(
                    'action' => array('templates'), 'method' => 'POST',
                    'htmlOptions' => array('enctype' => 'multipart/form-data'),
                )); ?>

                    <div class="form-group">
                        <label for="name">Template Name</label>
                        <input type="text" id="name" name="name" class="form-control" style="max-width: 400px;" required>
                    </div>

                    <div class="form-group">
                        <label for="body">Message</label>
                        <textarea id="body" name="body" class="form-control" rows="6" required></textarea>
                        <p class="text-muted" style="margin-top: 4px;">
                            Optional placeholders — resolved differently depending on where the template is
                            used: a real Contact's name for Individual and Broadcast sends, or "everyone" for
                            a WhatsApp Group post:
                            <code>{{firstName}}</code>, <code>{{lastName}}</code>, <code>{{fullName}}</code>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="attachment">Image or PDF Attachment (optional)</label>
                        <input type="file" id="attachment" name="attachment" accept="image/*,application/pdf">
                        <p class="text-muted" style="margin-top: 4px;">
                            Sent inline (image as a photo, PDF as a document) — directly visible in the chat,
                            not a plain file link.
                        </p>
                    </div>

                    <?php echo CHtml::submitButton('Create Template', array('class' => 'x2-button highlight')); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>

        <?php if (!empty($templates)): ?>
            <div class="panel panel-default">
                <div class="panel-body">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Message</th>
                                <th>Attachment</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $t):
                                $preview = mb_strlen($t['body']) > 80 ? mb_substr($t['body'], 0, 80) . '…' : $t['body'];
                            ?>
                                <tr>
                                    <td><strong><?php echo CHtml::encode($t['name']); ?></strong></td>
                                    <td><?php echo CHtml::encode($preview); ?></td>
                                    <td>
                                        <?php if ($t['attachmentKind'] === 'image'): ?>
                                            <span class="label label-success">Image</span>
                                            <span class="text-muted"><?php echo CHtml::encode($t['attachmentFileName']); ?></span>
                                        <?php elseif ($t['attachmentKind'] === 'document'): ?>
                                            <span class="label label-warning">PDF</span>
                                            <span class="text-muted"><?php echo CHtml::encode($t['attachmentFileName']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo !empty($t['updatedAt']) ? date('M j, Y', strtotime($t['updatedAt'])) : '—'; ?></td>
                                    <td class="actions-cell">
                                        <?php echo CHtml::link('Edit', array('editTemplate', 'id' => $t['id']), array('class' => 'x2-button blue')); ?>
                                        <?php echo CHtml::link('Delete', array('deleteTemplate', 'id' => $t['id']), array(
                                            'class' => 'x2-button urgent',
                                            'submit' => array('deleteTemplate', 'id' => $t['id']),
                                            'confirm' => 'Delete template "' . CHtml::encode($t['name']) . '"? This cannot be undone.',
                                        )); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info" style="max-width: 900px;">
                No templates yet — create one above to reuse it from the Send Message page.
            </div>
        <?php endif; ?>

        <div class="send-message-footer">
            <?php echo CHtml::link('Back to Send Message', array('sendMessage'), array('class' => 'x2-button')); ?>
        </div>
    </div>
</div>

<style>
    #x2-layout-content { padding: 0 20px; }
    .panel { border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06); }
    .panel-heading { padding: 14px 18px; border-bottom: 1px solid rgba(0, 0, 0, 0.08); font-weight: 700; font-size: 15px; letter-spacing: 0.2px; }
    .panel-body { padding: 18px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .alert-info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; }
    .text-muted { color: #6c757d; }
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-warning { background-color: #e0a800; }
    .actions-cell { display: flex; gap: 8px; align-items: center; }
    .actions-cell .x2-button { float: none !important; margin: 0 !important; }
    .send-message-footer { margin-top: 4px; }
</style>
