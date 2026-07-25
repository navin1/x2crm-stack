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
        <div class="panel panel-default send-message-left">
            <div class="panel-heading">Send to an Individual</div>
            <div class="panel-body">
                <?php $form = $this->beginWidget('CActiveForm', array(
                    'action' => array('sendMessage'), 'method' => 'POST',
                    'htmlOptions' => array('enctype' => 'multipart/form-data'),
                )); ?>

                    <input type="hidden" id="contactId" name="contactId" value="">

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
                        <label for="message">Message</label>
                        <textarea id="message" name="message" class="form-control" rows="6" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="image">Image Attachment (optional)</label>
                        <input type="file" id="image" name="image" accept="image/*">
                        <p class="text-muted" style="margin-top: 4px;">
                            Sent inline as a photo (with the message above as its caption) — shows up directly
                            in the chat, not as a separate file download.
                        </p>
                    </div>

                    <?php echo CHtml::submitButton('Send', array('class' => 'x2-button highlight')); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>

        <div class="panel panel-default send-message-right">
            <div class="panel-heading">Send to a WhatsApp Group</div>
            <div class="panel-body">
                <?php $groupForm = $this->beginWidget('CActiveForm', array(
                    'action' => array('sendGroupMessage'), 'method' => 'POST',
                    'htmlOptions' => array('enctype' => 'multipart/form-data'),
                )); ?>

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
                        <label for="groupMessage">Message</label>
                        <textarea id="groupMessage" name="message" class="form-control" rows="6" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="groupImage">Image Attachment (optional)</label>
                        <input type="file" id="groupImage" name="groupImage" accept="image/*">
                        <p class="text-muted" style="margin-top: 4px;">
                            Sent inline as a photo (with the message above as its caption) — shows up directly
                            in the chat, not as a separate file download.
                        </p>
                    </div>

                    <?php echo CHtml::submitButton('Send to Group', array('class' => 'x2-button highlight')); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>
        </div>

        <div class="panel panel-default">
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
                        <label for="broadcastMessage">Message</label>
                        <textarea id="broadcastMessage" name="message" class="form-control" rows="6" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="broadcastImage">Image Attachment (optional)</label>
                        <input type="file" id="broadcastImage" name="image" accept="image/*">
                        <p class="text-muted" style="margin-top: 4px;">
                            Sent inline as a photo (with the message above as its caption) — shows up directly
                            in the chat, not as a separate file download.
                        </p>
                    </div>

                    <?php echo CHtml::submitButton('Send Broadcast', array(
                        'class' => 'x2-button highlight',
                        'confirm' => 'Send this message individually to every contact in the selected list? This cannot be undone once started.',
                    )); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <?php echo CHtml::link('Back to Groups', array('index'), array('class' => 'x2-button')); ?>
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
})();
</script>

<style>
    .panel { border: 1px solid #ddd; margin-bottom: 20px; }
    .panel-heading { background-color: #f5f5f5; padding: 15px; border-bottom: 1px solid #ddd; font-weight: bold; }
    .panel-body { padding: 15px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .text-muted { color: #6c757d; }
    #x2-layout-content { padding: 0 20px; }
    .send-message-columns { display: flex; gap: 20px; align-items: stretch; }
    .send-message-left, .send-message-right { flex: 1 1 0; max-width: 500px; }
    @media (max-width: 1050px) {
        .send-message-columns { flex-direction: column; }
        .send-message-left, .send-message-right { max-width: 600px; width: 100%; }
    }
</style>
