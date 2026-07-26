<?php
/**
 * Manual admin tool: send one WhatsApp message on demand — an individual
 * phone number (left) or a WhatsApp Group (right).
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Send WhatsApp Message</h2></div>

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

        <div class="send-message-columns">
        <div class="panel panel-default send-message-individual">
            <div class="panel-heading">Send to an Individual</div>
            <div class="panel-body">
                <?php $form = $this->beginWidget('CActiveForm', array(
                    'action' => array('sendMessage'), 'method' => 'POST',
                    'htmlOptions' => array('enctype' => 'multipart/form-data'),
                )); ?>

                    <input type="hidden" id="contactId" name="contactId" value="">
                    <input type="hidden" id="templateId" name="templateId" value="">

                    <div class="form-group">
                        <label>Find a Contact <span style="color: red;">*</span></label>
                        <p class="text-muted">
                            Messages can only be sent to people already in your Contacts — search and select
                            one below. Their number and country code are resolved automatically from their
                            Contact record.
                        </p>
                        <input type="text" id="contactFilter" class="form-control" placeholder="Search by name or phone...">
                        <div id="contactResults" style="max-height: 220px; overflow-y: auto; border: 1px solid #ddd; margin-top: 8px; display: none;"></div>
                        <div id="selectedContact" style="margin-top: 8px;"></div>
                    </div>

                    <div class="form-group">
                        <label for="templateSelect">Use Template (optional)</label>
                        <select id="templateSelect" class="form-control">
                            <option value="">-- No template --</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo (int) $t['id']; ?>"><?php echo CHtml::encode($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-muted template-attachment-note" id="templateAttachmentNote" style="display: none;"></p>
                    </div>

                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" class="form-control" rows="6" required></textarea>
                        <p class="text-muted" style="margin-top: 4px;">
                            <code>{{firstName}}</code>/<code>{{lastName}}</code>/<code>{{fullName}}</code> resolve to
                            this Contact's own name.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="image">Image or PDF Attachment (optional)</label>
                        <input type="file" id="image" name="image" accept="image/*,application/pdf">
                        <a href="#" id="imageClear" class="wa-file-clear" style="display: none;">&times; Remove</a>
                        <p class="text-muted" style="margin-top: 4px;">
                            Sent inline (image as a photo, PDF as a document) with the message above as its
                            caption — shows up directly in the chat, not as a plain file link. Overrides the
                            template's own attachment, if any, above.
                        </p>
                    </div>

                    <?php echo CHtml::submitButton('Send', array('class' => 'x2-button highlight')); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>

        <div class="panel panel-default send-message-group">
            <div class="panel-heading">Send to a WhatsApp Group</div>
            <div class="panel-body">
                <?php $groupForm = $this->beginWidget('CActiveForm', array(
                    'action' => array('sendGroupMessage'), 'method' => 'POST',
                    'htmlOptions' => array('enctype' => 'multipart/form-data'),
                )); ?>

                    <input type="hidden" id="groupTemplateId" name="templateId" value="">

                    <div class="form-group">
                        <label for="groupId">WhatsApp Group</label>
                        <select id="groupId" name="groupId" class="form-control" required>
                            <option value="">-- Select a group --</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?php echo CHtml::encode($g['groupId']); ?>">
                                    <?php echo CHtml::encode($g['groupName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="groupTemplateSelect">Use Template (optional)</label>
                        <select id="groupTemplateSelect" class="form-control">
                            <option value="">-- No template --</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo (int) $t['id']; ?>"><?php echo CHtml::encode($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-muted template-attachment-note" id="groupTemplateAttachmentNote" style="display: none;"></p>
                    </div>

                    <div class="form-group">
                        <label for="groupMessage">Message</label>
                        <textarea id="groupMessage" name="message" class="form-control" rows="6" required></textarea>
                        <p class="text-muted" style="margin-top: 4px;">
                            A group post has no single recipient, so <code>{{firstName}}</code>/<code>{{fullName}}</code>
                            resolve to "everyone" and <code>{{lastName}}</code> is dropped.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="groupImage">Image or PDF Attachment (optional)</label>
                        <input type="file" id="groupImage" name="groupImage" accept="image/*,application/pdf">
                        <a href="#" id="groupImageClear" class="wa-file-clear" style="display: none;">&times; Remove</a>
                        <p class="text-muted" style="margin-top: 4px;">
                            Sent inline (image as a photo, PDF as a document) with the message above as its
                            caption — shows up directly in the chat, not as a plain file link. Overrides the
                            template's own attachment, if any, above.
                        </p>
                    </div>

                    <?php echo CHtml::submitButton('Send to Group', array('class' => 'x2-button highlight')); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>

        <div class="panel panel-default send-message-broadcast">
            <div class="panel-heading">Broadcast to a Contact List</div>
            <div class="panel-body">
                <p class="text-muted">
                    WhatsApp's own Broadcast List feature isn't something this tool can create or manage —
                    so this sends the same message individually to every Contact in the list you pick below
                    (each logged to that Contact's own Activity/History, same as a regular individual
                    message). Sends are spaced out to avoid looking spam-like to WhatsApp, so a large list
                    will take a while — the page will wait until it's done rather than send in the
                    background, so please don't navigate away once you click Send.
                </p>
                <?php $broadcastForm = $this->beginWidget('CActiveForm', array(
                    'action' => array('broadcastMessage'), 'method' => 'POST',
                    'htmlOptions' => array('enctype' => 'multipart/form-data'),
                )); ?>

                    <input type="hidden" id="broadcastTemplateId" name="templateId" value="">

                    <div class="form-group">
                        <label for="listId">Contact List</label>
                        <select id="listId" name="listId" class="form-control" style="max-width: 400px;" required>
                            <option value="">-- Select a list --</option>
                            <?php foreach ($lists as $list): ?>
                                <option value="<?php echo (int) $list->id; ?>">
                                    <?php echo CHtml::encode($list->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="broadcastTemplateSelect">Use Template (optional)</label>
                        <select id="broadcastTemplateSelect" class="form-control">
                            <option value="">-- No template --</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo (int) $t['id']; ?>"><?php echo CHtml::encode($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-muted template-attachment-note" id="broadcastTemplateAttachmentNote" style="display: none;"></p>
                    </div>

                    <div class="form-group">
                        <label for="broadcastMessage">Message</label>
                        <textarea id="broadcastMessage" name="message" class="form-control" rows="6" required></textarea>
                        <p class="text-muted" style="margin-top: 4px;">
                            Optional placeholders, replaced per-contact so each person gets a personalized
                            message instead of an identical mass blast:
                            <code>{{firstName}}</code>, <code>{{lastName}}</code>, <code>{{fullName}}</code>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="broadcastImage">Image or PDF Attachment (optional)</label>
                        <input type="file" id="broadcastImage" name="image" accept="image/*,application/pdf">
                        <a href="#" id="broadcastImageClear" class="wa-file-clear" style="display: none;">&times; Remove</a>
                        <p class="text-muted" style="margin-top: 4px;">
                            Sent inline (image as a photo, PDF as a document) with the message above as its
                            caption — shows up directly in the chat, not as a plain file link. Overrides the
                            template's own attachment, if any, above.
                        </p>
                    </div>

                    <?php echo CHtml::submitButton('Send Broadcast', array(
                        'class' => 'x2-button highlight',
                        'confirm' => 'Send this message individually to every contact in the selected list? This cannot be undone once started.',
                    )); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>
        </div>

        <div class="send-message-footer">
            <?php echo CHtml::link('Back to Groups', array('index'), array('class' => 'x2-button')); ?>
            <?php echo CHtml::link('Manage Templates', array('templates'), array('class' => 'x2-button')); ?>
        </div>
    </div>
</div>

<script>
(function () {
    var searchUrl = <?php echo CJSON::encode($this->createUrl('searchContacts')); ?>;
    var filterInput = document.getElementById('contactFilter');
    var resultsBox = document.getElementById('contactResults');
    var selectedBox = document.getElementById('selectedContact');
    var contactIdInput = document.getElementById('contactId');
    var searchTimer = null;

    function selectContact(id, name, phone) {
        contactIdInput.value = id;
        resultsBox.style.display = 'none';
        resultsBox.innerHTML = '';
        filterInput.value = '';
        filterInput.style.display = 'none';
        selectedBox.innerHTML = '<span class="text-muted">Sending to: <strong>' + name + '</strong> (' + phone + ')</span> ' +
            '<a href="#" id="clearContact">Change</a>';
        document.getElementById('clearContact').addEventListener('click', function (e) {
            e.preventDefault();
            contactIdInput.value = '';
            filterInput.style.display = '';
            selectedBox.innerHTML = '';
        });
    }

    // Block submission (rather than only relying on the server-side
    // rejection) if no Contact has actually been picked — messages can
    // only go to a person already in Contacts, never a typed-in number.
    filterInput.closest('form').addEventListener('submit', function (e) {
        if (!contactIdInput.value) {
            e.preventDefault();
            alert('Please search for and select a Contact first.');
        }
    });

    function renderResults(contacts) {
        if (contacts.length === 0) {
            resultsBox.innerHTML = '<p class="text-muted" style="margin: 8px;">No matching contacts with a phone number.</p>';
            resultsBox.style.display = 'block';
            return;
        }
        var html = '';
        contacts.forEach(function (c) {
            html += '<div class="contact-result" data-id="' + c.id + '" data-name="' + c.name.replace(/"/g, '&quot;') + '" data-phone="' + c.phone.replace(/"/g, '&quot;') + '" ' +
                'style="padding: 6px 10px; cursor: pointer; border-bottom: 1px solid #eee;">' +
                '<strong>' + c.name + '</strong> <span class="text-muted">' + c.phone + '</span></div>';
        });
        resultsBox.innerHTML = html;
        resultsBox.style.display = 'block';
        resultsBox.querySelectorAll('.contact-result').forEach(function (el) {
            el.addEventListener('mouseenter', function () { el.style.background = '#f0f0f0'; });
            el.addEventListener('mouseleave', function () { el.style.background = ''; });
            el.addEventListener('click', function () {
                selectContact(el.dataset.id, el.dataset.name, el.dataset.phone);
            });
        });
    }

    filterInput.addEventListener('keyup', function () {
        var q = this.value.trim();
        clearTimeout(searchTimer);
        if (q === '') {
            resultsBox.style.display = 'none';
            resultsBox.innerHTML = '';
            return;
        }
        searchTimer = setTimeout(function () {
            fetch(searchUrl + '?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(renderResults)
                .catch(function () {
                    resultsBox.innerHTML = '<p class="text-muted" style="margin: 8px;">Search failed — try again.</p>';
                    resultsBox.style.display = 'block';
                });
        }, 300);
    });

    // "Use Template" dropdowns, one per card — picking a template
    // pre-fills that card's own textarea with the template's body and
    // records its id, so the send action can attach the template's own
    // image/PDF (if it has one) unless a fresh file is uploaded instead.
    var templateJsonUrl = <?php echo CJSON::encode($this->createUrl('templateJson')); ?>;

    function wireTemplatePicker(selectEl, hiddenIdEl, textareaEl, noteEl) {
        if (!selectEl) return;
        selectEl.addEventListener('change', function () {
            var id = selectEl.value;
            hiddenIdEl.value = id;
            noteEl.style.display = 'none';
            noteEl.textContent = '';
            if (!id) return;
            fetch(templateJsonUrl + '?id=' + encodeURIComponent(id))
                .then(function (r) { return r.json(); })
                .then(function (t) {
                    if (!t) return;
                    textareaEl.value = t.body;
                    if (t.attachmentKind) {
                        noteEl.style.display = 'block';
                        noteEl.textContent = 'This template includes ' +
                            (t.attachmentKind === 'image' ? 'an image' : 'a PDF') +
                            ' attachment (' + t.attachmentFileName + ') — it will be sent unless you choose a ' +
                            'different file above.';
                    }
                })
                .catch(function () {});
        });
    }

    wireTemplatePicker(
        document.getElementById('templateSelect'), document.getElementById('templateId'),
        document.getElementById('message'), document.getElementById('templateAttachmentNote')
    );
    wireTemplatePicker(
        document.getElementById('groupTemplateSelect'), document.getElementById('groupTemplateId'),
        document.getElementById('groupMessage'), document.getElementById('groupTemplateAttachmentNote')
    );
    wireTemplatePicker(
        document.getElementById('broadcastTemplateSelect'), document.getElementById('broadcastTemplateId'),
        document.getElementById('broadcastMessage'), document.getElementById('broadcastTemplateAttachmentNote')
    );

    // "x Remove" next to each file input — plain <input type="file"> has
    // no visible way to deselect a chosen file short of re-opening the
    // picker and hitting Cancel, which isn't obvious. Shows up once a
    // file is chosen, clears the input and hides itself when clicked.
    function wireFileClear(inputId, clearId) {
        var input = document.getElementById(inputId);
        var clear = document.getElementById(clearId);
        if (!input || !clear) return;
        input.addEventListener('change', function () {
            clear.style.display = (input.files && input.files.length) ? 'inline' : 'none';
        });
        clear.addEventListener('click', function (e) {
            e.preventDefault();
            input.value = '';
            clear.style.display = 'none';
            input.dispatchEvent(new Event('change'));
        });
    }
    wireFileClear('image', 'imageClear');
    wireFileClear('groupImage', 'groupImageClear');
    wireFileClear('broadcastImage', 'broadcastImageClear');
})();
</script>

<style>
    #x2-layout-content { padding: 0 20px; }

    .panel {
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        margin-bottom: 20px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    }
    .panel-heading {
        padding: 14px 18px;
        border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        font-weight: 700;
        font-size: 15px;
        letter-spacing: 0.2px;
        color: #fff;
    }
    .panel-body {
        padding: 18px;
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .text-muted { color: #6c757d; }
    .wa-file-clear { margin-left: 8px; color: #c0392b; font-size: 13px; text-decoration: none; }
    .wa-file-clear:hover { text-decoration: underline; }

    /* Three equal-width cards on wide screens, each a flex column so its
       Send button consistently sinks to the bottom regardless of how much
       intro text/fields the other cards have — a uniform "row of cards"
       look instead of ragged bottoms. */
    .send-message-columns { display: flex; align-items: stretch; gap: 20px; margin-bottom: 4px; }
    .send-message-individual, .send-message-group, .send-message-broadcast {
        flex: 1 1 0;
        min-width: 0;
        display: flex;
        flex-direction: column;
        margin-bottom: 0;
    }
    .send-message-individual .panel-body,
    .send-message-group .panel-body,
    .send-message-broadcast .panel-body {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
    }
    .send-message-individual .panel-body form,
    .send-message-group .panel-body form,
    .send-message-broadcast .panel-body form {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
    }
    /* Pins the Send button to the bottom of each card without stretching
       the fields/textarea above it. */
    .send-message-individual .x2-button,
    .send-message-group .x2-button,
    .send-message-broadcast .x2-button {
        margin-top: auto;
        align-self: flex-start;
    }

    .send-message-individual .panel-heading { background-color: #2f6feb; }
    .send-message-group .panel-heading { background-color: #28a745; }
    .send-message-broadcast .panel-heading { background-color: #e8830f; }

    .send-message-footer { margin-top: 4px; }

    @media (max-width: 1100px) {
        .send-message-columns { flex-direction: column; }
        .send-message-individual, .send-message-group, .send-message-broadcast {
            max-width: 100%;
            margin-bottom: 20px;
        }
        .send-message-individual .x2-button,
        .send-message-group .x2-button,
        .send-message-broadcast .x2-button {
            margin-top: 16px;
        }
    }
</style>
