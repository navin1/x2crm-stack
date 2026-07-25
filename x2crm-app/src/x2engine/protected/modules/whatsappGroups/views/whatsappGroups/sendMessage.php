<?php
/**
 * Manual admin tool: send one WhatsApp message to an individual phone
 * number on demand.
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

        <div class="panel panel-default" style="max-width: 600px;">
            <div class="panel-body">
                <?php $form = $this->beginWidget('CActiveForm', array(
                    'action' => array('sendMessage'), 'method' => 'POST',
                    'htmlOptions' => array('enctype' => 'multipart/form-data'),
                )); ?>

                    <input type="hidden" id="contactId" name="contactId" value="">

                    <div class="form-group">
                        <label>Find a Contact (optional)</label>
                        <p class="text-muted">
                            Search and pick a Contact to auto-fill their number (and correctly resolve their
                            country code), or just type a phone number directly below.
                        </p>
                        <input type="text" id="contactFilter" class="form-control" placeholder="Search by name or phone...">
                        <div id="contactResults" style="max-height: 220px; overflow-y: auto; border: 1px solid #ddd; margin-top: 8px; display: none;"></div>
                        <div id="selectedContact" style="margin-top: 8px;"></div>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" class="form-control" placeholder="e.g., 7603907974" required>
                    </div>

                    <div class="form-group" id="countryGroup">
                        <label for="country">Country (only needed for a 10-digit number with no country code)</label>
                        <select id="country" name="country" class="form-control">
                            <option value="">-- Not needed / already includes country code --</option>
                            <option value="usa">USA</option>
                            <option value="canada">Canada</option>
                            <option value="india">India</option>
                            <option value="russia">Russia</option>
                            <option value="mexico">Mexico</option>
                            <option value="australia">Australia</option>
                            <option value="malaysia">Malaysia</option>
                            <option value="nepal">Nepal</option>
                            <option value="united arab emirates">United Arab Emirates</option>
                            <option value="suriname">Suriname</option>
                        </select>
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
                    <?php echo CHtml::link('Back to Groups', array('index'), array('class' => 'x2-button')); ?>

                <?php $this->endWidget(); ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var searchUrl = <?php echo CJSON::encode($this->createUrl('searchContacts')); ?>;
    var filterInput = document.getElementById('contactFilter');
    var resultsBox = document.getElementById('contactResults');
    var selectedBox = document.getElementById('selectedContact');
    var phoneInput = document.getElementById('phone');
    var contactIdInput = document.getElementById('contactId');
    var countryGroup = document.getElementById('countryGroup');
    var searchTimer = null;

    function selectContact(id, name, phone) {
        contactIdInput.value = id;
        phoneInput.value = phone;
        phoneInput.readOnly = true;
        countryGroup.style.display = 'none';
        resultsBox.style.display = 'none';
        resultsBox.innerHTML = '';
        filterInput.value = '';
        selectedBox.innerHTML = '<span class="text-muted">Selected: <strong>' + name + '</strong> (' + phone + ')</span> ' +
            '<a href="#" id="clearContact">Clear</a>';
        document.getElementById('clearContact').addEventListener('click', function (e) {
            e.preventDefault();
            contactIdInput.value = '';
            phoneInput.value = '';
            phoneInput.readOnly = false;
            countryGroup.style.display = '';
            selectedBox.innerHTML = '';
        });
    }

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
    .panel-body { padding: 15px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .text-muted { color: #6c757d; }
    #x2-layout-content { padding: 0 20px; }
</style>
