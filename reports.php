<?php
require_once 'includes/config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: admin-login.php');
    exit;
}

// ----------------- NEW: handle AJAX create_report -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_report') {
    header('Content-Type: application/json; charset=utf-8');
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $from = trim($_POST['from_date'] ?? '');
    $to = trim($_POST['to_date'] ?? '');
    $description = trim($_POST['description'] ?? ''); // <-- new
    $created_by = getCurrentUserId();

    if ($name === '' || $type === '') {
        echo json_encode(['success' => false, 'message' => 'Name and type required.']);
        exit;
    }

    try {
        // Try inserting with description column if available
        try {
            $stmt = $pdo->prepare("
                INSERT INTO reports (name, type, description, from_date, to_date, created_by, status, created_at)
                VALUES (:name, :type, :description, :from_date, :to_date, :created_by, :status, NOW())
            ");
            $status = 'pending';
            $stmt->execute([
                ':name' => $name,
                ':type' => $type,
                ':description' => $description !== '' ? $description : null,
                ':from_date' => $from ?: null,
                ':to_date' => $to ?: null,
                ':created_by' => $created_by,
                ':status' => $status
            ]);
        } catch (Exception $innerEx) {
            // Fallback: some schemas may not have description column — insert without description
            // Log the inner exception for debugging
            error_log("[reports.php] insert w/description failed: " . $innerEx->getMessage());
            $stmt = $pdo->prepare("
                INSERT INTO reports (name, type, from_date, to_date, created_by, status, created_at)
                VALUES (:name, :type, :from_date, :to_date, :created_by, :status, NOW())
            ");
            $status = 'pending';
            $stmt->execute([
                ':name' => $name,
                ':type' => $type,
                ':from_date' => $from ?: null,
                ':to_date' => $to ?: null,
                ':created_by' => $created_by,
                ':status' => $status
            ]);
        }

        $reportId = (int)$pdo->lastInsertId();

        // Return created row for immediate rendering (try to include description)
        try {
            $stmt2 = $pdo->prepare("SELECT report_id, name, type, created_at, status, description FROM reports WHERE report_id = ?");
            $stmt2->execute([$reportId]);
            $row = $stmt2->fetch(PDO::FETCH_ASSOC);
            // If fetch failed (unexpected), build a minimal row
            if (!$row) {
                $row = [
                    'report_id' => $reportId,
                    'name' => $name,
                    'type' => $type,
                    'created_at' => date('Y-m-d H:i:s'),
                    'status' => $status,
                    'description' => $description !== '' ? $description : null
                ];
            }
        } catch (Exception $eFetch) {
            // Log and fall back to a safe constructed object
            error_log("[reports.php] fetch created report failed: " . $eFetch->getMessage());
            $row = [
                'report_id' => $reportId,
                'name' => $name,
                'type' => $type,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => $status,
                'description' => $description !== '' ? $description : null
            ];
        }

        echo json_encode(['success' => true, 'report' => $row]);
        exit;
    } catch (Exception $e) {
        // Log full exception for server-side debugging and return the message to the client
        error_log("[reports.php] create_report error: " . $e->getMessage() . " -- Trace: " . $e->getTraceAsString());
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create report.',
            'error' => $e->getMessage() // helpful for debugging; remove in production
        ]);
        exit;
    }
}
// ----------------- end create_report handler -----------------

// ---------------------------
// Real-time stats from DB (server-side initial values)
// ---------------------------
$collectionsThisMonth = 0;
$pendingCount = 0;
$completedThisMonth = 0;
$reportsCount = 0;

try {
    // Collections this month
    $stmt = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM collections
        WHERE YEAR(created_at) = YEAR(CURRENT_DATE())
          AND MONTH(created_at) = MONTH(CURRENT_DATE())
    ");
    $row = $stmt->fetch();
    if ($row && isset($row['cnt'])) {
        $collectionsThisMonth = (int)$row['cnt'];
    }
} catch (Exception $e) {
    error_log("[reports.php] collections this month query failed: " . $e->getMessage());
    $collectionsThisMonth = 0;
}

try {
    // Pending (total pending that need action)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM collections WHERE status = :status");
    $stmt->execute([':status' => 'pending']);
    $pendingCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("[reports.php] pending count query failed: " . $e->getMessage());
    $pendingCount = 0;
}

try {
    // Completed this month
    $stmt = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM collections
        WHERE status = 'completed'
          AND YEAR(created_at) = YEAR(CURRENT_DATE())
          AND MONTH(created_at) = MONTH(CURRENT_DATE())
    ");
    $row = $stmt->fetch();
    if ($row && isset($row['cnt'])) {
        $completedThisMonth = (int)$row['cnt'];
    }
} catch (Exception $e) {
    error_log("[reports.php] completed this month query failed: " . $e->getMessage());
    $completedThisMonth = 0;
}

try {
    // Reports total (simple count)
    $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM reports");
    $row = $stmt->fetch();
    if ($row && isset($row['cnt'])) {
        $reportsCount = (int)$row['cnt'];
    }
} catch (Exception $e) {
    error_log("[reports.php] reports count query failed: " . $e->getMessage());
    $reportsCount = 0;
}

// Fetch recent reports (server-side rendering fallback)
$reports = [];
try {
    $stmt = $pdo->query("
        SELECT
            report_id,
            name,
            type,
            description,
            created_at,
            status
        FROM reports
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $reports = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("[reports.php] Failed to load reports: " . $e->getMessage());
    $reports = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports & Analytics - Trashbin Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/janitor-dashboard.css">
  <!-- header styles are included in shared header include -->
</head>
<body>
  <?php include_once __DIR__ . '/includes/header-admin.php'; ?>

    <div class="dashboard">
      <!-- Animated Background Circles -->
      <div class="background-circle background-circle-1"></div>
      <div class="background-circle background-circle-2"></div>
      <div class="background-circle background-circle-3"></div>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header d-none d-md-block">
        <h6 class="sidebar-title">Menu</h6>
      </div>
      <a href="admin-dashboard.php" class="sidebar-item active">
        <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
      </a>
      <a href="bins.php" class="sidebar-item">
        <i class="fa-solid fa-trash-alt"></i><span>Trashbins</span>
      </a>
      <a href="janitors.php" class="sidebar-item">
        <i class="fa-solid fa-users"></i><span>Maintenance Staff</span>
      </a>
      <a href="reports.php" class="sidebar-item">
        <i class="fa-solid fa-chart-line"></i><span>Reports</span>
      </a>
      <a href="notifications.php" class="sidebar-item">
        <i class="fa-solid fa-bell"></i><span>Notifications</span>
      </a>
      <a href="#" class="sidebar-item">
        <i class="fa-solid fa-gear"></i><span>Settings</span>
      </a>
      <a href="profile.php" class="sidebar-item">
        <i class="fa-solid fa-user"></i><span>My Profile</span>
      </a>
    </aside>

    <!-- Main Content -->
    <main class="content">
      <div class="section-header flex-column flex-md-row">
        <div>
          <h1 class="page-title">Reports & Analytics</h1>
          <p class="page-subtitle">View system reports and analytics</p>
        </div>
        <div class="d-flex gap-2 flex-column flex-md-row mt-3 mt-md-0">
          <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#createReportModal">
            <i class="fas fa-plus me-1"></i>Create Report
          </button>
          <button id="btnExport" class="btn btn-primary" onclick="exportReport()">
            <i class="fas fa-download me-1"></i>Export
          </button>
        </div>
      </div>

      <!-- Report Stats -->
      <div class="row g-3 g-md-4 mb-4 mb-md-5">
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon">
              <i class="fas fa-trash-can"></i>
            </div>
            <div class="stat-content">
              <h6>Collections</h6>
              <h2 id="stat-collections"><?php echo htmlspecialchars($collectionsThisMonth, ENT_QUOTES, 'UTF-8'); ?></h2>
              <small>This month</small>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon warning">
              <i class="fas fa-clock"></i>
            </div>
            <div class="stat-content">
              <h6>Pending</h6>
              <h2 id="stat-pending"><?php echo htmlspecialchars($pendingCount, ENT_QUOTES, 'UTF-8'); ?></h2>
              <small>Need action</small>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon success">
              <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
              <h6>Completed</h6>
              <h2 id="stat-completed"><?php echo htmlspecialchars($completedThisMonth, ENT_QUOTES, 'UTF-8'); ?></h2>
              <small>This month</small>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon">
              <i class="fas fa-calendar"></i>
            </div>
            <div class="stat-content">
              <h6>Reports</h6>
              <h2 id="stat-reports"><?php echo htmlspecialchars($reportsCount, ENT_QUOTES, 'UTF-8'); ?></h2>
              <small>Generated</small>
            </div>
          </div>
        </div>
      </div>

      <!-- NEW: Created Reports Cards (appended when creating new reports) -->
      <div class="row g-3 g-md-4 mb-4 mb-md-5" id="createdReportsContainer">
        <?php
          // Render up to 6 most recent reports as cards on initial page load
          $initialCards = array_slice($reports, 0, 6);
          foreach ($initialCards as $rc) {
              $rid = (int)($rc['report_id'] ?? 0);
              $rname = htmlspecialchars($rc['name'] ?? 'Unnamed Report', ENT_QUOTES, 'UTF-8');
              $rtype = htmlspecialchars($rc['type'] ?? '', ENT_QUOTES, 'UTF-8');
              $rdesc = htmlspecialchars($rc['description'] ?? '', ENT_QUOTES, 'UTF-8');
              $rcreated = $rc['created_at'] ? date('M d, Y H:i', strtotime($rc['created_at'])) : '-';
              $rstatus = htmlspecialchars($rc['status'] ?? 'pending', ENT_QUOTES, 'UTF-8');
              $statusClass = 'badge bg-secondary';
              if (strtolower($rstatus) === 'completed') $statusClass = 'badge bg-success';
              if (strtolower($rstatus) === 'pending') $statusClass = 'badge bg-warning text-dark';
              if (strtolower($rstatus) === 'failed') $statusClass = 'badge bg-danger';
              $descSnippet = $rdesc ? '<div class="small text-muted mt-1">' . (strlen($rdesc) > 120 ? substr($rdesc,0,120) . '...' : $rdesc) . '</div>' : '';
        ?>
          <div class="col-12 col-md-4" id="report-card-<?php echo $rid; ?>">
            <div class="card h-100">
              <div class="card-body d-flex flex-column">
                <h5 class="card-title mb-2"><?php echo $rname; ?></h5>
                <p class="mb-1"><strong>Type:</strong> <?php echo $rtype; ?></p>
                <p class="text-muted small mb-2"><?php echo $rcreated; ?></p>
                <?php echo $descSnippet; ?>
                <div class="mt-auto d-flex justify-content-between align-items-center">
                  <span class="<?php echo $statusClass; ?>"><?php echo ucfirst($rstatus); ?></span>
                  <div>
                    <a href="view-report.php?id=<?php echo $rid; ?>" class="btn btn-sm btn-outline-primary">View</a>
                    <a href="download-report.php?id=<?php echo $rid; ?>" class="btn btn-sm btn-outline-secondary">Download</a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php } ?>
      </div>

      <!-- Recent Reports -->
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Recent Reports</h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table mb-0">
              <thead>
                <tr>
                  <th>Report Name</th>
                  <th class="d-none d-md-table-cell">Description</th>
                  <th class="d-none d-md-table-cell">Type</th>
                  <th class="d-none d-lg-table-cell">Date Created</th>
                  <th>Status</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody id="reportsTableBody">
                <?php if (empty($reports)): ?>
                  <tr>
                    <td colspan="6" class="text-center py-4 text-muted">No reports found</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($reports as $r): ?>
                    <?php
                      $id = (int)($r['report_id'] ?? 0);
                      $name = htmlspecialchars($r['name'] ?? 'Unnamed Report', ENT_QUOTES, 'UTF-8');
                      $description = htmlspecialchars($r['description'] ?? '', ENT_QUOTES, 'UTF-8');
                      $type = htmlspecialchars($r['type'] ?? '', ENT_QUOTES, 'UTF-8');
                      $created = $r['created_at'] ?? null;
                      $dateFormatted = $created ? date('M d, Y H:i', strtotime($created)) : '-';
                      $status = htmlspecialchars($r['status'] ?? 'unknown', ENT_QUOTES, 'UTF-8');

                      // simple label classes
                      $statusClass = 'badge bg-secondary';
                      if (strtolower($status) === 'completed') $statusClass = 'badge bg-success';
                      if (strtolower($status) === 'pending') $statusClass = 'badge bg-warning text-dark';
                      if (strtolower($status) === 'failed') $statusClass = 'badge bg-danger';
                    ?>
                    <tr>
                      <td><?php echo $name; ?></td>
                      <td class="d-none d-md-table-cell"><?php echo $description ?: '-'; ?></td>
                      <td class="d-none d-md-table-cell"><?php echo ucfirst($type); ?></td>
                      <td class="d-none d-lg-table-cell"><?php echo $dateFormatted; ?></td>
                      <td><span class="<?php echo $statusClass; ?>"><?php echo ucfirst($status); ?></span></td>
                      <td class="text-end">
                        <div class="btn-group" role="group" aria-label="Actions">
                          <a href="view-report.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">View</a>
                          <a href="download-report.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">Download</a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Create Report Modal -->
  <div class="modal fade" id="createReportModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Create New Report</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="createReportForm">
            <div class="mb-3">
              <label class="form-label">Report Name</label>
              <input type="text" class="form-control" id="reportName" placeholder="Enter report name" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Report Type</label>
              <select class="form-select" id="reportType" required>
                <option value="">Select type</option>
                <option value="collections">Collections Report</option>
                <option value="performance">Janitor Performance</option>
                <option value="bins">Bin Status Report</option>
              </select>
            </div>

            <!-- NEW: Description field -->
            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea class="form-control" id="reportDescription" rows="3" placeholder="Optional description (summarize what this report will cover)"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">From Date</label>
              <input type="date" class="form-control" id="reportFromDate">
            </div>
            <div class="mb-3">
              <label class="form-label">To Date</label>
              <input type="date" class="form-control" id="reportToDate">
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="generateReport()">Generate Report</button>
        </div>
      </div>
    </div>
  </div>
    <?php include_once __DIR__ . '/includes/footer-admin.php'; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="js/bootstrap.bundle.min.js"></script>
  <script src="js/database.js"></script>
  <script src="js/dashboard.js"></script>
  <script src="js/reports.js"></script>

  <script>
    function createReportCardHtml(report) {
      const createdAt = report.created_at ? new Date(report.created_at).toLocaleString() : '';
      const status = (report.status || 'pending').toLowerCase();
      let statusClass = 'badge bg-secondary';
      if (status === 'completed') statusClass = 'badge bg-success';
      if (status === 'pending') statusClass = 'badge bg-warning text-dark';
      if (status === 'failed') statusClass = 'badge bg-danger';

      // include description snippet if present
      const desc = report.description ? `<div class="small text-muted mt-1">${escapeHtml(report.description.length > 120 ? report.description.substring(0,120) + '...' : report.description)}</div>` : '';

      return `
        <div class="col-12 col-md-4" id="report-card-${report.report_id}">
          <div class="card h-100">
            <div class="card-body d-flex flex-column">
              <h5 class="card-title mb-2">${escapeHtml(report.name)}</h5>
              <p class="mb-1"><strong>Type:</strong> ${escapeHtml(report.type)}</p>
              <p class="text-muted small mb-2">${escapeHtml(createdAt)}</p>
              ${desc}
              <div class="mt-auto d-flex justify-content-between align-items-center">
                <span class="${statusClass}">${escapeHtml(report.status || 'pending')}</span>
                <div>
                  <a href="view-report.php?id=${report.report_id}" class="btn btn-sm btn-outline-primary">View</a>
                  <a href="download-report.php?id=${report.report_id}" class="btn btn-sm btn-outline-secondary">Download</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      `;
    }

    function escapeHtml(s) {
      if (!s) return '';
      return s.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'", '&#039;');
    }

    function generateReport() {
      const nameEl = document.getElementById('reportName');
      const typeEl = document.getElementById('reportType');
      const fromEl = document.getElementById('reportFromDate');
      const toEl = document.getElementById('reportToDate');
      const descEl = document.getElementById('reportDescription'); // <-- new

      const name = nameEl ? nameEl.value.trim() : '';
      const type = typeEl ? typeEl.value : '';
      const fromDate = fromEl ? fromEl.value : '';
      const toDate = toEl ? toEl.value : '';
      const description = descEl ? descEl.value.trim() : ''; // <-- new

      if (!name || !type) {
        alert('Please enter report name and select type.');
        return;
      }

      const formData = new FormData();
      formData.append('action', 'create_report');
      formData.append('name', name);
      formData.append('type', type);
      if (fromDate) formData.append('from_date', fromDate);
      if (toDate) formData.append('to_date', toDate);
      if (description) formData.append('description', description); // <-- new

      // disable modal button UX
      const modalEl = document.getElementById('createReportModal');
      const btn = modalEl ? modalEl.querySelector('.btn-primary') : null;
      if (btn) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...'; }

      fetch('reports.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      })
      .then(r => {
        if (!r.ok) throw new Error('Network response was not ok');
        return r.json();
      })
      .then(data => {
        if (!data || !data.success) {
          alert((data && data.message) ? data.message : 'Failed to create report');
          return;
        }
        const report = data.report;

        // prepend card (includes description if present)
        const container = document.getElementById('createdReportsContainer');
        if (container) {
          container.insertAdjacentHTML('afterbegin', createReportCardHtml(report));
        }
        // update reports stat
        const statReports = document.getElementById('stat-reports');
        if (statReports) statReports.textContent = (parseInt(statReports.textContent||'0',10) + 1).toString();

        // also prepend to Recent Reports table with description snippet under name
        const tbody = document.getElementById('reportsTableBody');
        if (tbody) {
          const tr = document.createElement('tr');
          const createdFmt = report.created_at ? new Date(report.created_at).toLocaleString() : '-';
          const descSnippet = report.description ? `<div class="small text-muted mt-1">${escapeHtml(report.description.length > 120 ? report.description.substring(0,120) + '...' : report.description)}</div>` : '';
          tr.innerHTML = `
            <td>${escapeHtml(report.name)}</td>
            <td class="d-none d-md-table-cell">${escapeHtml(report.description || '-')}</td>
            <td class="d-none d-md-table-cell">${escapeHtml(report.type)}</td>
            <td class="d-none d-lg-table-cell">${escapeHtml(createdFmt)}</td>
            <td><span class="badge bg-warning text-dark">${escapeHtml(report.status)}</span></td>
            <td class="text-end">
              <div class="btn-group" role="group" aria-label="Actions">
                <a href="view-report.php?id=${report.report_id}" class="btn btn-sm btn-outline-primary">View</a>
                <a href="download-report.php?id=${report.report_id}" class="btn btn-sm btn-outline-secondary">Download</a>
              </div>
            </td>
          `;
          if (tbody.firstElementChild && tbody.firstElementChild.querySelector('.text-center')) {
            tbody.innerHTML = ''; // remove "No reports found"
          }
          tbody.prepend(tr);
        }

        // clear modal inputs
        if (document.getElementById('createReportForm')) document.getElementById('createReportForm').reset();

        // hide modal using Bootstrap API safely
        try {
          const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
          modalInstance.hide();
        } catch(e) {
          if (modalEl) modalEl.classList.remove('show');
        }
      })
      .catch(err => {
        console.error('create report error', err);
        alert('Failed to create report');
      })
      .finally(() => {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = btn.dataset.orig || 'Generate Report';
        }
      });
    }

    // ensure initial load if js/reports.js is not yet loaded or defines loadReports later
    document.addEventListener('DOMContentLoaded', function() {
      if (typeof loadReports === 'function') {
        loadReports();
      } else {
        setTimeout(function() {
          if (typeof loadReports === 'function') loadReports();
        }, 200);
      }
    });

    // Export implementation: POSTs a small form to export_reports.php which returns a download.
    // This includes optional filters (report type, from/to dates) taken from the modal inputs.
    function exportReport() {
      // Gather filter values from modal inputs (if user set them)
      const type = document.getElementById('reportType') ? document.getElementById('reportType').value : '';
      const fromDate = document.getElementById('reportFromDate') ? document.getElementById('reportFromDate').value : '';
      const toDate = document.getElementById('reportToDate') ? document.getElementById('reportToDate').value : '';

      // Build and submit a POST form to trigger the download
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = 'export_reports.php';
      form.style.display = 'none';

      if (type) {
        const inputType = document.createElement('input');
        inputType.type = 'hidden';
        inputType.name = 'type';
        inputType.value = type;
        form.appendChild(inputType);
      }

      if (fromDate) {
        const inputFrom = document.createElement('input');
        inputFrom.type = 'hidden';
        inputFrom.name = 'from_date';
        inputFrom.value = fromDate;
        form.appendChild(inputFrom);
      }

      if (toDate) {
        const inputTo = document.createElement('input');
        inputTo.type = 'hidden';
        inputTo.name = 'to_date';
        inputTo.value = toDate;
        form.appendChild(inputTo);
      }

      document.body.appendChild(form);

      // Optionally show a small UX change: disable export button briefly
      const btn = document.getElementById('btnExport');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Preparing...';
      }

      // Submit the form to trigger the browser download
      form.submit();

      // Clean up after a short delay (download is handled by browser)
      setTimeout(function() {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-download me-1"></i>Export';
        }
        form.remove();
      }, 1500);
    }
  </script>
  <!-- Janitor dashboard JS for header/footer modal helpers -->
  <script src="js/janitor-dashboard.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      try {
        const notifBtn = document.getElementById('notificationsBtn');
        if (notifBtn) notifBtn.addEventListener('click', function(e){ e.preventDefault(); if (typeof openNotificationsModal === 'function') openNotificationsModal(e); else if (typeof showModalById === 'function') showModalById('notificationsModal'); });
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) logoutBtn.addEventListener('click', function(e){ e.preventDefault(); if (typeof showLogoutModal === 'function') showLogoutModal(e); else if (typeof showModalById === 'function') showModalById('logoutModal'); else window.location.href='logout.php'; });
      } catch(err) { console.warn('Header fallback handlers error', err); }
    });
  </script>
</body>
</html>