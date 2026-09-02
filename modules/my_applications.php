<?php
include 'db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$student_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

function get_stages($module_type, $is_international) {
    if ($module_type == 'travel') {
        if ($is_international) {
            return ["Submitted", "Lecturer/Supervisor", "Chair of Department", "Non-Executive CGS", "Dean of PGR", "Approved"];
        } else {
            return ["Submitted", "Lecturer/Supervisor", "Chair of Department", "Approved"];
        }
    } elseif ($module_type == 'publication') {
        return ["Submitted", "Lecturer/Supervisor", "Chair of Department", "Non-Executive CGS", "Senior Director CGS", "Approved"];
    } else {
        return ["Submitted", "Lecturer/Supervisor", "Chair of Department", "Non-Executive CGS", "Manager CGS", "Approved"];
    }
}

function get_current_index($stages, $status, $current_stage) {
    if ($status == 'approved') return count($stages) - 1;
    if ($status == 'pending') return 1;
    $idx = array_search($current_stage, $stages);
    return $idx !== false ? $idx : 0;
}
function render_stepper($stages, $current_index, $rejected, $rejected_stage) {
    $html = '<div class="stepper">';
    $stop_at = $rejected ? array_search($rejected_stage, $stages) : count($stages);
    if ($stop_at === false) $stop_at = 0;

    for ($i = 0; $i <= $stop_at && $i < count($stages); $i++) {
        if ($rejected && $i == $stop_at) {
            $state = 'rejected';
            $icon = '&#10005;';
        } elseif ($i < $current_index || ($rejected && $i < $stop_at) || ($i == $current_index && $i == count($stages) - 1 && !$rejected)) {
            $state = 'completed';
            $icon = '&#10003;';
        } elseif ($i == $current_index && !$rejected) {
            $state = 'current';
            $icon = $i + 1;
        } else {
            $state = 'upcoming';
            $icon = $i + 1;
        }

        $html .= '<div class="step ' . $state . '"><div class="step-circle">' . $icon . '</div><div class="step-label">' . htmlspecialchars($stages[$i]) . '</div></div>';

        if ($i < $stop_at) {
            $line_state = ($i < $current_index || $rejected) ? 'completed' : '';
            $html .= '<div class="step-line ' . $line_state . '"></div>';
        }
    }
    $html .= '</div>';
    return $html;
}

$apps = $conn->prepare("SELECT * FROM applications WHERE student_id = ? ORDER BY submitted_at DESC");
$apps->bind_param("i", $student_id);
$apps->execute();
$apps_result = $apps->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Applications</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="top-header">
        <img src="images/UResearch_logo.png" alt="UResearch 2.0" class="uresearch-logo">
        <img src="images/UTP_logo.png" alt="UTP Logo" class="utp-logo">
    </div>

    <div class="dashboard-wrapper">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <h2>My Applications</h2>

            <?php if ($apps_result->num_rows == 0): ?>
                <p>You haven't submitted any applications yet.</p>
            <?php endif; ?>

            <?php while ($app = $apps_result->fetch_assoc()): ?>
                <?php
                $is_international = false;
                $detail_line = "";

                if ($app['module_type'] == 'travel') {
                    $d = $conn->prepare("SELECT reason_for_travel, destination_address, is_international FROM travel_details WHERE application_id = ?");
                    $d->bind_param("i", $app['application_id']);
                    $d->execute();
                    $detail = $d->get_result()->fetch_assoc();
                    $is_international = $detail['is_international'];
                    $detail_line = $detail['reason_for_travel'] . " — " . $detail['destination_address'];
                    $module_label = "Travel";
                } elseif ($app['module_type'] == 'publication') {
                    $d = $conn->prepare("SELECT title_of_paper FROM publication_details WHERE application_id = ?");
                    $d->bind_param("i", $app['application_id']);
                    $d->execute();
                    $detail = $d->get_result()->fetch_assoc();
                    $detail_line = $detail['title_of_paper'];
                    $module_label = "Publication";
                } else {
                    $d = $conn->prepare("SELECT purpose_of_claim FROM claims_details WHERE application_id = ?");
                    $d->bind_param("i", $app['application_id']);
                    $d->execute();
                    $detail = $d->get_result()->fetch_assoc();
                    $detail_line = $detail['purpose_of_claim'];
                    $module_label = "Claims";
                }

                $stages = get_stages($app['module_type'], $is_international);
                $current_index = get_current_index($stages, $app['status'], $app['current_stage']);
                $rejected = ($app['status'] == 'rejected');

                $rejected_stage = null;
                $rejected_remarks = null;
                if ($rejected) {
                    $h = $conn->prepare("SELECT stage, remarks FROM approval_history WHERE application_id = ? AND decision = 'rejected' ORDER BY decided_at DESC LIMIT 1");
                    $h->bind_param("i", $app['application_id']);
                    $h->execute();
                    $hrow = $h->get_result()->fetch_assoc();
                    if ($hrow) {
                        $rejected_stage = $hrow['stage'];
                        $rejected_remarks = $hrow['remarks'];
                    }
                }
                ?>
                <div class="app-item">
                    <p><b><?php echo $module_label; ?> Application #<?php echo $app['application_id']; ?></b></p>
                    <p><?php echo htmlspecialchars($detail_line); ?></p>

                    <?php echo render_stepper($stages, $current_index, $rejected, $rejected_stage); ?>

                    <?php if ($rejected): ?>
                        <div class="rejection-note">
                            Rejected at <?php echo htmlspecialchars($rejected_stage); ?>.
                            <?php if ($rejected_remarks): ?>Remarks: <?php echo htmlspecialchars($rejected_remarks); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
</body>
</html>
