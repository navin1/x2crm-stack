<?php
/**
 * Edit an existing Message Template, including replacing or removing
 * its attachment. See templates.php for the list/create page.
 */
$attachmentUrl = !empty($template['attachmentKind'])
    ? $this->createUrl('templateAttachment', array('id' => $template['id']))
    : null;
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Edit Template: <?php echo CHtml::encode($template['name']); ?></h2></div>

        <?php if (Yii::app()->user->hasFlash('error')): ?>
            <div class="alert alert-danger">
                <?php echo Yii::app()->user->getFlash('error'); ?>
            </div>
        <?php endif; ?>

        <div class="template-editor-columns">
            <div class="panel panel-default template-form-col">
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

            <div class="panel panel-default template-preview-col">
                <div class="panel-heading">Preview</div>
                <div class="panel-body">
                    <div class="wa-preview-phone">
                        <div class="wa-preview-header">
                            <div class="wa-preview-avatar">WA</div>
                            <div class="wa-preview-title" id="previewName"><?php echo CHtml::encode($template['name']); ?></div>
                        </div>
                        <div class="wa-preview-chat">
                            <div class="wa-preview-bubble">
                                <div class="wa-preview-attachment" id="previewAttachment"></div>
                                <div class="wa-preview-text" id="previewText"><?php echo CHtml::encode($template['body']); ?></div>
                                <div class="wa-preview-meta">
                                    <span id="previewTime"></span>
                                    <span class="wa-preview-ticks">✓✓</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted" style="margin-top: 10px;">
                        Placeholders show as typed here — they're resolved to an actual name (or "everyone" for a
                        group) only at send time.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var nameInput = document.getElementById('name');
    var bodyInput = document.getElementById('body');
    var attachmentInput = document.getElementById('attachment');
    var removeAttachmentCheckbox = document.getElementById('removeAttachment');
    var previewName = document.getElementById('previewName');
    var previewText = document.getElementById('previewText');
    var previewAttachment = document.getElementById('previewAttachment');
    var previewTime = document.getElementById('previewTime');

    var savedAttachmentUrl = <?php echo CJSON::encode($attachmentUrl); ?>;
    var savedAttachmentKind = <?php echo CJSON::encode(!empty($template['attachmentKind']) ? $template['attachmentKind'] : null); ?>;
    var savedAttachmentName = <?php echo CJSON::encode(!empty($template['attachmentFileName']) ? $template['attachmentFileName'] : ''); ?>;

    previewTime.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    function renderText() {
        var body = bodyInput.value.trim();
        previewText.textContent = body === '' ? 'Type a message to see a preview…' : body;
        previewName.textContent = nameInput.value.trim() || 'Message Preview';
    }

    function showImage(src) {
        previewAttachment.innerHTML = '';
        var img = document.createElement('img');
        img.src = src;
        previewAttachment.appendChild(img);
    }

    function showDoc(name) {
        previewAttachment.innerHTML =
            '<div class="wa-preview-doc"><span class="wa-preview-doc-icon">📄</span>' +
            '<span class="wa-preview-doc-name">' + name.replace(/</g, '&lt;') + '</span></div>';
    }

    function renderAttachment() {
        var file = attachmentInput.files && attachmentInput.files[0];
        if (file) {
            if (file.type.indexOf('image/') === 0) {
                var reader = new FileReader();
                reader.onload = function (e) { showImage(e.target.result); };
                reader.readAsDataURL(file);
            } else if (file.type === 'application/pdf') {
                showDoc(file.name);
            } else {
                previewAttachment.innerHTML = '';
            }
            return;
        }

        // No fresh upload — fall back to the currently-saved attachment,
        // unless "Remove this attachment" is checked.
        if (removeAttachmentCheckbox && removeAttachmentCheckbox.checked) {
            previewAttachment.innerHTML = '';
            return;
        }
        if (savedAttachmentUrl && savedAttachmentKind === 'image') {
            showImage(savedAttachmentUrl);
        } else if (savedAttachmentUrl && savedAttachmentKind === 'document') {
            showDoc(savedAttachmentName);
        } else {
            previewAttachment.innerHTML = '';
        }
    }

    nameInput.addEventListener('input', renderText);
    bodyInput.addEventListener('input', renderText);
    attachmentInput.addEventListener('change', renderAttachment);
    if (removeAttachmentCheckbox) {
        removeAttachmentCheckbox.addEventListener('change', renderAttachment);
    }
    renderText();
    renderAttachment();
})();
</script>

<style>
    #x2-layout-content { padding: 0 20px; }
    .panel { border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06); }
    .panel-heading { padding: 14px 18px; border-bottom: 1px solid rgba(0, 0, 0, 0.08); font-weight: 700; font-size: 15px; letter-spacing: 0.2px; }
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

    /* Form (left) + live preview (right), side by side. */
    .template-editor-columns { display: flex; align-items: stretch; gap: 20px; }
    .template-form-col { flex: 1 1 55%; min-width: 0; margin-bottom: 20px; }
    .template-preview-col { flex: 1 1 45%; min-width: 280px; margin-bottom: 20px; }

    /* WhatsApp-style phone mockup. */
    .wa-preview-phone {
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid #d0d0d0;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    .wa-preview-header {
        background: #075e54;
        color: #fff;
        padding: 12px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .wa-preview-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #128c7e;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex: none;
    }
    .wa-preview-title { font-weight: 600; font-size: 15px; }
    .wa-preview-chat {
        background-color: #e5ddd5;
        padding: 20px 16px;
        min-height: 320px;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
    }
    .wa-preview-bubble {
        background: #dcf8c6;
        border-radius: 8px;
        padding: 8px 10px 6px;
        max-width: 90%;
        align-self: flex-end;
        box-shadow: 0 1px 1px rgba(0, 0, 0, 0.12);
    }
    .wa-preview-attachment img {
        max-width: 100%;
        border-radius: 6px;
        display: block;
        margin-bottom: 4px;
    }
    .wa-preview-doc {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(0, 0, 0, 0.05);
        border-radius: 6px;
        padding: 8px;
        margin-bottom: 4px;
    }
    .wa-preview-doc-icon { font-size: 26px; }
    .wa-preview-doc-name { font-size: 13px; word-break: break-all; }
    .wa-preview-text {
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 14.5px;
        color: #111;
    }
    .wa-preview-meta {
        text-align: right;
        font-size: 11px;
        color: #667781;
        margin-top: 2px;
    }
    .wa-preview-ticks { color: #4fc3f7; margin-left: 3px; }

    @media (max-width: 900px) {
        .template-editor-columns { flex-direction: column; }
        .template-form-col, .template-preview-col { flex: 1 1 auto; }
    }
</style>
