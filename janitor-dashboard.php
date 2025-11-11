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

// ----------------- AJAX endpoint to return janitor dashboard stats -----------------
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
            // try 'tasks', 'task_history', 'bin_logs', 'activities' for a completed/emptied action
            $completedToday = 0;
            $todayStart = date('Y-m-d') . ' 00:00:00';
            $todayEnd = date('Y-m-d') . ' 23:59:59';

            // 1) try tasks table
            $tryTables = [
                "tasks" => "SELECT COUNT(*) AS c FROM tasks WHERE completed_by = " . intval($janitorId) . " AND DATE(completed_at) = CURDATE()",
                "task_history" => "SELECT COUNT(*) AS c FROM task_history WHERE performed_by = " . intval($janitorId) . " AND DATE(performed_at) = CURDATE()",
                "bin_logs" => "SELECT COUNT(*) AS c FROM bin_logs WHERE performed_by = " . intval($janitorId) . " AND (action = 'emptied' OR action = 'completed') AND DATE(created_at) = CURDATE()",
                "activities" => "SELECT COUNT(*) AS c FROM activities WHERE user_id = " . intval($janitorId) . " AND action IN ('emptied','completed') AND DATE(created_at) = CURDATE()"
            ];
            foreach ($tryTables as $table => $sql) {
                $r = $conn->query($sql);
                if ($r && $row = $r->fetch_assoc()) {
                    $completedToday = intval($row['c'] ?? 0);
                    // if we found any reasonable result (including zero), accept it and break
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

try {
    if ($janitorId > 0) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM bins WHERE assigned_to = " . intval($janitorId));
        if ($r && $row = $r->fetch_assoc()) $assignedBins = intval($row['c'] ?? 0);

        $r = $conn->query("SELECT COUNT(*) AS c FROM bins WHERE assigned_to = " . intval($janitorId) . " AND (status = 'full' OR (capacity IS NOT NULL AND capacity >= 100))");
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

        // completed today (same heuristic as AJAX)
        $completedToday = 0;
        $tryTables = [
            "tasks" => "SELECT COUNT(*) AS c FROM tasks WHERE completed_by = " . intval($janitorId) . " AND DATE(completed_at) = CURDATE()",
            "task_history" => "SELECT COUNT(*) AS c FROM task_history WHERE performed_by = " . intval($janitorId) . " AND DATE(performed_at) = CURDATE()",
            "bin_logs" => "SELECT COUNT(*) AS c FROM bin_logs WHERE performed_by = " . intval($janitorId) . " AND (action = 'emptied' OR action = 'completed') AND DATE(created_at) = CURDATE()",
            "activities" => "SELECT COUNT(*) AS c FROM activities WHERE user_id = " . intval($janitorId) . " AND action IN ('emptied','completed') AND DATE(created_at) = CURDATE()"
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
</head>
<body>
  <!-- Premium Header with Animated Logo -->
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
        <a class="nav-link notification-link" href="#" id="notificationsBtn" role="button" aria-label="Open notifications" onclick="openNotificationsModal(event)">
          <i class="fa-solid fa-bell" aria-hidden="true"></i>
          <span class="badge rounded-pill bg-danger notification-badge" id="notificationCount" style="display:none;">0</span>
        </a>
  <a id="logoutBtn" class="nav-link logout-link" href="#" onclick="showLogoutModal(event)" title="Logout">
          <i class="fa-solid fa-right-from-bracket"></i>
          <span class="logout-text">Logout</span>
        </a>
      </nav>
    </div>
  </header>

  <div class="dashboard">
    <!-- Animated Background Circles -->
    <div class="background-circle background-circle-1"></div>
    <div class="background-circle background-circle-2"></div>
    <div class="background-circle background-circle-3"></div>
    
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <h6 class="sidebar-title">Menu</h6>
      </div>
      <a href="#" class="sidebar-item active" data-section="dashboard" role="button" onclick="showSection('dashboard'); return false;">
        <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
      </a>
      <a href="#" class="sidebar-item" data-section="assigned-bins" role="button" onclick="showSection('assigned-bins'); return false;">
        <i class="fa-solid fa-trash-alt"></i><span>Assigned Bins</span>
      </a>
      <a href="#" class="sidebar-item" data-section="task-history" role="button" onclick="showSection('task-history'); return false;">
        <i class="fa-solid fa-history"></i><span>Task History</span>
      </a>
      <a href="#" class="sidebar-item" data-section="alerts" role="button" onclick="showSection('alerts'); return false;">
        <i class="fa-solid fa-bell"></i><span>Alerts</span>
      </a>
      <a href="#" class="sidebar-item" data-section="my-profile" role="button" onclick="showSection('my-profile'); return false;">
        <i class="fa-solid fa-user"></i><span>My Profile</span>
      </a>
    </aside>

    <!-- Main Content -->
    <main class="content">
      <!-- Dashboard Section -->
      <section id="dashboardSection" class="content-section">
        <div class="section-header">
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

        <!-- Stats Cards -->
        <div class="row g-4 mb-5">
          <div class="col-md-4">
            <div class="stat-card">
              <div class="stat-icon">
                <i class="fa-solid fa-trash-alt"></i>
              </div>
              <div class="stat-content">
                <h6>Assigned Bins</h6>
                <h2 id="assignedBinsCount"><?php echo intval($assignedBins); ?></h2>
                <small><i class="fas fa-info-circle me-1"></i>Active assignments</small>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="stat-card">
              <div class="stat-icon warning">
                <i class="fa-solid fa-clock"></i>
              </div>
              <div class="stat-content">
                <h6>Pending Tasks</h6>
                <h2 id="pendingTasksCount"><?php echo intval($pendingTasks); ?></h2>
                <small><i class="fas fa-clock me-1"></i>Awaiting action</small>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="stat-card">
              <div class="stat-icon success">
                <i class="fa-solid fa-check-circle"></i>
              </div>
              <div class="stat-content">
                <h6>Completed Today</h6>
                <h2 id="completedTodayCount"><?php echo intval($completedToday); ?></h2>
                <small><i class="fas fa-check-circle me-1"></i>Great work!</small>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Alerts -->
        <div class="card">
          <div class="card-header alert-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Recent Alerts</h5>
              <a href="#" class="btn btn-sm view-all-link" onclick="showSection('alerts'); return false;">View All</a>
            </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table mb-0">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Bin ID</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody id="recentAlertsBody">
                  <tr>
                    <td colspan="5" class="text-center py-4 text-muted">No recent alerts</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- Assigned Bins Section -->
      <section id="assignedBinsSection" class="content-section" style="display:none;">
        <div class="section-header">
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
                <li><a class="dropdown-item" href="#" data-filter="in_progress">In Progress</a></li>
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
                    <th>Bin ID</th>
                    <th>Location</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Last Emptied</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody id="assignedBinsBody">
                  <?php if (empty($dashboard_bins)): ?>
                  <tr>
                    <td colspan="6" class="text-center py-4 text-muted">No bins assigned</td>
                  </tr>
                  <?php else: ?>
                    <?php foreach ($dashboard_bins as $b): ?>
                      <tr>
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
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- Task History Section -->
      <section id="taskHistorySection" class="content-section" style="display:none;">
        <div class="section-header">
          <div>
            <h1 class="page-title">Task History</h1>
            <p class="page-subtitle">View your completed and ongoing tasks.</p>
          </div>
            <div class="d-flex gap-2">
            <div class="input-group" style="max-width: 200px;">
              <span class="input-group-text bg-white"><i class="fas fa-calendar-alt text-muted"></i></span>
              <input type="date" class="form-control" id="historyDateFilter">
            </div>
            <button class="btn btn-primary btn-sm filter-btn" id="filterHistoryBtn"><i class="fas fa-filter me-1"></i>Apply Filters</button>
          </div>
        </div>

        <div class="card">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table mb-0">
                <thead>
                  <tr>
                    <th>Date & Time</th>
                    <th>Bin ID</th>
                    <th>Location</th>
                    <th>Action</th>
                    <th>Status</th>
                    <th class="text-end">Details</th>
                  </tr>
                </thead>
                <tbody id="taskHistoryBody">
                  <tr>
                    <td colspan="6" class="text-center py-4 text-muted">No task history found</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- Alerts Section -->
      <section id="alertsSection" class="content-section" style="display:none;">
        <div class="section-header">
          <div>
            <h1 class="page-title">Alerts Dashboard</h1>
            <p class="page-subtitle">Monitor critical and important notifications.</p>
          </div>
          <div class="d-flex gap-2 align-items-center">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="alertSoundSwitch" checked>
              <label class="form-check-label" for="alertSoundSwitch">Alert Sound</label>
            </div>
            <div class="dropdown">
              <button class="btn btn-sm filter-btn dropdown-toggle" type="button" id="filterAlertsDropdown" data-bs-toggle="dropdown">
                <i class="fas fa-filter me-1"></i>Filter
              </button>
              <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="filterAlertsDropdown">
                <li><a class="dropdown-item active" href="#" data-filter="all">All Alerts</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" data-filter="critical">Critical</a></li>
                <li><a class="dropdown-item" href="#" data-filter="warning">Warning</a></li>
                <li><a class="dropdown-item" href="#" data-filter="info">Info</a></li>
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
                    <th>Time</th>
                    <th>Bin ID</th>
                    <th>Location</th>
                    <th>Alert Type</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody id="alertsTableBody">
                  <tr>
                    <td colspan="6" class="text-center py-4 text-muted">No alerts found</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="btn-group">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="markAllReadBtn"><i class="fas fa-check-double me-1"></i>Mark All as Read</button>
              <button type="button" class="btn btn-sm btn-outline-danger" id="clearAlertsBtn"><i class="fas fa-trash-alt me-1"></i>Clear All</button>
            </div>
          </div>
        </div>
      </section>

      <!-- My Profile Section -->
      <section id="myProfileSection" class="content-section" style="display:none;">
        <div class="section-header">
          <div>
            <h1 class="page-title">My Profile</h1>
            <p class="page-subtitle">Manage your personal information and settings.</p>
          </div>
        </div>
        
        <!-- Enhanced profile layout with premium card design and better spacing -->
        <div class="profile-container">
          <!-- Profile Header Card -->
          <div class="profile-header-card">
            <div class="profile-header-content">
              <div class="profile-picture-wrapper">
                <img id="profileImg" src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['name'] ?? 'Janitor'); ?>&background=0D6EFD&color=fff&size=150" 
                     alt="Profile Picture" class="profile-picture">
                <input type="file" id="photoInput" accept=".png,.jpg,.jpeg" style="display: none;">
                <button type="button" class="profile-edit-btn" id="changePhotoBtn" title="Change Photo" aria-label="Change profile photo" aria-controls="photoInput" tabindex="0">
                  <i class="fa-solid fa-camera"></i>
                </button>
              </div>
              <div class="profile-info">
                <h2 class="profile-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Janitor'); ?></h2>
                <p class="profile-role">Janitor</p>
                <div id="photoMessage" class="validation-message"></div>
              </div>
            </div>
          </div>

          <!-- Profile Content Grid -->
          <div class="profile-content-grid">
            <!-- Left Column - Quick Stats -->
            <div class="profile-sidebar">
              <div class="profile-stats-card">
                <h6 class="stats-title">Quick Stats</h6>
                <div class="stat-item">
                  <span class="stat-label">Tasks Completed</span>
                  <span class="stat-value"><?php echo intval($completedToday); ?></span>
                </div>
                <div class="stat-item">
                  <span class="stat-label">Bins Managed</span>
                  <span class="stat-value"><?php echo intval($assignedBins); ?></span>
                </div>
                <div class="stat-item">
                  <span class="stat-label">Member Since</span>
                  <span class="stat-value">2024</span>
                </div>
              </div>

              <div class="profile-menu-card">
                <h6 class="menu-title">Settings</h6>
                <a href="#personal-info" class="profile-menu-item active" data-bs-toggle="list">
                  <i class="fa-solid fa-user"></i>
                  <span>Personal Information</span>
                </a>
                <a href="#change-password" class="profile-menu-item" data-bs-toggle="list">
                  <i class="fa-solid fa-key"></i>
                  <span>Change Password</span>
                </a>
              </div>
            </div>

            <!-- Right Column - Forms -->
            <div class="profile-main">
              <div class="tab-content">
                <!-- Personal Information Tab -->
                <div class="tab-pane fade show active" id="personal-info">
                  <div class="profile-form-card">
                    <div class="form-card-header">
                      <h5><i class="fa-solid fa-user-circle me-2"></i>Personal Information</h5>
                    </div>
                    <div class="form-card-body">
                      <div id="personalInfoAlert" class="alert alert-message" role="alert"></div>
                      <form id="personalInfoForm">
                        <div class="form-row">
                          <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" id="firstName" value="<?php echo explode(' ', $_SESSION['name'] ?? 'John')[0]; ?>" required>
                            <div class="validation-message"></div>
                          </div>
                          <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="lastName" value="<?php echo explode(' ', $_SESSION['name'] ?? 'Doe')[1] ?? ''; ?>" required>
                            <div class="validation-message"></div>
                          </div>
                        </div>
                        <div class="form-group">
                          <label class="form-label">Email Address</label>
                          <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($_SESSION['email'] ?? 'janitor@example.com'); ?>" required>
                          <div class="validation-message"></div>
                        </div>
                        <div class="form-group">
                          <label class="form-label">Phone Number</label>
                          <input type="tel" class="form-control" id="phoneNumber" value="+1 (555) 123-4567" placeholder="11 digits">
                          <div class="validation-message"></div>
                        </div>
                        <div class="form-group">
                          <label class="form-label">Assigned Area</label>
                          <input type="text" class="form-control" value="Downtown District" readonly style="background-color: #f5f5f5; cursor: not-allowed;">
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg">
                          <i class="fa-solid fa-save me-2"></i>Save Changes
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
                
                <!-- Change Password Tab -->
                <div class="tab-pane fade" id="change-password">
                  <div class="profile-form-card">
                    <div class="form-card-header">
                      <h5><i class="fa-solid fa-lock me-2"></i>Change Password</h5>
                    </div>
                    <div class="form-card-body">
                      <div id="passwordAlert" class="alert alert-message" role="alert"></div>
                      <form id="changePasswordForm">
                        <div class="form-group">
                          <label class="form-label">Current Password</label>
                          <div class="password-input-container">
                            <input type="password" class="form-control password-input" id="currentPassword" placeholder="Enter current password" required>
                            <button type="button" class="password-toggle-btn" data-target="#currentPassword">
                              <i class="fa-solid fa-eye"></i>
                            </button>
                          </div>
                          <div class="validation-message"></div>
                        </div>
                        <div class="form-group">
                          <label class="form-label">New Password</label>
                          <div class="password-input-container">
                            <input type="password" class="form-control password-input" id="newPassword" placeholder="Enter new password" required>
                            <button type="button" class="password-toggle-btn" data-target="#newPassword">
                              <i class="fa-solid fa-eye"></i>
                            </button>
                          </div>
                          <div class="validation-message"></div>
                          <div class="password-strength">
                            <small>Password strength:</small>
                            <div class="strength-bar">
                              <div class="strength-fill"></div>
                            </div>
                          </div>
                        </div>
                        <div class="form-group">
                          <label class="form-label">Confirm New Password</label>
                          <div class="password-input-container">
                            <input type="password" class="form-control password-input" id="confirmNewPassword" placeholder="Confirm new password" required>
                            <button type="button" class="password-toggle-btn" data-target="#confirmNewPassword">
                              <i class="fa-solid fa-eye"></i>
                            </button>
                          </div>
                          <div class="validation-message"></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg">
                          <i class="fa-solid fa-lock me-2"></i>Update Password
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- Status Update Modal -->
  <div class="modal fade" id="statusUpdateModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Update Bin Status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="statusUpdateForm">
            <input type="hidden" id="binIdInput">
            <div class="mb-3">
              <label for="statusSelect" class="form-label">Status <span class="text-danger">*</span></label>
              <select class="form-select form-select-lg" id="statusSelect" required>
                <option value="" disabled selected>Select status...</option>
                <option value="empty">Empty</option>
                <option value="in_progress">In Progress</option>
                <option value="full">Full</option>
                <option value="needs_attention">Needs Attention</option>
                <option value="out_of_service">Out of Service</option>
              </select>
            </div>
            <div class="mb-4">
              <label for="notesInput" class="form-label">Notes <small class="text-muted">(Optional)</small></label>
              <textarea class="form-control" id="notesInput" rows="3" placeholder="Add any additional notes..."></textarea>
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

  <!-- Notifications Dropdown Panel -->
  <div class="modal fade" id="notificationsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-bell me-2"></i>Notifications</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
          <div id="notificationsPanel">
            <div class="text-center py-4 text-muted">
              <i class="fas fa-inbox" style="font-size: 40px; opacity: 0.5;"></i>
              <p class="mt-2">No notifications</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Task History Details Modal -->
  <div class="modal fade" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Task Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-bold">Date & Time</label>
            <p id="detailDate" class="mb-0"></p>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Bin ID</label>
            <p id="detailBinId" class="mb-0"></p>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Location</label>
            <p id="detailLocation" class="mb-0"></p>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Action</label>
            <p id="detailAction" class="mb-0"></p>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Status</label>
            <p id="detailStatus" class="mb-0"></p>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Notes</label>
            <p id="detailNotes" class="mb-0"></p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Handle Alert Modal -->
  <div class="modal fade" id="handleAlertModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Handle Alert</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="handleAlertForm">
            <input type="hidden" id="handleAlertBinId">
            <div class="mb-3">
              <label class="form-label fw-bold">Bin ID</label>
              <p id="handleBinId" class="mb-0"></p>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold">Location</label>
              <p id="handleLocation" class="mb-0"></p>
            </div>
            <div class="mb-3">
              <label class="form-label">Action Taken</label>
              <select class="form-control form-select" id="handleAction" required>
                <option value="">Select action...</option>
                <option value="emptied">Bin Emptied</option>
                <option value="maintenance">Maintenance Performed</option>
                <option value="inspected">Bin Inspected</option>
                <option value="repaired">Bin Repaired</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Notes</label>
              <textarea class="form-control" id="handleNotes" rows="3" placeholder="Enter any additional notes..."></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">Completion Status</label>
              <select class="form-control form-select" id="handleStatus" required>
                <option value="completed">Completed</option>
                <option value="in_progress">In Progress</option>
                <option value="pending">Pending</option>
              </select>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="submitHandleAlert()">
            <i class="fas fa-save me-1"></i>Submit Action
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Update Bin Status Modal (for Assigned Bins) -->
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
                <option value="in_progress">In Progress</option>
                <option value="needs_attention">Needs Attention</option>
                <option value="full">Full</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Action Type</label>
              <select class="form-control form-select" id="updateActionType" required>
                <option value="">Select action...</option>
                <option value="emptied">Emptying Bin</option>
                <option value="cleaning">Cleaning Bin</option>
                <option value="inspection">Inspection</option>
                <option value="maintenance">Maintenance</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Notes</label>
              <textarea class="form-control" id="updateStatusNotes" rows="3" placeholder="Enter any additional notes..."></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="submitBinStatusUpdate()">
            <i class="fas fa-save me-1"></i>Update Status
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Logout Confirmation Modal -->
  <div class="premium-modal" id="logoutModal">
    <div class="premium-modal-overlay" onclick="closeLogoutModal()"></div>
    <div class="premium-modal-content">
      <div class="premium-modal-header">
        <div class="modal-icon-wrapper">
          <i class="fa-solid fa-right-from-bracket"></i>
        </div>
        <h3 class="modal-title">Confirm Logout</h3>
        <p class="modal-subtitle">Are you sure you want to logout?</p>
      </div>
      <div class="premium-modal-footer">
        <button class="btn-modal btn-cancel" onclick="closeLogoutModal()">
          <i class="fa-solid fa-times me-2"></i>Cancel
        </button>
        <button class="btn-modal btn-confirm" onclick="confirmLogout()">
          <i class="fa-solid fa-check me-2"></i>Yes, Logout
        </button>
      </div>
    </div>
  </div>

  <!-- Privacy Policy Modal -->
  <div class="info-modal" id="privacyModal">
    <div class="info-modal-overlay" onclick="closeInfoModal()"></div>
    <div class="info-modal-content">
      <div class="info-modal-header">
        <h2 class="info-modal-title">
          <i class="fa-solid fa-shield-halved"></i>
          Privacy Policy
        </h2>
        <button class="info-modal-close" onclick="closeInfoModal()">
          <i class="fa-solid fa-times"></i>
        </button>
      </div>
      <div class="info-modal-body">
        <p><strong>Effective Date:</strong> November 2025</p>
        
        <h3>1. Information We Collect</h3>
        <p>Smart Trashbin collects information necessary to provide efficient waste management services:</p>
        <ul>
          <li><strong>User Account Information:</strong> Name, email address, employee ID, and role designation</li>
          <li><strong>Bin Usage Data:</strong> Fill levels, collection times, location data, and waste type categorization</li>
          <li><strong>System Activity:</strong> Task completion records, maintenance logs, and alert notifications</li>
          <li><strong>Device Information:</strong> IP addresses, browser types, and device identifiers for system security</li>
        </ul>

        <h3>2. How We Use Your Information</h3>
        <p>We use collected data to:</p>
        <ul>
          <li>Monitor and optimize waste collection routes and schedules</li>
          <li>Track bin capacity and trigger timely collection alerts</li>
          <li>Generate reports and analytics for facility management</li>
          <li>Maintain system security and prevent unauthorized access</li>
          <li>Improve our smart waste management services</li>
        </ul>

        <h3>3. Data Security</h3>
        <p>We implement industry-standard security measures to protect your data, including encryption, secure servers, and regular security audits. Access to personal information is restricted t[...]
      </div>
    </div>
  </div>

  <div class="footer">
      <div class="footer-content">
          <div class="footer-links">
              <a href="#" onclick="openPrivacyModal(event)">Privacy Policy</a>
              <span class="separator">•</span>
              <a href="#" onclick="openTermsModal(event)">Terms of Service</a>
              <span class="separator">•</span>
              <a href="#" onclick="openSupportModal(event)">Support</a>
          </div>
          <p class="footer-text" id="footerText">Making waste management smarter, one bin at a time.</p>
          <p class="footer-copyright">
              &copy; 2025 Smart Trashbin. All rights reserved.
          </p>
      </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/janitor-dashboard.js"></script>

  <!-- Client-side refresh to fetch the same server-side stats used above -->
  <script>
    async function loadDashboardData() {
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('action', 'get_dashboard_stats');
        const resp = await fetch(url.toString(), { credentials: 'same-origin' });
        if (!resp.ok) return;
        const data = await resp.json();
        if (!data || !data.success) return;

        // Update stat cards
        document.getElementById('assignedBinsCount').textContent = data.assignedBins ?? (data.bins ? data.bins.length : 0);
        document.getElementById('pendingTasksCount').textContent = data.pendingTasks ?? 0;
        document.getElementById('completedTodayCount').textContent = data.completedToday ?? 0;

        // Rebuild assigned bins table
        const tbody = document.getElementById('assignedBinsBody');
        if (!tbody) return;
        if (!data.bins || !data.bins.length) {
          tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No bins assigned</td></tr>';
          return;
        }
        tbody.innerHTML = '';
        data.bins.forEach(b => {
          const statusMap = {
            'full': ['danger', 'Full'],
            'empty': ['success', 'Empty'],
            'half_full': ['warning', 'Half Full'],
            'needs_attention': ['info', 'Needs Attention'],
            'out_of_service': ['secondary', 'Out of Service']
          };
          const meta = statusMap[b.status] || ['secondary', b.status || 'N/A'];
          const lastEmptied = b.last_emptied || b.updated_at || 'N/A';
          const binCode = b.bin_code || b.bin_id;
          const type = b.type || '';
          tbody.insertAdjacentHTML('beforeend', `
            <tr>
              <td><strong>${escapeHtml(binCode)}</strong></td>
              <td>${escapeHtml(b.location || '')}</td>
              <td>${escapeHtml(type)}</td>
              <td><span class="badge bg-${meta[0]}">${escapeHtml(meta[1])}</span></td>
              <td>${escapeHtml(lastEmptied)}</td>
              <td class="text-end"><button class="btn btn-sm btn-primary" onclick="openUpdateBinStatusModal(${parseInt(b.bin_id,10)})">Update</button></td>
            </tr>
          `);
        });
      } catch (err) {
        // silent
        console.warn('Dashboard refresh error', err);
      }
    }

    function escapeHtml(s) {
      if (s === null || s === undefined) return '';
      return String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    // Hook up modal-opening helper used in rows
    function openUpdateBinStatusModal(binId) {
      // populate minimal info and open modal - page has updateBinStatusModal
      document.getElementById('updateBinId').value = binId;
      document.getElementById('updateBinIdDisplay').textContent = binId;
      const modalEl = document.getElementById('updateBinStatusModal');
      const modal = new bootstrap.Modal(modalEl);
      modal.show();
    }

    document.addEventListener('DOMContentLoaded', function() {
      loadDashboardData();
      setInterval(loadDashboardData, 30000); // refresh every 30s

      // keep notification/logout behavior consistent
      try {
        const notifBtn = document.getElementById('notificationsBtn');
        if (notifBtn) {
          notifBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof openNotificationsModal === 'function') openNotificationsModal(e);
            else {
              const modalEl = document.getElementById('notificationsModal');
              if (modalEl) new bootstrap.Modal(modalEl).show();
            }
          });
        }
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
          logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof showLogoutModal === 'function') showLogoutModal(e);
            else {
              const modalEl = document.getElementById('logoutModal');
              if (modalEl) new bootstrap.Modal(modalEl).show();
              else window.location.href = 'logout.php';
            }
          });
        }
      } catch (err) {
        console.warn('Header handlers error', err);
      }
    });
  </script>
</body>
</html>