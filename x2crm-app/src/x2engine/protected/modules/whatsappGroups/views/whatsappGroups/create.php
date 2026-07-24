<?php
/**
 * Create WhatsApp Group View
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Create WhatsApp Group</h2></div>

        <?php if (Yii::app()->user->hasFlash('error')): ?>
            <div class="alert alert-danger">
                <?php echo Yii::app()->user->getFlash('error'); ?>
            </div>
        <?php endif; ?>

        <div class="panel panel-default" style="max-width: 600px;">
            <div class="panel-body">
                <?php $form = $this->beginWidget('CActiveForm', array('action' => array('create'), 'method' => 'POST')); ?>

                    <div class="form-group">
                        <label for="groupName">Group Name <span style="color: red;">*</span></label>
                        <input type="text" id="groupName" name="groupName" class="form-control" placeholder="e.g., Sales Team" required>
                        <small class="form-text text-muted">The name of the WhatsApp group</small>
                    </div>

                    <div class="form-group">
                        <label for="listId">Filter by List (Optional)</label>
                        <p class="text-muted">
                            Pick one of your dynamic <?php echo CHtml::link('Contact Lists', array('/contacts/contacts/lists')); ?>
                            to use its live filter criteria as this group's membership, instead of picking contacts manually below.
                            The group stays linked to the list, so you can re-sync its WhatsApp members later as matching contacts change.
                        </p>
                        <select id="listId" name="listId" class="form-control">
                            <option value="">-- No list, select contacts manually --</option>
                            <?php foreach ($lists as $list): ?>
                                <option value="<?php echo $list->id; ?>"><?php echo CHtml::encode($list->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="manualContactsGroup">
                        <label>Add Contacts (Optional)</label>
                        <p class="text-muted">Search for contacts to add as initial members. Only contacts with phone numbers can be added.</p>

                        <div style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="contactFilter" class="form-control" placeholder="Search by name or phone..." style="max-width: 250px;">
                            <a href="#" id="selectNoneContacts">Clear selected</a>
                        </div>

                        <div id="selectedContactsList" style="margin-bottom: 10px;"></div>

                        <div id="contactsList" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background-color: #fafafa;">
                            <p class="text-muted" style="margin: 0;">Start typing above to search contacts.</p>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <?php echo CHtml::submitButton('Create Group', array('class' => 'btn btn-primary')); ?>
                        <?php echo CHtml::link('Cancel', array('index'), array('class' => 'btn btn-default')); ?>
                    </div>

                <?php $this->endWidget(); ?>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    // Selected contacts are tracked here (id -> {name, phone}) rather than
    // relying on checkboxes staying in the DOM — search results are
    // replaced on every keystroke, so a contact picked under one search
    // term would otherwise vanish the moment a different search replaces
    // the results list. Hidden inputs for these get injected at submit
    // time instead of depending on native checkbox form-serialization.
    var selected = {};
    var contactsList = document.getElementById('contactsList');
    var selectedList = document.getElementById('selectedContactsList');
    var filterInput = document.getElementById('contactFilter');
    var searchTimer = null;

    function renderSelected() {
        var ids = Object.keys(selected);
        if (ids.length === 0) {
            selectedList.innerHTML = '';
            return;
        }
        var html = '<p class="text-muted" style="margin: 0 0 6px;">' + ids.length + ' contact(s) selected:</p>';
        ids.forEach(function(id) {
            html += '<span style="display: inline-block; background: #e9ecef; border-radius: 3px; padding: 3px 8px; margin: 0 6px 6px 0; font-size: 13px;">' +
                selected[id].name + ' <a href="#" data-remove="' + id + '" style="color: #721c24; text-decoration: none;">&times;</a></span>';
        });
        selectedList.innerHTML = html;
        selectedList.querySelectorAll('[data-remove]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                delete selected[this.dataset.remove];
                renderSelected();
                var cb = contactsList.querySelector('input[value="' + this.dataset.remove + '"]');
                if (cb) cb.checked = false;
            });
        });
    }

    function renderResults(contacts) {
        if (contacts.length === 0) {
            contactsList.innerHTML = '<p class="text-muted" style="margin: 0;">No matching contacts with a phone number.</p>';
            return;
        }
        var html = '';
        contacts.forEach(function(c) {
            var checked = selected[c.id] ? 'checked' : '';
            html += '<div class="checkbox"><label>' +
                '<input type="checkbox" data-id="' + c.id + '" data-name="' + c.name.replace(/"/g, '&quot;') + '" data-phone="' + c.phone.replace(/"/g, '&quot;') + '" ' + checked + '>' +
                '<strong>' + c.name + '</strong><br><small class="text-muted">' + c.phone + '</small>' +
                '</label></div>';
        });
        contactsList.innerHTML = html;
        contactsList.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
            cb.addEventListener('change', function() {
                if (this.checked) {
                    selected[this.dataset.id] = { name: this.dataset.name, phone: this.dataset.phone };
                } else {
                    delete selected[this.dataset.id];
                }
                renderSelected();
            });
        });
    }

    function search(q) {
        fetch('<?php echo Yii::app()->createUrl("whatsappGroups/whatsappGroups/searchContacts"); ?>?q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(renderResults)
            .catch(function() {
                contactsList.innerHTML = '<p class="text-muted" style="margin: 0;">Search failed — try again.</p>';
            });
    }

    filterInput.addEventListener('keyup', function() {
        var q = this.value.trim();
        clearTimeout(searchTimer);
        if (q === '') {
            contactsList.innerHTML = '<p class="text-muted" style="margin: 0;">Start typing above to search contacts.</p>';
            return;
        }
        searchTimer = setTimeout(function() { search(q); }, 300);
    });

    document.getElementById('selectNoneContacts').addEventListener('click', function(e) {
        e.preventDefault();
        selected = {};
        renderSelected();
        contactsList.querySelectorAll('input[type=checkbox]').forEach(function(cb) { cb.checked = false; });
    });

    document.getElementById('listId').addEventListener('change', function() {
        var manual = document.getElementById('manualContactsGroup');
        manual.style.display = this.value ? 'none' : '';
    });

    // Inject one hidden input per selected contact right before submit,
    // since checkboxes for contacts found under an earlier search term are
    // no longer in the DOM by the time the form is submitted.
    filterInput.closest('form').addEventListener('submit', function() {
        var form = this;
        Object.keys(selected).forEach(function(id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'contacts[]';
            input.value = id;
            form.appendChild(input);
        });
    });
})();
</script>

<style>
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
    }
    .form-control {
        display: block;
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
    }
    .form-control:focus {
        outline: none;
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }
    .text-muted {
        color: #6c757d;
    }
    .form-text {
        font-size: 12px;
    }
    .checkbox {
        margin-bottom: 12px;
        padding: 8px;
        border-radius: 3px;
        transition: background-color 0.2s;
    }
    .checkbox:hover {
        background-color: #f0f0f0;
    }
    .checkbox label {
        margin: 0;
        font-weight: normal;
        display: flex;
        align-items: flex-start;
    }
    .checkbox input[type="checkbox"] {
        margin-right: 10px;
        margin-top: 3px;
    }
    .alert {
        padding: 12px 15px;
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-radius: 4px;
    }
    .alert-danger {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }
    .panel {
        border: 1px solid #ddd;
    }
    .panel-body {
        padding: 20px;
    }
    .btn {
        padding: 8px 16px;
        border: 1px solid transparent;
        border-radius: 4px;
        font-size: 14px;
        cursor: pointer;
        display: inline-block;
        text-decoration: none;
        margin-right: 8px;
    }
    .btn-primary {
        background-color: #007bff;
        color: white;
    }
    .btn-primary:hover {
        background-color: #0056b3;
    }
    .btn-default {
        background-color: #f8f9fa;
        color: #333;
        border-color: #ddd;
    }
    .btn-default:hover {
        background-color: #e2e6ea;
    }
</style>
