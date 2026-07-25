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

        <div class="notify-template-columns">
        <div class="panel panel-default notify-template-left">
            <div class="panel-heading">Message template</div>
            <div class="panel-body">
                <p class="text-muted">
                    This is the wording sent to the assigned pracharak's own WhatsApp DM,
                    and also posted into any WhatsApp group with new-lead notifications
                    turned on — the same message, sent both ways.
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

                <?php if ($selectedWebFormId): ?>
                    <p class="text-muted">
                        Sends to:
                        <?php if (!empty($groupNames)): ?>
                            <strong><?php echo CHtml::encode(implode(', ', $groupNames)); ?></strong>
                        <?php elseif (!empty($ineligibleGroupNames)): ?>
                            <em>no groups right now</em> &mdash;
                            <?php echo CHtml::encode(implode(', ', $ineligibleGroupNames)); ?>
                            <?php echo count($ineligibleGroupNames) === 1 ? 'is' : 'are'; ?> assigned to this
                            form but "New-lead notifications" is off for
                            <?php echo count($ineligibleGroupNames) === 1 ? 'it' : 'them'; ?> &mdash; turn it on
                            from that group's own page to actually receive this.
                        <?php else: ?>
                            <em>no groups assigned to this form</em> &mdash;
                            <?php echo CHtml::link('assign one on Web Form Notifications', array('webFormNotify')); ?>.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <p class="text-muted">
                        This is the fallback wording for any form that hasn't set its own custom
                        message above. Whether a pracharak or group actually receives it still
                        depends on that specific form's own assignment on
                        <?php echo CHtml::link('Web Form Notifications', array('webFormNotify')); ?>.
                    </p>
                <?php endif; ?>

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

        <div class="panel panel-default notify-template-right">
            <div class="panel-heading">Web Lead Form Preview</div>
            <div class="panel-body">
                <?php if ($selectedWebFormId): ?>
                    <p class="text-muted">
                        Live preview of <strong><?php echo CHtml::encode($selectedFormName); ?></strong> as a
                        lead would see it.
                    </p>
                    <iframe src="<?php echo CHtml::encode($previewIframeUrl); ?>" frameborder="0"
                        allowtransparency="true" scrolling="auto"></iframe>
                <?php else: ?>
                    <p class="text-muted">
                        Select a specific form above (instead of the default) to see a live
                        preview of it here.
                    </p>
                <?php endif; ?>
            </div>
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
    .notify-template-columns { display: flex; gap: 20px; align-items: stretch; }
    .notify-template-left { flex: 1 1 700px; max-width: 700px; display: flex; flex-direction: column; }
    .notify-template-right { flex: 1 1 420px; max-width: 460px; display: flex; flex-direction: column; }
    .notify-template-left .panel-body, .notify-template-right .panel-body {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
    }
    .notify-template-right iframe {
        flex: 1 1 auto;
        width: 100%;
        min-height: 300px;
        border: 1px solid #ddd;
    }
    @media (max-width: 1200px) {
        .notify-template-columns { flex-direction: column; }
        .notify-template-left, .notify-template-right { max-width: 700px; width: 100%; }
        .notify-template-right iframe { min-height: 480px; }
    }
    #x2-layout-content { padding: 0 20px; }
</style>
