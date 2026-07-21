<?php
/**
 * WhatsApp Configuration (Administration Tools)
 */
?>

<div id="x2-layout">
    <div id="x2-layout-content">
        <div class="page-title icon custom-module"><h2>WhatsApp Configuration</h2></div>

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
            <div class="panel-heading">Connection Status</div>
            <div class="panel-body">
                <dl class="dl-horizontal" id="status-fields">
                    <dt>Status:</dt>
                    <dd id="status-badge"><span class="label label-default">Checking...</span></dd>

                    <dt>Phone Number:</dt>
                    <dd id="status-phone">&mdash;</dd>

                    <dt>Profile Name:</dt>
                    <dd id="status-pushname">&mdash;</dd>

                    <dt>Groups Tracked:</dt>
                    <dd id="status-groups">&mdash;</dd>

                    <dt>Messages Logged:</dt>
                    <dd id="status-messages">&mdash;</dd>

                    <dt>Message Retention:</dt>
                    <dd id="status-retention">&mdash;</dd>

                    <dt>Session Directory:</dt>
                    <dd><code><?php echo CHtml::encode(isset($status['sessionDir']) ? $status['sessionDir'] : '?'); ?></code></dd>
                </dl>

                <div id="connected-actions" style="display:none; margin-top: 15px;">
                    <?php echo CHtml::link('Disconnect / Re-pair', '#', array(
                        'class' => 'x2-button urgent',
                        'submit' => array('disconnect'),
                        'csrf' => true,
                        'confirm' => 'Disconnect this WhatsApp account? You will need to scan a new QR code to reconnect, and group creation/sync will stop working until then.',
                    )); ?>
                </div>

                <div id="stuck-actions" style="display:none; margin-top: 15px;">
                    <p class="text-muted">Not connected and no QR code is being generated (e.g. after a session logout). Reset to start a fresh pairing:</p>
                    <?php echo CHtml::link('Start Pairing', '#', array(
                        'class' => 'x2-button highlight',
                        'submit' => array('disconnect'),
                        'csrf' => true,
                        'confirm' => 'Reset the WhatsApp connection and generate a new QR code to scan?',
                    )); ?>
                </div>

                <div id="qr-section" style="display:none; margin-top: 20px; text-align: center;">
                    <p id="qr-message" style="font-weight: 600;">Scan this QR code with WhatsApp to connect:</p>
                    <div style="display: inline-block; padding: 16px; background: #fff; border: 1px solid #ddd; border-radius: 8px;">
                        <img id="qr-img" src="" alt="QR code" style="width: 280px; height: 280px; display: block;">
                    </div>
                    <p style="color: #888; font-size: 13px; margin-top: 10px;">
                        WhatsApp &gt; Settings &gt; Linked Devices &gt; Link a Device
                    </p>
                </div>
            </div>
        </div>

        <div class="panel panel-default" style="max-width: 700px;">
            <div class="panel-heading">Recent Activity</div>
            <div class="panel-body">
                <table class="table table-striped" id="audit-table">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Admin</th>
                            <th>Result</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody id="audit-body">
                        <tr><td colspan="4" class="text-muted">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var qrRefreshTimer = null;

    function humanizeAction(action) {
        return String(action).replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
    }

    function timeAgo(dateStr) {
        var d = new Date(dateStr.replace(' ', 'T') + 'Z');
        var diffSec = Math.round((Date.now() - d.getTime()) / 1000);
        if (diffSec < 60) return diffSec + 's ago';
        if (diffSec < 3600) return Math.round(diffSec / 60) + 'm ago';
        if (diffSec < 86400) return Math.round(diffSec / 3600) + 'h ago';
        return Math.round(diffSec / 86400) + 'd ago';
    }

    function render(data) {
        var badge = document.getElementById('status-badge');
        var connectedActions = document.getElementById('connected-actions');
        var stuckActions = document.getElementById('stuck-actions');
        var qrSection = document.getElementById('qr-section');
        var qrImg = document.getElementById('qr-img');

        if (data.error) {
            badge.innerHTML = '<span class="label label-danger">Error: ' + data.error + '</span>';
            connectedActions.style.display = 'none';
            stuckActions.style.display = 'none';
            qrSection.style.display = 'none';
            return;
        }

        connectedActions.style.display = 'none';
        stuckActions.style.display = 'none';

        if (data.connected) {
            badge.innerHTML = '<span class="label label-success">Connected</span>';
            connectedActions.style.display = 'block';
            qrSection.style.display = 'none';
            if (qrRefreshTimer) { clearInterval(qrRefreshTimer); qrRefreshTimer = null; }
        } else {
            if (data.hasQr) {
                badge.innerHTML = '<span class="label label-warning">Disconnected &mdash; scan QR to connect</span>';
                qrSection.style.display = 'block';
                if (!qrRefreshTimer) {
                    var refreshQr = function() { qrImg.src = 'qrImage?_=' + Date.now(); };
                    refreshQr();
                    qrRefreshTimer = setInterval(refreshQr, 5000);
                }
            } else if (data.connecting) {
                badge.innerHTML = '<span class="label label-info">Connecting...</span>';
                qrSection.style.display = 'none';
            } else {
                badge.innerHTML = '<span class="label label-default">Disconnected</span>';
                qrSection.style.display = 'none';
                stuckActions.style.display = 'block';
            }
        }

        document.getElementById('status-phone').textContent = data.phoneNumber ? ('+' + data.phoneNumber) : '—';
        document.getElementById('status-pushname').textContent = data.pushName || '—';
        document.getElementById('status-groups').textContent = (data.totalGroups !== null && data.totalGroups !== undefined) ? data.totalGroups : '—';
        document.getElementById('status-messages').textContent = (data.totalMessages !== null && data.totalMessages !== undefined) ? data.totalMessages : '—';
        document.getElementById('status-retention').textContent = data.retentionDays ? (data.retentionDays + ' days') : '—';

        var auditBody = document.getElementById('audit-body');
        if (data.recentAudit && data.recentAudit.length) {
            auditBody.innerHTML = '';
            data.recentAudit.forEach(function(entry) {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + humanizeAction(entry.action) + '</td>' +
                    '<td>' + (entry.admin_user || '—') + '</td>' +
                    '<td>' + (entry.success ? '<span class="label label-success">OK</span>' : '<span class="label label-danger" title="' + (entry.error || '') + '">Failed</span>') + '</td>' +
                    '<td>' + timeAgo(entry.created_at) + '</td>';
                auditBody.appendChild(tr);
            });
        } else {
            auditBody.innerHTML = '<tr><td colspan="4" class="text-muted">No activity recorded yet.</td></tr>';
        }
    }

    function poll() {
        fetch('status').then(function(r) { return r.json(); }).then(render).catch(function(e) {
            document.getElementById('status-badge').innerHTML = '<span class="label label-danger">Error checking status</span>';
        });
    }

    poll();
    setInterval(poll, 4000);
})();
</script>

<style>
    .dl-horizontal dt {
        font-weight: bold;
        margin-top: 8px;
    }
    /* The theme's own .x2-button rule floats it right by default; these
       containers only ever hold one button each, so pin it left instead. */
    #connected-actions .x2-button, #stuck-actions .x2-button {
        float: none !important;
    }
    .panel {
        border: 1px solid #ddd;
        margin-bottom: 20px;
    }
    .panel-heading {
        background-color: #f5f5f5;
        padding: 15px;
        border-bottom: 1px solid #ddd;
        font-weight: 600;
    }
    .panel-body {
        padding: 15px;
    }
    .label {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 3px;
        color: #fff;
        font-size: 13px;
    }
    .label-success { background-color: #28a745; }
    .label-danger { background-color: #dc3545; }
    .label-warning { background-color: #e0a800; }
    .label-info { background-color: #17a2b8; }
    .label-default { background-color: #6c757d; }
    .alert {
        padding: 12px 15px;
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-radius: 4px;
    }
    .alert-success {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }
    .alert-danger {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }
    .text-muted {
        color: #6c757d;
    }
</style>
