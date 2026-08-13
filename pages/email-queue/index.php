<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireAdmin();
require_once __DIR__ . '/../../includes/pin-lockscreen-check.php';

$pageTitle = t('email_queue_status');
ensureEmailQueueTable();
$settings = getSettings();

// Get filter status
$statusFilter = $_GET['status'] ?? '';

// Build query
$where = '';
$params = [];
if ($statusFilter && in_array($statusFilter, ['pending', 'sent', 'failed'])) {
    $where = 'WHERE status = ?';
    $params[] = $statusFilter;
}

// Get statistics
$stats = [
    'pending' => 0,
    'sent'    => 0,
    'failed'  => 0
];
foreach (['pending', 'sent', 'failed'] as $status) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE status = ?");
    $stmt->execute([$status]);
    $stats[$status] = $stmt->fetchColumn();
}

// Pagination settings
$itemsPerPage = 20;
$pageParam = isset($_GET['page']) ? intval($_GET['page']) : null;

// Sorting settings
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$validSortColumns = ['id', 'to_email', 'subject', 'status', 'attempts', 'created_at', 'sent_at'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Helper function to build URL with sort parameters
function buildSortUrl($column, $currentSortBy, $currentSortOrder, $statusFilter) {
    $params = [];
    if ($column === $currentSortBy) {
        // Toggle sort order if clicking the same column
        $newSortOrder = $currentSortOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        // Default to DESC for new column
        $newSortOrder = 'DESC';
    }
    $params['sort_by'] = $column;
    $params['sort_order'] = $newSortOrder;
    
    if ($statusFilter) $params['status'] = $statusFilter;
    
    return '?' . http_build_query($params);
}

// Helper function to get sort icon
function getSortIcon($column, $currentSortBy, $currentSortOrder) {
    if ($column !== $currentSortBy) {
        return '<i class="bi bi-arrow-down-up text-muted small ms-1"></i>';
    }
    return $currentSortOrder === 'ASC' 
        ? '<i class="bi bi-arrow-up text-primary small ms-1"></i>' 
        : '<i class="bi bi-arrow-down text-primary small ms-1"></i>';
}

// Get total records for pagination
$countSql = "SELECT COUNT(*) as total FROM email_queue $where";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRecords = $stmt->fetch()['total'];
$totalPages = ceil($totalRecords / $itemsPerPage);

// Determine page - default to 1 if not specified
$page = $pageParam === null ? 1 : max(1, min($pageParam, $totalPages));

$offset = ($page - 1) * $itemsPerPage;

// Apply sorting
$sortColumnMap = [
    'id' => 'id',
    'to_email' => 'to_email',
    'subject' => 'subject',
    'status' => 'status',
    'attempts' => 'attempts',
    'created_at' => 'created_at',
    'sent_at' => 'sent_at'
];
$orderBy = " ORDER BY " . $sortColumnMap[$sortBy] . " " . $sortOrder;

// Get emails with pagination
$sql = "SELECT * FROM email_queue $where $orderBy LIMIT " . intval($itemsPerPage) . " OFFSET " . intval($offset);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$emails = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <?php if (!$showLockScreen): ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-envelope me-2"></i><?php echo $pageTitle; ?></h5>
                    <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                        <?php echo t('all'); ?> <?php echo number_format($totalRecords); ?>
                    </span>
                </div>
                <div class="card-body">
                    <!-- Statistics Cards -->
                    <style>
                    @media (max-width: 767.98px) {
                        .stat-cards-scroll {
                            display: flex;
                            flex-wrap: nowrap;
                            gap: 0.35rem;
                        }
                        .stat-cards-scroll .stat-card-item {
                            flex: 1 1 0;
                            min-width: 0;
                        }
                        .stat-cards-scroll .card-body {
                            padding: 0.45rem 0.3rem;
                            text-align: center;
                        }
                        .stat-cards-scroll .card-body h5 {
                            font-size: 0.65rem;
                            line-height: 1.25;
                            margin-bottom: 0.2rem;
                            word-break: break-word;
                            white-space: normal;
                        }
                        .stat-cards-scroll .card-body h2 {
                            font-size: 1.3rem;
                            line-height: 1;
                            margin-bottom: 0;
                            white-space: nowrap;
                        }
                    }
                    @media (min-width: 768px) {
                        .stat-cards-scroll { display: flex; flex-wrap: wrap; gap: 0.75rem; }
                        .stat-cards-scroll .stat-card-item { flex: 1 1 0; min-width: 0; }
                    }
                    </style>
                    <div class="stat-cards-scroll mb-4 mt-4">
                        <div class="stat-card-item">
                            <div class="card bg-warning text-white">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo t('email_pending'); ?></h5>
                                    <h2 class="mb-0"><?php echo $stats['pending']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card-item">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo t('email_sent'); ?></h5>
                                    <h2 class="mb-0"><?php echo $stats['sent']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card-item">
                            <div class="card bg-danger text-white">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo t('email_failed'); ?></h5>
                                    <h2 class="mb-0"><?php echo $stats['failed']; ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter -->
                    <div class="row mb-4 mt-4 gy-3">
                        <div class="col-md-6">
                            <div class="btn-group">
                                <a href="index.php" class="btn <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo t('email_all'); ?></a>
                                <a href="index.php?status=pending" class="btn btn-outline-warning <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>"><?php echo t('email_pending'); ?></a>
                                <a href="index.php?status=sent" class="btn btn-outline-success <?php echo $statusFilter === 'sent' ? 'active' : ''; ?>"><?php echo t('email_sent'); ?></a>
                                <a href="index.php?status=failed" class="btn btn-outline-danger <?php echo $statusFilter === 'failed' ? 'active' : ''; ?>"><?php echo t('email_failed'); ?></a>
                            </div>
                        </div>
                        <div class="col-md-6 text-start text-md-end">
                            <div class="d-inline-flex flex-wrap gap-2">
                                <button type="button" id="processPendingBtn" class="btn btn-primary">
                                    <i class="bi bi-send"></i> <?php echo t('email_process_pending'); ?>
                                </button>
                                <button type="button" id="clearQueueBtn" class="btn btn-danger">
                                    <i class="bi bi-trash"></i> <?php echo t('clear_emails'); ?>
                                </button>
                                <button type="button" id="refreshBtn" class="btn btn-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> <?php echo t('email_refresh'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Email List -->
                    <div class="table-responsive mt-4">
                        <style>
                            @media (max-width: 768px) {
                                .table-responsive table {
                                    font-size: 12px;
                                }
                                .table-responsive th,
                                .table-responsive td {
                                    padding: 8px 4px;
                                }
                                .table-responsive .btn-sm {
                                    padding: 4px 8px;
                                    font-size: 11px;
                                }
                                .table-responsive .btn-sm i {
                                    font-size: 12px;
                                }
                            }
                            @media (max-width: 576px) {
                                .table-responsive table {
                                    font-size: 11px;
                                }
                                .table-responsive th,
                                .table-responsive td {
                                    padding: 6px 3px;
                                }
                                .table-responsive .btn-sm {
                                    padding: 3px 6px;
                                    font-size: 10px;
                                }
                                .table-responsive .btn-sm i {
                                    font-size: 11px;
                                }
                            }
                        </style>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th style="white-space: nowrap;">
                                        <a href="<?php echo buildSortUrl('id', $sortBy, $sortOrder, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo t('email_id'); ?><?php echo getSortIcon('id', $sortBy, $sortOrder); ?>
                                        </a>
                                    </th>
                                    <th style="white-space: nowrap;">
                                        <a href="<?php echo buildSortUrl('to_email', $sortBy, $sortOrder, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo t('email_to'); ?><?php echo getSortIcon('to_email', $sortBy, $sortOrder); ?>
                                        </a>
                                    </th>
                                    <th style="white-space: nowrap;">
                                        <a href="<?php echo buildSortUrl('subject', $sortBy, $sortOrder, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo t('email_subject'); ?><?php echo getSortIcon('subject', $sortBy, $sortOrder); ?>
                                        </a>
                                    </th>
                                    <th style="white-space: nowrap;">
                                        <a href="<?php echo buildSortUrl('status', $sortBy, $sortOrder, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo t('email_status'); ?><?php echo getSortIcon('status', $sortBy, $sortOrder); ?>
                                        </a>
                                    </th>
                                    <th style="width: 120px; white-space: nowrap;">
                                        <a href="<?php echo buildSortUrl('attempts', $sortBy, $sortOrder, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo t('email_attempts'); ?><?php echo getSortIcon('attempts', $sortBy, $sortOrder); ?>
                                        </a>
                                    </th>
                                    <th style="white-space: nowrap;">
                                        <a href="<?php echo buildSortUrl('created_at', $sortBy, $sortOrder, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo t('email_created'); ?><?php echo getSortIcon('created_at', $sortBy, $sortOrder); ?>
                                        </a>
                                    </th>
                                    <th style="white-space: nowrap;">
                                        <a href="<?php echo buildSortUrl('sent_at', $sortBy, $sortOrder, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo t('email_sent'); ?><?php echo getSortIcon('sent_at', $sortBy, $sortOrder); ?>
                                        </a>
                                    </th>
                                    <th style="width: 150px; text-align: center; white-space: nowrap;"><?php echo t('email_actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($emails as $email): ?>
                                <tr>
                                    <td><?php echo $email['id']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($email['to_name'] ?? ''); ?><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($email['to_email']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars(mb_substr($email['subject'], 0, 50, 'UTF-8')) . (mb_strlen($email['subject'], 'UTF-8') > 50 ? '...' : ''); ?></td>
                                    <td>
                                        <?php if ($email['status'] === 'pending'): ?>
                                            <span class="badge bg-warning"><?php echo t('email_pending'); ?></span>
                                        <?php elseif ($email['status'] === 'sent'): ?>
                                            <span class="badge bg-success"><?php echo t('email_sent'); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><?php echo t('email_failed'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $email['attempts']; ?> / <?php echo $email['max_attempts']; ?></td>
                                    <td><?php echo formatDate($email['created_at']); ?></td>
                                    <td><?php echo $email['sent_at'] ? formatDate($email['sent_at']) : '-'; ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex flex-wrap justify-content-center gap-1">
                                            <button type="button" class="btn btn-sm btn-info btn-view-email"
                                                    data-email-id="<?php echo $email['id']; ?>">
                                                <i class="bi bi-eye"></i> <?php echo t('email_view'); ?>
                                            </button>
                                            <?php if ($email['status'] === 'failed'): ?>
                                                <button type="button" class="btn btn-sm btn-warning btn-retry-email"
                                                        data-email-id="<?php echo $email['id']; ?>">
                                                    <i class="bi bi-arrow-counterclockwise"></i> <?php echo t('email_retry'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($emails)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-1"></i>
                        <p class="mt-2 mb-0"><?php echo t('email_no_emails_in_queue'); ?></p>
                    </div>
                    <?php else: ?>
                    <?php renderUnifiedPagination($page, $totalPages, (int)$totalRecords, $itemsPerPage, [
                        'status' => $statusFilter,
                        'sort_by' => $sortBy,
                        'sort_order' => $sortOrder,
                    ], t('email_queue')); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Single Email Detail Modal — appended to <body> via JS to avoid nesting issues -->
<div class="modal fade" id="emailDetailModal" tabindex="-1" aria-labelledby="emailDetailModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailDetailModalLabel"><?php echo t('email_details'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><strong><?php echo t('email_to_label'); ?></strong> <span id="emailDetailTo"></span></div>
                <div class="mb-3"><strong><?php echo t('email_subject_label'); ?></strong> <span id="emailDetailSubject"></span></div>
                <div class="mb-3"><strong><?php echo t('email_status_label'); ?></strong> <span id="emailDetailStatus"></span></div>
                <div class="mb-3"><strong><?php echo t('email_attempts_label'); ?></strong> <span id="emailDetailAttempts"></span></div>
                <div id="emailDetailErrorSection" class="mb-3 d-none">
                    <strong><?php echo t('email_error_label'); ?></strong>
                    <div id="emailDetailError" class="alert alert-danger mb-0"></div>
                </div>
                <div class="mb-3">
                    <strong><?php echo t('email_plain_text'); ?></strong>
                    <pre id="emailDetailPlain" class="bg-light p-3 mb-0" style="white-space:pre-wrap;word-break:break-word;"></pre>
                </div>
                <div class="mb-3">
                    <strong><?php echo t('email_html_preview'); ?></strong>
                    <iframe id="emailIframePreview" style="width:100%;height:300px;border:1px solid #dee2e6;" sandbox="allow-same-origin"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Serialize email data for JS (no inline scripts inside loops) -->
<script>
const EMAIL_QUEUE_DATA = <?php
    $jsData = [];
    foreach ($emails as $email) {
        $jsData[$email['id']] = [
            'id'            => $email['id'],
            'to_name'       => $email['to_name'] ?? '',
            'to_email'      => $email['to_email'],
            'subject'       => $email['subject'],
            'status'        => $email['status'],
            'attempts'      => $email['attempts'],
            'max_attempts'  => $email['max_attempts'],
            'error_message' => $email['error_message'] ?? '',
            'plain_body'    => $email['plain_body'] ?? '',
            'html_body'     => $email['html_body'] ?? '',
        ];
    }
    echo json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

const CSRF_TOKEN = '<?php echo csrfToken(); ?>';
const BASE_URL   = '<?php echo BASE_URL; ?>';

document.addEventListener('DOMContentLoaded', function () {

    // Move modal to <body> to avoid Bootstrap positioning bugs inside nested containers
    const modal = document.getElementById('emailDetailModal');
    document.body.appendChild(modal);

    const bsModal    = new bootstrap.Modal(modal);
    const modalTitle = document.getElementById('emailDetailModalLabel');

    // ── View button ──────────────────────────────────────────────
    document.querySelectorAll('.btn-view-email').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id    = this.dataset.emailId;
            const email = EMAIL_QUEUE_DATA[id];
            if (!email) return;

            modalTitle.textContent = '<?php echo t('email_details'); ?> #' + email.id;

            document.getElementById('emailDetailTo').innerHTML = escHtml(email.to_name) + ' &lt;' + escHtml(email.to_email) + '&gt;';
            document.getElementById('emailDetailSubject').textContent = email.subject;

            let statusBadge = '';
            if (email.status === 'pending')       statusBadge = '<span class="badge bg-warning"><?php echo t('email_pending'); ?></span>';
            else if (email.status === 'sent')      statusBadge = '<span class="badge bg-success"><?php echo t('email_sent'); ?></span>';
            else                                   statusBadge = '<span class="badge bg-danger"><?php echo t('email_failed'); ?></span>';
            document.getElementById('emailDetailStatus').innerHTML = statusBadge;

            document.getElementById('emailDetailAttempts').textContent = email.attempts + ' / ' + email.max_attempts;

            const errorSection = document.getElementById('emailDetailErrorSection');
            if (email.error_message) {
                document.getElementById('emailDetailError').textContent = email.error_message;
                errorSection.classList.remove('d-none');
            } else {
                errorSection.classList.add('d-none');
            }

            document.getElementById('emailDetailPlain').textContent = email.plain_body || '';

            // Set iframe srcdoc directly before showing the modal
            const iframe = document.getElementById('emailIframePreview');
            if (iframe) {
                iframe.srcdoc = email.html_body || '';
            }

            bsModal.show();
        });
    });

    // ── Retry button ─────────────────────────────────────────────
    document.querySelectorAll('.btn-retry-email').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = this.dataset.emailId;
            Swal.fire({
                title: '<?php echo t('email_confirm_title'); ?>',
                text: '<?php echo t('email_retry_confirm_text'); ?>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--primary-color, #0d6efd)',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<?php echo t('email_yes'); ?>',
                cancelButtonText: '<?php echo t('email_cancel'); ?>'
            }).then(function (result) {
                if (!result.isConfirmed) return;

                fetch(BASE_URL + 'api/retry-email.php?id=' + id, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': CSRF_TOKEN
                    }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    console.log('Retry response:', data);
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: '<?php echo t('email_success'); ?>',
                            text: '<?php echo t('email_send_success'); ?>',
                            confirmButtonColor: 'var(--primary-color, #0d6efd)'
                        }).then(function () { location.reload(); });
                    } else {
                        const errors = data.result && data.result.errors ? data.result.errors.join('\n') : '';
                        const msg = data.error || errors || 'Unknown error';
                        Swal.fire({
                            icon: 'error',
                            title: '<?php echo t('email_send_failed'); ?>',
                            text: msg,
                            confirmButtonColor: 'var(--primary-color, #0d6efd)'
                        });
                    }
                })
                .catch(function (err) {
                    Swal.fire({
                        icon: 'error',
                        title: '<?php echo t('email_error_occurred'); ?>',
                        text: err.message,
                        confirmButtonColor: 'var(--primary-color, #0d6efd)'
                    });
                });
            });
        });
    });

    // ── Process Pending button ────────────────────────────────────
    document.getElementById('processPendingBtn').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing';

        const formData = new FormData();
        formData.append('limit', '20');

        fetch(BASE_URL + 'api/process-email-queue.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            console.log('Process response:', data);
            const result = data.result || {};
            let htmlMsg = '<table class="table table-sm mt-2 text-start">'
                + '<tr><td><?php echo t('email_processed'); ?></td><td><strong>' + (result.processed || 0) + '</strong></td></tr>'
                + '<tr><td><?php echo t('email_sent'); ?></td><td><strong class="text-success">' + (result.success || 0) + '</strong></td></tr>'
                + '<tr><td><?php echo t('email_failed'); ?></td><td><strong class="text-danger">' + (result.failed || 0) + '</strong></td></tr>'
                + '<tr><td><?php echo t('email_still_pending'); ?></td><td><strong>' + (result.pending || 0) + '</strong></td></tr>'
                + '</table>';

            if (result.errors && result.errors.length) {
                htmlMsg += '<div class="alert alert-danger text-start mt-2 mb-0"><strong><?php echo t('email_errors'); ?></strong><br>'
                    + result.errors.map(function (e) { return '<small>' + escHtml(e) + '</small>'; }).join('<br>')
                    + '</div>';
            }

            Swal.fire({
                title: '<?php echo t('email_process_queue_title'); ?>',
                html: htmlMsg,
                icon: (result.failed || 0) > 0 ? 'warning' : 'success',
                confirmButtonColor: 'var(--primary-color, #0d6efd)',
                confirmButtonText: '<?php echo t('yes'); ?>'
            }).then(function () { location.reload(); });
        })
        .catch(function (err) {
            Swal.fire({
                icon: 'error',
                title: '<?php echo t('error'); ?>',
                text: err.message,
                confirmButtonColor: 'var(--primary-color, #0d6efd)'
            });
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send"></i> <?php echo t('email_process_pending'); ?>';
        });
    });

    // ── Refresh button ────────────────────────────────────────────
    document.getElementById('refreshBtn').addEventListener('click', function () {
        location.reload();
    });

    // ── Clear Queue button ────────────────────────────────────────
    let isClearingEmailQueue = false;
    document.getElementById('clearQueueBtn').addEventListener('click', function () {
        if (isClearingEmailQueue) return;

        const btn = this;
        Swal.fire({
            title: '<?php echo t('clear_emails_confirm_title'); ?>',
            text: '<?php echo t('clear_emails_confirm_text'); ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<?php echo t('clear_emails'); ?>',
            cancelButtonText: '<?php echo t('cancel'); ?>'
        }).then(function (result) {
            // Show PIN modal only if PIN system is enabled in settings
            const hasPin = <?php echo !empty($settings['pin']) ? 'true' : 'false'; ?>;
            if (hasPin) {
                showPinModalForClearEmailQueue();
            } else {
                executeClearEmailQueue('');
            }
        });

        function showPinModalForClearEmailQueue() {
            Swal.fire({
                title: '<?php echo t('please_enter_pin'); ?>',
                input: 'password',
                inputLabel: '<?php echo t('confirm_pin_clear_emails_help'); ?>',
                inputPlaceholder: '******',
                inputAttributes: {
                    maxlength: 6,
                    pattern: '[0-9]*',
                    inputmode: 'numeric'
                },
                showCancelButton: true,
                confirmButtonText: '<?php echo t('confirm'); ?>',
                cancelButtonText: '<?php echo t('cancel'); ?>',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                preConfirm: function (pin) {
                    if (!pin || pin.length !== 6) {
                        Swal.showValidationMessage('<?php echo t('pin_must_be_6_digits'); ?>');
                        return false;
                    }
                    return pin;
                }
            }).then(function (result) {
                if (!result.isConfirmed) return;

                executeClearEmailQueue(result.value);
            });
        }

        function executeClearEmailQueue(pinVal) {
            isClearingEmailQueue = true;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span><?php echo t('clear_emails_clearing'); ?>';

            const formData = new FormData();
            formData.append('pin', pinVal);
            formData.append('_csrf_token', CSRF_TOKEN);

            fetch(BASE_URL + 'api/clear-email-queue.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                isClearingEmailQueue = false;

                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: '<?php echo t('success'); ?>',
                        text: data.message || '<?php echo t('clear_emails_success'); ?>',
                        confirmButtonColor: 'var(--primary-color, #0d6efd)'
                    }).then(function () { location.reload(); });
                } else {
                    isClearingEmailQueue = false;

                    if (data.pin_error) {
                        Swal.fire({
                            icon: 'error',
                            title: '<?php echo t('error'); ?>',
                            text: data.error || '<?php echo t('pin_incorrect'); ?>',
                            confirmButtonColor: 'var(--primary-color, #0d6efd)'
                        }).then(function () {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-trash"></i> <?php echo t('clear_emails'); ?>';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: '<?php echo t('error'); ?>',
                            text: data.error || '<?php echo t('clear_emails_error'); ?>',
                            confirmButtonColor: 'var(--primary-color, #0d6efd)'
                        });
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-trash"></i> <?php echo t('clear_emails'); ?>';
                    }
                }
            })
            .catch(function (err) {
                isClearingEmailQueue = false;
                Swal.fire({
                    icon: 'error',
                    title: '<?php echo t('error'); ?>',
                    text: err.message,
                    confirmButtonColor: 'var(--primary-color, #0d6efd)'
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-trash"></i> <?php echo t('clear_emails'); ?>';
            });
        }
    });

    // ── Helper: HTML escape ───────────────────────────────────────
    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
});
</script>

    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../../includes/pin-lockscreen-modal.php';
include __DIR__ . '/../../includes/footer.php'; 
?>
