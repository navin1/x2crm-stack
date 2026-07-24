<?php
/**
 * Edit the wording for the new-lead WhatsApp group broadcast.
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>New-Lead Group Message</h2></div>

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

        <div class="panel panel-default" style="max-width: 700px;">
            <div class="panel-heading">Message template</div>
            <div class="panel-body">
                <p class="text-muted">
                    This is the wording posted into any WhatsApp group with new-lead
                    notifications turned on. It does not change the personal message the
                    assigned pracharak already gets.
                </p>

                <div class="form-group">
                    <label for="webform-picker">Editing message for:</label>
                    <select id="webform-picker" class="form-control" style="max-width: 400px;">
                        <option value="">&mdash; Default (all forms without a custom message) &mdash;</option>
                        <?php foreach ($forms as $f): ?>
                            <option value="<?php echo (int) $f['id']; ?>"
                                <?php echo (string) $selectedWebFormId === (string) $f['id'] ? ' selected' : ''; ?>>
                                <?php echo CHtml::encode($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <p class="text-muted">
                    Sends to:
                    <?php if (empty($groupNames)): ?>
                        <em>no groups currently opted in</em>
                    <?php else: ?>
                        <strong><?php echo CHtml::encode(implode(', ', $groupNames)); ?></strong>
                        <?php if ($usingFallback): ?>
                            (default pool &mdash; no groups picked specifically for this form on
                            <?php echo CHtml::link('Web Form Notifications', array('webFormNotify')); ?>)
                        <?php endif; ?>
                    <?php endif; ?>
                </p>

                <p>
                    Available placeholders — each is replaced with the lead's actual value,
                    and a line consisting only of a blank placeholder (e.g. <code>Company: {{company}}</code>
                    when there's no company) is automatically dropped:
                </p>
                <ul>
                    <li><code>{{formLabel}}</code> &mdash; the web form's name</li>
                    <li><code>{{pracharak}}</code> &mdash; name of the pracharak this lead was assigned to</li>
                    <li><code>{{name}}</code> &mdash; the lead's full name</li>
                    <li><code>{{email}}</code></li>
                    <li><code>{{phone}}</code></li>
                    <li><code>{{company}}</code></li>
                    <li><code>{{title}}</code></li>
                    <li><code>{{state}}</code></li>
                    <li><code>{{city}}</code></li>
                    <li><code>{{message}}</code> &mdash; the lead's own message/background info field</li>
                </ul>

                <p>
                    WhatsApp's own text formatting works here too: <code>*bold*</code>,
                    <code>_italic_</code>, <code>~strikethrough~</code>.
                </p>

                <?php $form = $this->beginWidget('CActiveForm', array('action' => array('saveNotifyTemplate'), 'method' => 'POST')); ?>
                    <input type="hidden" name="webFormId" value="<?php echo (int) $selectedWebFormId; ?>">
                    <div class="form-group">
                        <textarea name="template" class="form-control" rows="12" style="font-family: monospace;" required><?php echo CHtml::encode($template); ?></textarea>
                    </div>
                    <?php echo CHtml::submitButton('Save', array('class' => 'x2-button highlight')); ?>
                    <?php if ($selectedWebFormId && $isCustom): ?>
                        <?php echo CHtml::link('Reset to Default', '#', array(
                            'class' => 'x2-button',
                            'submit' => array('resetNotifyTemplate', 'webFormId' => $selectedWebFormId),
                            'csrf' => true,
                            'confirm' => 'Revert this form to the default message?',
                        )); ?>
                    <?php endif; ?>
                    <?php echo CHtml::link('Back to Groups', array('index'), array('class' => 'x2-button')); ?>
                <?php $this->endWidget(); ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var picker = document.getElementById('webform-picker');
    var baseUrl = <?php echo CJSON::encode($this->createUrl('editNotifyTemplate')); ?>;
    picker.addEventListener('change', function () {
        window.location = baseUrl + (this.value ? '?webFormId=' + encodeURIComponent(this.value) : '');
    });
})();
</script>

<style>
    .panel { border: 1px solid #ddd; margin-bottom: 20px; }
    .panel-heading { background-color: #f5f5f5; padding: 15px; border-bottom: 1px solid #ddd; font-weight: bold; }
    .panel-body { padding: 15px; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    textarea.form-control { width: 100%; box-sizing: border-box; }
</style>
