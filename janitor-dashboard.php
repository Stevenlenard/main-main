<?php
require_once 'includes/config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: user-login.php');
    exit;
}

// Check if user is janitor
if (!isJanitor()) {
    header('Location: admin-dashboard.php');
    exit;
}

// determine janitor id from session (best-effort)
$janitorId = intval($_SESSION['janitor_id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

/**
 * POST endpoint for janitors to update bin status.
 * Accepts: action=janitor_edit_status, bin_id, status, action_type (optional)
 * - updates bins table (status + capacity mapping)
 * - inserts admin notification
 * - inserts a bin_history/bin_logs entry if table exists (best-effort)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'janitor_edit_status') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!$janitorId) throw new Exception('Unauthorized');

        $bin_id = intval($_POST['bin_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $actionType = trim($_POST['action_type'] ?? $_POST['actionType'] ?? '');

        // normalize legacy value
        if ($status === 'in_progress') $status = 'half_full';

        $valid_statuses = ['empty', 'half_full', 'full', 'needs_attention', 'disabled', 'out_of_service'];
        if (!in_array($status, $valid_statuses, true)) {
            throw new Exception('Invalid status value');
        }

        // map capacity where applicable
        $capacity_map = [
            'empty' => 10,
            'half_full' => 50,
            'full' => 90,
            'needs_attention' => null,
            'disabled' => null,
            'out_of_service' => null
        ];
        $capacity = $capacity_map[$status] ?? null;

        // Update bins table (status and capacity if applicable)
        if ($capacity !== null) {
            $stmt = $conn->prepare("UPDATE bins SET status = ?, capacity = ?, updated_at = NOW() WHERE bin_id = ?");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param("sii", $status, $capacity, $bin_id);
        } else {
            $stmt = $conn->prepare("UPDATE bins SET status = ?, updated_at = NOW() WHERE bin_id = ?");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param("si", $status, $bin_id);
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception('Execute failed: ' . $err);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();

        // Resolve janitor name
        $janitor_name = null;
        if ($janitorId > 0) {
            if (isset($pdo) && $pdo instanceof PDO) {
                try {
                    $aStmt = $pdo->prepare("SELECT first_name, last_name, phone, email FROM janitors WHERE janitor_id = ? LIMIT 1");
                    $aStmt->execute([(int)$janitorId]);
                    $aRow = $aStmt->fetch(PDO::FETCH_ASSOC);
                    if ($aRow) $janitor_name = trim(($aRow['first_name'] ?? '') . ' ' . ($aRow['last_name'] ?? ''));
                } catch (Exception $e) { /* ignore */ }
            } else {
                if ($stmtA = $conn->prepare("SELECT first_name, last_name, phone, email FROM janitors WHERE janitor_id = ? LIMIT 1")) {
                    $stmtA->bind_param("i", $janitorId);
                    $stmtA->execute();
                    $r2 = $stmtA->get_result()->fetch_assoc();
                    if ($r2) $janitor_name = trim(($r2['first_name'] ?? '') . ' ' . ($r2['last_name'] ?? ''));
                    $stmtA->close();
                }
            }
        }
        if (empty($janitor_name)) $janitor_name = $janitorId ? "Janitor #{$janitorId}" : 'A janitor';

        // Get bin code for message
        $bin_code = null;
        if ($bin_id > 0) {
            if (isset($pdo) && $pdo instanceof PDO) {
                try {
                    $bstmt = $pdo->prepare("SELECT bin_code FROM bins WHERE bin_id = ? LIMIT 1");
                    $bstmt->execute([(int)$bin_id]);
                    $brow = $bstmt->fetch(PDO::FETCH_ASSOC);
                    if ($brow) $bin_code = $brow['bin_code'] ?? null;
                } catch (Exception $e) { /* ignore */ }
            } else {
                $res = $conn->query("SELECT bin_code FROM bins WHERE bin_id = " . intval($bin_id) . " LIMIT 1");
                if ($res && $row = $res->fetch_assoc()) $bin_code = $row['bin_code'] ?? null;
            }
        }
        $binDisplay = $bin_code ? "Bin '{$bin_code}'" : "Bin #{$bin_id}";

        // Build notification message (include actionType if provided)
        $notificationType = 'info';
        $statusText = ucfirst(str_replace('_', ' ', $status));
        $title = "{$binDisplay} status updated";
        $message = "{$janitor_name} updated status to \"{$statusText}\".";
        if (!empty($actionType)) $message .= " Action: {$actionType}.";

        // Insert notification (PDO or mysqli)
        try {
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmtN = $pdo->prepare("
                    INSERT INTO notifications (admin_id, janitor_id, bin_id, notification_type, title, message, created_at)
                    VALUES (:admin_id, :janitor_id, :bin_id, :type, :title, :message, NOW())
                ");
                $stmtN->execute([
                    ':admin_id' => null,
                    ':janitor_id' => $janitorId,
                    ':bin_id' => $bin_id,
                    ':type' => $notificationType,
                    ':title' => $title,
                    ':message' => $message
                ]);
            } else {
                if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows > 0) {
                    $stmtN = $conn->prepare("
                        INSERT INTO notifications (admin_id, janitor_id, bin_id, notification_type, title, message, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    if ($stmtN) {
                        $adminParam = null;
                        $janitorParam = $janitorId;
                        $binParam = (int)$bin_id;
                        $typeParam = $notificationType;
                        $titleParam = $title;
                        $messageParam = $message;
                        $stmtN->bind_param("iiisss", $adminParam, $janitorParam, $binParam, $typeParam, $titleParam, $messageParam);
                        $stmtN->execute();
                        $stmtN->close();
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[janitor_dashboard] notification insert failed: " . $e->getMessage());
        }

        // Best-effort: insert into bin_history or bin_logs if table exists (no notes)
        try {
            if ($conn->query("SHOW TABLES LIKE 'bin_history'")->num_rows > 0) {
                $hstmt = $conn->prepare("INSERT INTO bin_history (bin_id, janitor_id, status, action_type, created_at) VALUES (?, ?, ?, ?, NOW())");
                if ($hstmt) {
                    $hstmt->bind_param("iiss", $bin_id, $janitorId, $status, $actionType);
                    $hstmt->execute();
                    $hstmt->close();
                }
            } elseif ($conn->query("SHOW TABLES LIKE 'bin_logs'")->num_rows > 0) {
                $hstmt = $conn->prepare("INSERT INTO bin_logs (bin_id, performed_by, action, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                if ($hstmt) {
                    $act = $actionType ?: 'status_update';
                    $hstmt->bind_param("iiss", $bin_id, $janitorId, $act, $status);
                    $hstmt->execute();
                    $hstmt->close();
                }
            }
        } catch (Exception $e) {
            error_log("[janitor_dashboard] bin history insert failed: " . $e->getMessage());
        }

        echo json_encode(['success' => true, 'status' => $status, 'affected' => $affected]);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ----------------- AJAX GET endpoints for task history, alerts and profile -----------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Task history for this janitor (prefer bin_history then bin_logs)
    if ($_GET['action'] === 'get_task_history') {
        $history = [];
        try {
            if ($conn->query("SHOW TABLES LIKE 'bin_history'")->num_rows > 0) {
                $stmt = $conn->prepare("SELECT bh.created_at, bh.action_type, bh.status, bh.notes, bh.bin_id, b.bin_code, b.location
                                        FROM bin_history bh
                                        LEFT JOIN bins b ON bh.bin_id = b.bin_id
                                        WHERE bh.janitor_id = ?
                                        ORDER BY bh.created_at DESC
                                        LIMIT 500");
                if ($stmt) {
                    $stmt->bind_param("i", $janitorId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) $history[] = $row;
                    $stmt->close();
                }
            } elseif ($conn->query("SHOW TABLES LIKE 'bin_logs'")->num_rows > 0) {
                $stmt = $conn->prepare("SELECT bl.created_at, bl.action AS action_type, bl.status, bl.notes, bl.bin_id, b.bin_code, b.location
                                        FROM bin_logs bl
                                        LEFT JOIN bins b ON bl.bin_id = b.bin_id
                                        WHERE bl.performed_by = ?
                                        ORDER BY bl.created_at DESC
                                        LIMIT 500");
                if ($stmt) {
                    $stmt->bind_param("i", $janitorId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) $history[] = $row;
                    $stmt->close();
                }
            }
        } catch (Exception $e) { /* ignore */ }

        echo json_encode(['success' => true, 'history' => $history]);
        exit;
    }

    // Alerts / notifications relevant to this janitor
    if ($_GET['action'] === 'get_alerts') {
        $alerts = [];
        try {
            // Notifications directly addressed to this janitor
            $stmt = $conn->prepare("SELECT n.created_at, n.title, n.message, n.bin_id, b.bin_code, n.is_read
                                    FROM notifications n
                                    LEFT JOIN bins b ON n.bin_id = b.bin_id
                                    WHERE n.janitor_id = ? OR (n.janitor_id IS NULL AND n.bin_id IN (SELECT bin_id FROM bins WHERE assigned_to = ?))
                                    ORDER BY n.created_at DESC
                                    LIMIT 200");
            if ($stmt) {
                $stmt->bind_param("ii", $janitorId, $janitorId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $alerts[] = $row;
                $stmt->close();
            }
        } catch (Exception $e) { /* ignore */ }

        echo json_encode(['success' => true, 'alerts' => $alerts]);
        exit;
    }

    // Janitor profile data
    if ($_GET['action'] === 'get_profile') {
        $profile = null;
        try {
            $stmt = $conn->prepare("SELECT janitor_id, first_name, last_name, phone, email FROM janitors WHERE janitor_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $janitorId);
                $stmt->execute();
                $res = $stmt->get_result();
                $profile = $res->fetch_assoc();
                $stmt->close();
            }
        } catch (Exception $e) { /* ignore */ }

        echo json_encode(['success' => true, 'profile' => $profile]);
        exit;
    }
}
// ----------------- end GET endpoints -----------------

// ----------------- existing get_dashboard_stats and page rendering logic -----------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_dashboard_stats') {
    $dashboard_bins = [];
    $assignedBins = 0;
    $fullBins = 0;
    $pendingTasks = 0; // show as number of full bins (bins needing action)
    $completedToday = 0;

    try {
        if ($janitorId > 0) {
            // bins assigned to this janitor (full bins first)
            $bins_query = "SELECT bins.*, CONCAT(j.first_name, ' ', j.last_name) AS janitor_name
                           FROM bins
                           LEFT JOIN janitors j ON bins.assigned_to = j.janitor_id
                           WHERE bins.assigned_to = " . $conn->real_escape_string($janitorId) . "
                           ORDER BY
                             CASE WHEN (bins.status = 'full' OR (bins.capacity IS NOT NULL AND bins.capacity >= 100)) THEN 0 ELSE 1 END,
                             bins.capacity DESC,
                             bins.created_at DESC
                           LIMIT 500";
            $bins_res = $conn->query($bins_query);
            if ($bins_res) {
                while ($r = $bins_res->fetch_assoc()) $dashboard_bins[] = $r;
            }

            // assigned bins count
            $r = $conn->query("SELECT COUNT(*) AS c FROM bins WHERE assigned_to = " . intval($janitorId));
            if ($r && $row = $r->fetch_assoc()) $assignedBins = intval($row['c'] ?? 0);

            // full bins assigned to this janitor
            $r = $conn->query("SELECT COUNT(*) AS c FROM bins WHERE assigned_to = " . intval($janitorId) . " AND (status = 'full' OR (capacity IS NOT NULL AND capacity >= 100))");
            if ($r && $row = $r->fetch_assoc()) $fullBins = intval($row['c'] ?? 0);

            // pending tasks: interpret as number of full bins (awaiting action)
            $pendingTasks = $fullBins;

            // completed today: best-effort attempt - check common table names
            $completedToday = 0;
            $todayStart = date('Y-m-d') . ' 00:00:00';
            $todayEnd = date('Y-m-d') . ' 23:59:59';

            // Try a set of common tables for a completed/emptied action
            $tryTables = [
                "SELECT COUNT(*) AS c FROM tasks WHERE completed_by = " . intval($janitorId) . " AND DATE(completed_at) = CURDATE()",
                "SELECT COUNT(*) AS c FROM task_history WHERE performed_by = " . intval($janitorId) . " AND DATE(performed_at) = CURDATE()",
                "SELECT COUNT(*) AS c FROM bin_logs WHERE performed_by = " . intval($janitorId) . " AND (action = 'emptied' OR action = 'completed') AND DATE(created_at) = CURDATE()",
                "SELECT COUNT(*) AS c FROM activities WHERE user_id = " . intval($janitorId) . " AND action IN ('emptied','completed') AND DATE(created_at) = CURDATE()"
            ];
            foreach ($tryTables as $sql) {
                $r = $conn->query($sql);
                if ($r && $row = $r->fetch_assoc()) {
                    $completedToday = intval($row['c'] ?? 0);
                    break;
                }
            }
        }
    } catch (Exception $e) {
        // ignore and return defaults
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'bins' => $dashboard_bins,
        'assignedBins' => $assignedBins,
        'fullBins' => $fullBins,
        'pendingTasks' => $pendingTasks,
        'completedToday' => $completedToday,
        'janitorId' => $janitorId
    ]);
    exit;
}
// ----------------- end AJAX endpoint -----------------

// ---------------------- fetch initial stats & bins for PHP-rendered page ----------------------
$assignedBins = 0;
$fullBins = 0;
$pendingTasks = 0;
$completedToday = 0;
$dashboard_bins = [];
$recent_alerts = [];

try {
    if ($janitorId > 0) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM bins WHERE assigned_to = " . intval($janitorId));
        if ($r && $row = $r->fetch_assoc()) $assignedBins = intval($row['c'] ?? 0);

        $r = $conn->query("SELECT COUNT(*) AS c FROM bins WHERE assigned_to = " . intval($janitorId) . " AND (bins.status = 'full' OR (bins.capacity IS NOT NULL AND bins.capacity >= 100))");
        if ($r && $row = $r->fetch_assoc()) $fullBins = intval($row['c'] ?? 0);

        $pendingTasks = $fullBins;

        // fetch bins
        $bins_query = "SELECT bins.*, CONCAT(j.first_name, ' ', j.last_name) AS janitor_name
                       FROM bins
                       LEFT JOIN janitors j ON bins.assigned_to = j.janitor_id
                       WHERE bins.assigned_to = " . $conn->real_escape_string($janitorId) . "
                       ORDER BY
                         CASE WHEN (bins.status = 'full' OR (bins.capacity IS NOT NULL AND bins.capacity >= 100)) THEN 0 ELSE 1 END,
                         bins.capacity DESC,
                         bins.created_at DESC
                       LIMIT 200";
        $bins_res = $conn->query($bins_query);
        if ($bins_res) {
            while ($r = $bins_res->fetch_assoc()) $dashboard_bins[] = $r;
        }

        // NOTE: Show all assigned bins in the Recent Alerts table per request.
        $recent_alerts = $dashboard_bins;

        // completed today (same heuristic as AJAX)
        $completedToday = 0;
        $tryTables = [
            "SELECT COUNT(*) AS c FROM tasks WHERE completed_by = " . intval($janitorId) . " AND DATE(completed_at) = CURDATE()",
            "SELECT COUNT(*) AS c FROM task_history WHERE performed_by = " . intval($janitorId) . " AND DATE(performed_at) = CURDATE()",
            "SELECT COUNT(*) AS c FROM bin_logs WHERE performed_by = " . intval($janitorId) . " AND (action = 'emptied' OR action = 'completed') AND DATE(created_at) = CURDATE()",
            "SELECT COUNT(*) AS c FROM activities WHERE user_id = " . intval($janitorId) . " AND action IN ('emptied','completed') AND DATE(created_at) = CURDATE()"
        ];
        foreach ($tryTables as $sql) {
            $r = $conn->query($sql);
            if ($r && $row = $r->fetch_assoc()) {
                $completedToday = intval($row['c'] ?? 0);
                break;
            }
        }
    }
} catch (Exception $e) {
    // ignore and keep defaults
}
// ---------------------- end fetch ----------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Janitor Dashboard - Trashbin Management</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/janitor-dashboard.css">
  <style>
    .bin-detail-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; }
    .bin-status-badge { font-size:0.9rem; padding: .45rem .65rem; }
    .bin-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    @media (max-width:576px){ .bin-detail-grid{grid-template-columns:1fr;} }
    .map-placeholder { background:#f7f7f7; height:140px; display:flex; align-items:center; justify-content:center; color:#888; border-radius:6px; }
    .table-responsive { overflow: visible !important; }
    .action-buttons { position: relative; display:flex; gap:.5rem; align-items:center; justify-content:flex-end; }
    .action-buttons .dropdown-menu { min-width: 220px; max-width: 350px; z-index: 2000; }
  </style>
</head>
<body>
  <!-- Header -->
  <header class="header">
    <div class="header-container">
      <div class="logo-section">
        <div class="logo-wrapper">
          <svg class="animated-logo" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <rect x="30" y="35" width="40" height="50" rx="6" fill="#16a34a"/>
            <rect x="25" y="30" width="50" height="5" fill="#15803d"/>
            <rect x="40" y="20" width="20" height="8" rx="2" fill="#22c55e"/>
            <line x1="40" y1="45" x2="40" y2="80" stroke="#f0fdf4" stroke-width="3" />
            <line x1="50" y1="45" x2="50" y2="80" stroke="#f0fdf4" stroke-width="3" />
            <line x1="60" y1="45" x2="60" y2="80" stroke="#f0fdf4" stroke-width="3" />
          </svg>
        </div>
        <div class="logo-text-section">
          <h1 class="brand-name">Smart Trashbin</h1>
          <p class="header-subtitle">Intelligent Waste Management System</p>
        </div>
      </div>
      <nav class="nav-buttons">
        <!-- IMPORTANT: notificationsBtn uses href="javascript:void(0)" and an ID; click handler added in JS.
             This avoids the anchor navigating to '#' and ensures JS showSection('alerts') runs. -->
        <a class="nav-link notification-link" href="javascript:void(0)" id="notificationsBtn" role="button" aria-label="Open notifications">
          <i class="fa-solid fa-bell" aria-hidden="true"></i>
          <span class="badge rounded-pill bg-danger notification-badge" id="notificationCount" style="display:none;">0</span>
        </a>
        <a id="logoutBtn" class="nav-link logout-link" href="logout.php" title="Logout">
          <i class="fa-solid fa-right-from-bracket"></i>
          <span class="logout-text">Logout</span>
        </a>
      </nav>
    </div>
  </header>

  <div class="dashboard">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <h6 class="sidebar-title">Menu</h6>
      </div>
      <a href="#" class="sidebar-item active" data-section="dashboard" onclick="showSection('dashboard'); return false;">
        <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
      </a>
      <a href="#" class="sidebar-item" data-section="assigned-bins" onclick="showSection('assigned-bins'); return false;">
        <i class="fa-solid fa-trash-alt"></i><span>Assigned Bins</span>
      </a>
      <a href="#" class="sidebar-item" data-section="task-history" onclick="showSection('task-history'); return false;">
        <i class="fa-solid fa-history"></i><span>Task History</span>
      </a>
      <a href="#" class="sidebar-item" data-section="alerts" onclick="showSection('alerts'); return false;">
        <i class="fa-solid fa-bell"></i><span>Alerts</span>
      </a>
      <a href="#" class="sidebar-item" data-section="my-profile" onclick="showSection('my-profile'); return false;">
        <i class="fa-solid fa-user"></i><span>My Profile</span>
      </a>
    </aside>

    <!-- Main content -->
    <main class="content">
      <!-- Dashboard -->
      <section id="dashboardSection" class="content-section">
        <div class="section-header d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle">Welcome back! Here's your daily overview.</p>
          </div>
          <div class="btn-group">
            <button class="btn btn-sm period-btn" onclick="filterDashboard('today')">Today</button>
            <button class="btn btn-sm period-btn" onclick="filterDashboard('week')">Week</button>
            <button class="btn btn-sm period-btn" onclick="filterDashboard('month')">Month</button>
          </div>
        </div>

        <!-- Stats -->
        <div class="row g-4 mb-4">
          <div class="col-md-4">
            <div class="stat-card">
              <div class="stat-icon"><i class="fa-solid fa-trash-alt"></i></div>
              <div class="stat-content">
                <h6>Assigned Bins</h6>
                <h2 id="assignedBinsCount"><?php echo intval($assignedBins); ?></h2>
                <small>Active assignments</small>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="stat-card">
              <div class="stat-icon warning"><i class="fa-solid fa-clock"></i></div>
              <div class="stat-content">
                <h6>Pending Tasks</h6>
                <h2 id="pendingTasksCount"><?php echo intval($pendingTasks); ?></h2>
                <small>Awaiting action</small>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="stat-card">
              <div class="stat-icon success"><i class="fa-solid fa-check-circle"></i></div>
              <div class="stat-content">
                <h6>Completed Today</h6>
                <h2 id="completedTodayCount"><?php echo intval($completedToday); ?></h2>
                <small>Great work!</small>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Alerts (top-level) -->
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Recent Alerts</h5>
              <a href="#" class="btn btn-sm view-all-link" onclick="showSection('alerts'); return false;">View All</a>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table mb-0">
                <thead>
                  <tr><th>Time</th><th>Bin ID</th><th>Location</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody id="recentAlertsBody">
                  <?php if (empty($recent_alerts)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">No recent alerts</td></tr>
                  <?php else: foreach ($recent_alerts as $a): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($a['last_emptied'] ?? $a['updated_at'] ?? $a['created_at'] ?? 'N/A'); ?></td>
                      <td><strong><?php echo htmlspecialchars($a['bin_code'] ?? $a['bin_id']); ?></strong></td>
                      <td><?php echo htmlspecialchars($a['location'] ?? ''); ?></td>
                      <td>
                        <?php
                          $s = $a['status'] ?? '';
                          $display = match(strtolower($s)) {
                            'full' => 'Full',
                            'empty' => 'Empty',
                            'half_full' => 'Half Full',
                            'needs_attention' => 'Needs Attention',
                            'out_of_service' => 'Out of Service',
                            default => $s
                          };
                          $badge = (strtolower($s) === 'full') ? 'danger' : ((strtolower($s) === 'empty') ? 'success' : ((strtolower($s) === 'half_full') ? 'warning' : 'secondary'));
                        ?>
                        <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($display); ?></span>
                      </td>
                      <td class="text-end">
                        <a href="bins.php?action=get_details&bin_id=<?php echo intval($a['bin_id']); ?>" class="btn btn-sm btn-soft-primary">View</a>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- Assigned Bins -->
      <section id="assignedBinsSection" class="content-section" style="display:none;">
        <div class="section-header d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title">Assigned Bins</h1>
            <p class="page-subtitle">Manage and monitor your assigned waste bins.</p>
          </div>
          <div class="d-flex gap-2">
            <div class="input-group" style="max-width: 300px;">
              <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
              <input type="text" class="form-control border-start-0 ps-0" id="searchBinsInput" placeholder="Search bins...">
            </div>
            <div class="dropdown">
              <button class="btn btn-sm filter-btn dropdown-toggle" type="button" id="filterBinsDropdown" data-bs-toggle="dropdown">
                <i class="fas fa-filter me-1"></i>Filter
              </button>
              <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="filterBinsDropdown">
                <li><a class="dropdown-item" href="#" data-filter="all">All Bins</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" data-filter="needs_attention">Needs Attention</a></li>
                <li><a class="dropdown-item" href="#" data-filter="full">Full</a></li>
                <li><a class="dropdown-item" href="#" data-filter="half_full">Half Full</a></li>
                <li><a class="dropdown-item" href="#" data-filter="empty">Empty</a></li>
              </ul>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table mb-0">
                <thead>
                  <tr>
                    <th>Bin ID</th><th>Location</th><th>Type</th><th>Status</th><th>Last Emptied</th><th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody id="assignedBinsBody">
                  <?php if (empty($dashboard_bins)): ?>
                  <tr><td colspan="6" class="text-center py-4 text-muted">No bins assigned</td></tr>
                  <?php else: foreach ($dashboard_bins as $b): ?>
                    <tr data-bin-id="<?php echo intval($b['bin_id']); ?>" data-status="<?php echo htmlspecialchars($b['status'] ?? ''); ?>">
                      <td><strong><?php echo htmlspecialchars($b['bin_code'] ?? $b['bin_id']); ?></strong></td>
                      <td><?php echo htmlspecialchars($b['location'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($b['type'] ?? ''); ?></td>
                      <td>
                        <?php
                          $s = $b['status'] ?? '';
                          $display = match($s) {
                            'full' => 'Full',
                            'empty' => 'Empty',
                            'half_full' => 'Half Full',
                            'needs_attention' => 'Needs Attention',
                            'out_of_service' => 'Out of Service',
                            default => $s
                          };
                          $badge = ($s === 'full') ? 'danger' : (($s === 'empty') ? 'success' : (($s === 'half_full') ? 'warning' : 'secondary'));
                        ?>
                        <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($display); ?></span>
                      </td>
                      <td><?php echo htmlspecialchars($b['last_emptied'] ?? $b['updated_at'] ?? 'N/A'); ?></td>
                      <td class="text-end">
                        <button class="btn btn-sm btn-primary" onclick="openUpdateBinStatusModal(<?php echo intval($b['bin_id']); ?>)">Update</button>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- Task History -->
      <section id="taskHistorySection" class="content-section" style="display:none;">
        <div class="section-header d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title">Task History</h1>
            <p class="page-subtitle">View your completed and ongoing tasks.</p>
          </div>
          <div>
            <button class="btn btn-sm btn-outline-secondary" id="refreshHistoryBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
          </div>
        </div>

        <div class="card">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table mb-0">
                <thead>
                  <tr>
                    <th>Date & Time</th><th>Bin</th><th>Location</th><th>Action</th><th>Status</th><th class="text-end">Details</th>
                  </tr>
                </thead>
                <tbody id="taskHistoryBody">
                  <tr><td colspan="6" class="text-center py-4 text-muted">Loading...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- Alerts -->
      <section id="alertsSection" class="content-section" style="display:none;">
        <div class="section-header d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title">Alerts</h1>
            <p class="page-subtitle">Notifications about assigned bins and system messages.</p>
          </div>
          <div>
            <button class="btn btn-sm btn-outline-secondary" id="markAllReadBtn"><i class="fas fa-check-double"></i> Mark All Read</button>
          </div>
        </div>

        <div class="card">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table mb-0">
                <thead>
                  <tr><th>Time</th><th>Title</th><th class="d-none d-md-table-cell">Message</th><th class="d-none d-lg-table-cell">Target</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody id="alertsBody">
                  <tr><td colspan="5" class="text-center py-4 text-muted">Loading...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- My Profile -->
      <section id="myProfileSection" class="content-section" style="display:none;">
        <div class="section-header d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title">My Profile</h1>
            <p class="page-subtitle">Update your contact details.</p>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <form id="profileForm">
              <input type="hidden" id="profileJanitorId" value="<?php echo $janitorId; ?>">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">First name</label>
                  <input type="text" id="profileFirstName" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Last name</label>
                  <input type="text" id="profileLastName" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone</label>
                  <input type="text" id="profilePhone" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Email</label>
                  <input type="email" id="profileEmail" class="form-control" disabled>
                </div>
              </div>
              <div class="mt-3">
                <button type="button" class="btn btn-primary" id="saveProfileBtn">Save Profile</button>
              </div>
            </form>
          </div>
        </div>
      </section>

    </main>
  </div>

  <!-- Update Bin Status Modal (for Assigned Bins) - NO NOTES -->
  <div class="modal fade" id="updateBinStatusModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-sync-alt me-2"></i>Update Bin Status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="updateBinStatusForm">
            <input type="hidden" id="updateBinId">
            <div class="mb-3">
              <label class="form-label fw-bold">Bin ID</label>
              <p id="updateBinIdDisplay" class="mb-0"></p>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold">Location</label>
              <p id="updateBinLocation" class="mb-0"></p>
            </div>
            <div class="mb-3">
              <label class="form-label">New Status</label>
              <select class="form-control form-select" id="updateNewStatus" required>
                <option value="">Select status...</option>
                <option value="empty">Empty</option>
                <option value="half_full">Half Full</option>
                <option value="needs_attention">Needs Attention</option>
                <option value="full">Full</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Action Type (optional)</label>
              <select class="form-control form-select" id="updateActionType">
                <option value="">Select action...</option>
                <option value="emptied">Emptying Bin</option>
                <option value="cleaning">Cleaning Bin</option>
                <option value="inspection">Inspection</option>
                <option value="maintenance">Maintenance</option>
              </select>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="updateStatusBtn"><i class="fas fa-save me-1"></i>Update Status</button>
        </div>
      </div>
    </div>
  </div>

  <?php include_once __DIR__ . '/includes/footer-admin.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  (function(){
    let currentFilter = 'all';
    let currentSearch = '';

    // -------------------------------------
    // Utility
    // -------------------------------------
    function escapeHtml(s) {
      if (s === null || s === undefined) return '';
      return String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    // Expose showSection so inline onclicks work (this was the reason you saw only '#' before)
    function showSection(name) {
      document.querySelectorAll('.content-section').forEach(s => s.style.display = 'none');
      if (name === 'alerts') {
        document.getElementById('alertsSection').style.display = '';
        loadAlerts();
      } else if (name === 'task-history') {
        document.getElementById('taskHistorySection').style.display = '';
        loadTaskHistory();
      } else if (name === 'my-profile') {
        document.getElementById('myProfileSection').style.display = '';
        loadProfile();
      } else if (name === 'assigned-bins') {
        document.getElementById('assignedBinsSection').style.display = '';
      } else {
        document.getElementById('dashboardSection').style.display = '';
      }
      // highlight sidebar
      document.querySelectorAll('.sidebar-item').forEach(it => it.classList.remove('active'));
      const el = Array.from(document.querySelectorAll('.sidebar-item')).find(a => a.getAttribute('data-section') === name);
      if (el) el.classList.add('active');
    }
    // expose globally
    window.showSection = showSection;

    // -------------------------------------
    // Dashboard / Assigned bins
    // -------------------------------------
    async function loadDashboardData(filter = 'all') {
      try {
        currentFilter = filter || currentFilter || 'all';
        const url = new URL(window.location.href);
        url.searchParams.set('action', 'get_dashboard_stats');
        url.searchParams.set('filter', currentFilter);
        const resp = await fetch(url.toString(), { credentials: 'same-origin' });
        if (!resp.ok) return;
        const data = await resp.json();
        if (!data || !data.success) return;

        document.getElementById('assignedBinsCount').textContent = data.assignedBins ?? (data.bins ? data.bins.length : 0);
        document.getElementById('pendingTasksCount').textContent = data.pendingTasks ?? 0;
        document.getElementById('completedTodayCount').textContent = data.completedToday ?? 0;

        // assigned bins
        const tbody = document.getElementById('assignedBinsBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        const bins = data.bins || [];
        if (!bins.length) {
          tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No bins assigned</td></tr>';
        } else {
          bins.forEach(b => {
            let statusKey = (b.status || '').toString();
            if (statusKey === 'in_progress') statusKey = 'half_full';
            const statusMap = {
              'full': ['danger', 'Full'],
              'empty': ['success', 'Empty'],
              'half_full': ['warning', 'Half Full'],
              'needs_attention': ['info', 'Needs Attention'],
              'out_of_service': ['secondary', 'Out of Service'],
              'disabled': ['secondary', 'Disabled']
            };
            const meta = statusMap[statusKey] || ['secondary', statusKey || 'N/A'];
            const lastEmptied = b.last_emptied || b.updated_at || 'N/A';
            const binCode = b.bin_code || b.bin_id;
            const type = b.type || '';
            const escapedBinId = parseInt(b.bin_id,10);
            tbody.insertAdjacentHTML('beforeend', `
              <tr data-bin-id="${escapedBinId}" data-status="${encodeURIComponent(statusKey)}">
                <td><strong>${escapeHtml(binCode)}</strong></td>
                <td>${escapeHtml(b.location || '')}</td>
                <td>${escapeHtml(type)}</td>
                <td><span class="badge bg-${meta[0]}">${escapeHtml(meta[1])}</span></td>
                <td>${escapeHtml(lastEmptied)}</td>
                <td class="text-end"><button class="btn btn-sm btn-primary" onclick="openUpdateBinStatusModal(${escapedBinId})">Update</button></td>
              </tr>
            `);
          });
        }

        // recent alerts top-of-dashboard (show assigned bins)
        const alertsTbody = document.getElementById('recentAlertsBody');
        if (!alertsTbody) return;
        alertsTbody.innerHTML = '';
        const alerts = data.bins || [];
        if (!alerts.length) {
          alertsTbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No recent alerts</td></tr>';
        } else {
          alerts.forEach(b => {
            let statusKey = (b.status || '').toString();
            if (statusKey === 'in_progress') statusKey = 'half_full';
            const statusMap = {
              'full': ['danger', 'Full'],
              'empty': ['success', 'Empty'],
              'half_full': ['warning', 'Half Full'],
              'needs_attention': ['info', 'Needs Attention'],
              'out_of_service': ['secondary', 'Out of Service'],
              'disabled': ['secondary', 'Disabled']
            };
            const meta = statusMap[statusKey] || ['secondary', statusKey || 'N/A'];
            const time = b.last_emptied || b.updated_at || b.created_at || 'N/A';
            const binCode = b.bin_code || b.bin_id;
            const location = b.location || '';
            alertsTbody.insertAdjacentHTML('beforeend', `
              <tr>
                <td>${escapeHtml(time)}</td>
                <td><strong>${escapeHtml(binCode)}</strong></td>
                <td>${escapeHtml(location)}</td>
                <td><span class="badge bg-${meta[0]}">${escapeHtml(meta[1])}</span></td>
                <td class="text-end"><a href="bins.php?action=get_details&bin_id=${parseInt(b.bin_id,10)}" class="btn btn-sm btn-soft-primary">View</a></td>
              </tr>
            `);
          });
        }

        applySearchFilter();
      } catch (err) {
        console.warn('Dashboard refresh error', err);
      }
    }

    // -------------------------------------
    // Task history loader
    // -------------------------------------
    async function loadTaskHistory() {
      try {
        const resp = await fetch(window.location.pathname + '?action=get_task_history', { credentials: 'same-origin' });
        if (!resp.ok) throw new Error('Network error');
        const json = await resp.json();
        const tbody = document.getElementById('taskHistoryBody');
        tbody.innerHTML = '';
        if (!json.success || !json.history || !json.history.length) {
          tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No task history found</td></tr>';
          return;
        }
        json.history.forEach(h => {
          const dt = h.created_at || 'N/A';
          const bin = h.bin_code || ('Bin #' + (h.bin_id || 'N/A'));
          const loc = h.location || '';
          const action = h.action_type || h.action || '-';
          const status = h.status || '-';
          tbody.insertAdjacentHTML('beforeend', `
            <tr>
              <td>${escapeHtml(dt)}</td>
              <td><strong>${escapeHtml(bin)}</strong></td>
              <td>${escapeHtml(loc)}</td>
              <td>${escapeHtml(action)}</td>
              <td>${escapeHtml(status)}</td>
              <td class="text-end"><button class="btn btn-sm btn-soft-primary" onclick="viewBinDetails(${parseInt(h.bin_id||0,10)})">View</button></td>
            </tr>
          `);
        });
      } catch (e) {
        console.warn('Failed to load history', e);
        document.getElementById('taskHistoryBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Failed to load history</td></tr>';
      }
    }

    // -------------------------------------
    // Alerts loader
    // -------------------------------------
    async function loadAlerts() {
      try {
        const resp = await fetch(window.location.pathname + '?action=get_alerts', { credentials: 'same-origin' });
        if (!resp.ok) throw new Error('Network error');
        const json = await resp.json();
        const tbody = document.getElementById('alertsBody');
        tbody.innerHTML = '';
        if (!json.success || !json.alerts || !json.alerts.length) {
          tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No alerts found</td></tr>';
          return;
        }
        json.alerts.forEach(a => {
          const time = a.created_at || 'N/A';
          const title = a.title || 'Notification';
          const message = a.message || '';
          const target = a.bin_code || (a.bin_id ? ('Bin #' + a.bin_id) : '-');
          tbody.insertAdjacentHTML('beforeend', `
            <tr>
              <td>${escapeHtml(time)}</td>
              <td>${escapeHtml(title)}</td>
              <td class="d-none d-md-table-cell"><small class="text-muted">${escapeHtml(message)}</small></td>
              <td class="d-none d-lg-table-cell">${escapeHtml(target)}</td>
              <td class="text-end"><button class="btn btn-sm btn-primary" onclick="viewBinDetails(${parseInt(a.bin_id||0,10)})">Open</button></td>
            </tr>
          `);
        });
      } catch (e) {
        console.warn('Failed to load alerts', e);
        document.getElementById('alertsBody').innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">Failed to load alerts</td></tr>';
      }
    }

    // -------------------------------------
    // Profile loader & save
    // -------------------------------------
    async function loadProfile() {
      try {
        const resp = await fetch(window.location.pathname + '?action=get_profile', { credentials: 'same-origin' });
        if (!resp.ok) throw new Error('Network error');
        const json = await resp.json();
        if (!json.success || !json.profile) {
          document.getElementById('profileFirstName').value = '';
          document.getElementById('profileLastName').value = '';
          document.getElementById('profilePhone').value = '';
          document.getElementById('profileEmail').value = '';
          return;
        }
        const p = json.profile;
        document.getElementById('profileFirstName').value = p.first_name || '';
        document.getElementById('profileLastName').value = p.last_name || '';
        document.getElementById('profilePhone').value = p.phone || '';
        document.getElementById('profileEmail').value = p.email || '';
      } catch (e) {
        console.warn('Failed to load profile', e);
      }
    }

    async function saveProfile() {
      try {
        const first_name = document.getElementById('profileFirstName').value.trim();
        const last_name = document.getElementById('profileLastName').value.trim();
        const phone = document.getElementById('profilePhone').value.trim();
        const janitor_id = document.getElementById('profileJanitorId').value;

        const formData = new URLSearchParams();
        formData.append('action', 'update_profile');
        formData.append('janitor_id', janitor_id);
        formData.append('first_name', first_name);
        formData.append('last_name', last_name);
        formData.append('phone', phone);

        const resp = await fetch(window.location.pathname, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: formData.toString()
        });
        const json = await resp.json();
        if (json && json.success) {
          alert('Profile updated');
          loadProfile();
        } else {
          alert((json && json.message) ? json.message : 'Failed to update profile');
        }
      } catch (e) {
        console.error('Save profile error', e);
        alert('Server error while updating profile');
      }
    }

    // -------------------------------------
    // Open update modal & submit (no notes)
    // -------------------------------------
    window.openUpdateBinStatusModal = function(binId) {
      fetch('bins.php?action=get_details&bin_id=' + encodeURIComponent(binId), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
          if (!data || !data.success || !data.bin) {
            document.getElementById('updateBinId').value = binId;
            document.getElementById('updateBinIdDisplay').textContent = binId;
            document.getElementById('updateBinLocation').textContent = 'N/A';
            document.getElementById('updateNewStatus').value = '';
          } else {
            const bin = data.bin;
            let curStatus = bin.status || '';
            if (curStatus === 'in_progress') curStatus = 'half_full';
            document.getElementById('updateBinId').value = bin.bin_id || binId;
            document.getElementById('updateBinIdDisplay').textContent = bin.bin_code || ('Bin ' + bin.bin_id);
            document.getElementById('updateBinLocation').textContent = bin.location || 'N/A';
            document.getElementById('updateNewStatus').value = curStatus;
          }
          document.getElementById('updateActionType').value = '';
          new bootstrap.Modal(document.getElementById('updateBinStatusModal')).show();
        })
        .catch(err => {
          console.warn('Failed to fetch bin details', err);
          document.getElementById('updateBinId').value = binId;
          document.getElementById('updateBinIdDisplay').textContent = binId;
          new bootstrap.Modal(document.getElementById('updateBinStatusModal')).show();
        });
    };

    async function submitBinStatusUpdate() {
      const binId = document.getElementById('updateBinId').value;
      let newStatus = document.getElementById('updateNewStatus').value;
      const actionType = document.getElementById('updateActionType').value || '';

      if (!newStatus) { alert('Please select a new status'); return; }
      if (newStatus === 'in_progress') newStatus = 'half_full';

      try {
        const formData = new URLSearchParams();
        formData.append('action', 'janitor_edit_status');
        formData.append('bin_id', binId);
        formData.append('status', newStatus);
        if (actionType) formData.append('action_type', actionType);

        const resp = await fetch(window.location.pathname, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: formData.toString()
        });
        const json = await resp.json();
        if (json && json.success) {
          const modalEl = document.getElementById('updateBinStatusModal');
          const modal = bootstrap.Modal.getInstance(modalEl);
          if (modal) modal.hide();
          await loadDashboardData();
          if (document.getElementById('alertsSection').style.display !== 'none') loadAlerts();
          alert('Status updated successfully');
        } else {
          alert((json && json.message) ? json.message : 'Failed to update status');
        }
      } catch (e) {
        console.error('Update failed', e);
        alert('Server error while updating status');
      }
    }

    // small helper to view details
    window.viewBinDetails = function(binId) {
      window.open('bins.php?action=get_details&bin_id=' + encodeURIComponent(binId), '_blank');
    }

    // -------------------------------------
    // Hook up UI interactions
    // -------------------------------------
    document.addEventListener('click', function(e) {
      if (e.target && e.target.closest && e.target.closest('#updateStatusBtn')) {
        e.preventDefault();
        submitBinStatusUpdate();
      }
      if (e.target && e.target.closest && e.target.closest('#saveProfileBtn')) {
        e.preventDefault();
        saveProfile();
      }
      if (e.target && e.target.closest && e.target.closest('#refreshHistoryBtn')) {
        e.preventDefault();
        loadTaskHistory();
      }
      if (e.target && e.target.closest && e.target.closest('#markAllReadBtn')) {
        e.preventDefault();
        fetch('notifications.php?action=mark_all_read&for=janitor', { method:'POST', credentials:'same-origin' })
          .then(() => loadAlerts()).catch(()=>loadAlerts());
      }
    });

    // search/filter wiring
    function applySearchFilter() {
      const tbody = document.getElementById('assignedBinsBody');
      if (!tbody) return;
      const rows = tbody.querySelectorAll('tr[data-bin-id]');
      rows.forEach(row => {
        const statusEncoded = row.getAttribute('data-status') || '';
        const status = decodeURIComponent(statusEncoded);
        let visible = (currentFilter === 'all') || (status === currentFilter);
        if (visible && currentSearch) {
          const text = row.textContent.toLowerCase();
          visible = text.includes(currentSearch.toLowerCase());
        }
        row.style.display = visible ? '' : 'none';
      });
    }

    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.getElementById('searchBinsInput');
      if (searchInput) {
        searchInput.addEventListener('input', function() {
          currentSearch = this.value.trim();
          applySearchFilter();
        });
      }

      document.querySelectorAll('#assignedBinsSection .dropdown-menu .dropdown-item').forEach(item => {
        item.addEventListener('click', function(e) {
          e.preventDefault();
          let filter = this.getAttribute('data-filter') || 'all';
          if (filter === 'in_progress') filter = 'half_full';
          currentFilter = filter;
          document.querySelectorAll('#assignedBinsSection .dropdown-menu .dropdown-item').forEach(it => it.classList.remove('active'));
          this.classList.add('active');
          loadDashboardData(filter);
        });
      });

      // ensure notifications button opens alerts and doesn't navigate to '#'
      const nb = document.getElementById('notificationsBtn');
      if (nb) {
        nb.addEventListener('click', function(e){
          e.preventDefault();
          showSection('alerts');
        });
      }

      // initial view
      showSection('dashboard');
      loadDashboardData();
      // periodic refresh
      setInterval(()=>loadDashboardData(currentFilter), 30000);
    });

    // Expose functions for console debugging
    window.loadDashboardData = loadDashboardData;
    window.loadAlerts = loadAlerts;
    window.loadTaskHistory = loadTaskHistory;
    window.loadProfile = loadProfile;

  })();
  </script>
</body>
</html>