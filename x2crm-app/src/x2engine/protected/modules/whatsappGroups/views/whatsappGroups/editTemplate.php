<?php
/**
 * Edit an existing Message Template, including replacing or removing
 * its attachment. See templates.php for the list/create page.
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Edit Template: <?php echo CHtml::encode($template['name']); ?></h2></div>

        <?php if (Yii::app()->user->hasFlash('error')): ?>
            <div class="alert alert-danger">
                <?php echo Yii::app()->user->getFlash('error'); ?>
            </div>
        <?php endif; ?>

        <div class="panel panel-default">
            <div class="panel-body">
                <?php $form = $this->beginWidget('CActiveForm', array(
                    'action' => array('editTemplate', 'id' => $template['id']), 'method' => 'POST',
                    'htmlOptions' => array('enctype' => 'multipart/form-data'),
                )); ?>

                    <div class="form-group">
                        <label for="name">Template Name</label>
                        <input type="text" id="name" name="name" class="form-control" style="max-width: 400px;"
                               value="<?php echo CHtml::encode($template['name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="body">Message</label>
                        <textarea id="body" name="body" class="form-control" rows="6" required><?php echo CHtml::encode($template['body']); ?></textarea>
                        <p class="text-muted" style="margin-top: 4px;">
                            <code>{{firstName}}</code>, <code>{{lastName}}</code>, <code>{{fullName}}</code>
                        </p>
                    </div>

                    <div class="form-group">
                        <label>Current Attachment</label>
                        <?php if (!empty($template['attachmentKind'])): ?>
                            <p>
                                <span class="label <?php echo $template['attachmentKind'] === 'image' ? 'label-success' : 'label-warning'; ?>">
                                    <?php echo $template['attachmentKind'] === 'image' ? 'Image' : 'PDF'; ?>
                                </span>
                                <span class="text-muted"><?php echo CHtml::encode($template['attachmentFileName']); ?></span>
                            </p>
                            <label class="checkbox-label">
                                <input type="checkbox" id="removeAttachment" name="removeAttachment" value="1">
                                Remove this attachment
                            </label>
                        <?php else: ?>
                            <p class="text-muted">None.</p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="attachment">Replace Attachment (optional)</label>
                        <input type="file" id="attachment" name="attachment" accept="image/*,application/pdf">
                        <p class="text-muted" style="margin-top: 4px;">
                            Uploading a new file here replaces the current attachment regardless of the
                            "Remove" checkbox above.
                        </p>
                    </div>

                    <?php echo CHtml::submitButton('Save Changes', array('class' => 'x2-button highlight')); ?>
                    <?php echo CHtml::link('Cancel', array('templates'), array('class' => 'x2-button')); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>
    </div>
</div>

<style>
    #x2-layout-content { padding: 0 20px; }
    .panel { border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06); }
    .panel-body { padding: 18px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
    .checkbox-label { font-weight: 400 !important; display: flex !important; align-items: center; gap: 6px; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .text-muted { color: #6c757d; }
    .label { display: inline-block; padding: 4px 8px; border-radius: 3px; color: #fff; font-size: 13px; }
    .label-success { background-color: #28a745; }
    .label-warning { background-color: #e0a800; }
</style>
