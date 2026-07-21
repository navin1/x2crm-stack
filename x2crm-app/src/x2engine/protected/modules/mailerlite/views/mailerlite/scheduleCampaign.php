<?php
/**
 * Schedule a one-time MailerLite campaign to a Contacts list.
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>Schedule MailerLite Campaign</h2></div>
        <p class="text-muted">
            Sends a one-time email at the date/time you choose, to every Contact in the list you pick
            (synced to MailerLite automatically as part of scheduling). MailerLite doesn't support
            repeating/recurring sends, so this schedules a single send only — see
            <?php echo CHtml::link('MailerLite Configuration', array('configure')); ?> to sync a list on
            its own without scheduling anything.
        </p>

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
            <div class="panel-body">
                <?php if (!empty($lists)): ?>
                    <?php $form = $this->beginWidget('CActiveForm', array('action' => array('scheduleCampaign'), 'method' => 'POST')); ?>
                        <div class="form-group">
                            <label for="listId">Contact List</label>
                            <select id="listId" name="listId" class="form-control" style="max-width: 320px;">
                                <?php foreach ($lists as $list): ?>
                                    <option value="<?php echo $list->id; ?>"><?php echo CHtml::encode($list->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="campaignName">Campaign Name</label>
                            <input type="text" id="campaignName" name="campaignName" class="form-control" placeholder="e.g., July Newsletter" required>
                            <small class="text-muted">Internal name shown in your MailerLite dashboard — not seen by recipients.</small>
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject Line</label>
                            <input type="text" id="subject" name="subject" class="form-control" placeholder="e.g., What's new this month" required>
                        </div>

                        <div class="row-2" style="display: flex; gap: 16px;">
                            <div class="form-group" style="flex: 1;">
                                <label for="fromName">From Name</label>
                                <input type="text" id="fromName" name="fromName" class="form-control" placeholder="e.g., Acme Sales Team">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label for="fromEmail">From Email</label>
                                <input type="email" id="fromEmail" name="fromEmail" class="form-control" placeholder="e.g., sales@acme.com" required>
                                <small class="text-muted">Must already be a verified sending address in your MailerLite account.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="html">Email Content (HTML)</label>
                            <textarea id="html" name="html" class="form-control" rows="10" placeholder="&lt;h1&gt;Hello!&lt;/h1&gt;&lt;p&gt;...&lt;/p&gt;" required></textarea>
                            <small class="text-muted">
                                MailerLite's API has no way to list or reuse your existing visual-editor
                                templates — paste raw HTML here (e.g. exported from a template you've
                                already built in MailerLite, or written from scratch). Also requires an
                                Advanced-tier MailerLite plan; on lower plans this field is ignored by
                                MailerLite's API.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="sendAt">Send At</label>
                            <input type="datetime-local" id="sendAt" name="sendAt" class="form-control" style="max-width: 260px;" required>
                            <small class="text-muted">Must be in the future. One-time send only — no repeat option.</small>
                        </div>

                        <?php echo CHtml::submitButton('Schedule Campaign', array('class' => 'x2-button highlight')); ?>
                    <?php $this->endWidget(); ?>
                <?php else: ?>
                    <p class="text-muted">
                        No Contact Lists yet — create one at
                        <?php echo CHtml::link('Contact Lists', array('/contacts/contacts/lists')); ?> first.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .panel { border: 1px solid #ddd; margin-bottom: 20px; }
    .panel-body { padding: 20px; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-control { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
    textarea.form-control { font-family: monospace; font-size: 13px; }
    .alert { padding: 12px 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
    .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
    .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    .text-muted { color: #6c757d; }
</style>
