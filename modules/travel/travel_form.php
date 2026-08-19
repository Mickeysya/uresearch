<?php
include 'db_connect.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Step 1: create the shared application record first
    $student_id = 1; // temporary test value, real login comes later
    $module_type = "travel";
    $status = "pending";

    $stmt = $conn->prepare("INSERT INTO applications (student_id, module_type, status, submitted_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iss", $student_id, $module_type, $status);
    $stmt->execute();

    $application_id = $conn->insert_id; // grabs the ID just created above

    // Step 2: save the travel-specific details, linked by application_id
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $duration = $_POST['duration'];
    $reason = $_POST['reason'];
    $destination = $_POST['destination'];
    $is_international = isset($_POST['is_international']) ? 1 : 0;
    $contact_name = $_POST['contact_name'];
    $contact_no = $_POST['contact_no'];

    $stmt2 = $conn->prepare("INSERT INTO travel_details (application_id, travel_start_date, travel_end_date, duration_days, reason_for_travel, destination_address, is_international, contact_person_name, contact_person_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt2->bind_param("issississ", $application_id, $start_date, $end_date, $duration, $reason, $destination, $is_international, $contact_name, $contact_no);
    $stmt2->execute();

    $message = "Application submitted successfully. Application ID: " . $application_id;
}
?>

<!DOCTYPE html>
<html>
<head><title>Travel Application</title></head>
<body>
    <h2>Travel Application Form</h2>

    <?php if ($message): ?>
        <p style="color: green;"><?php echo $message; ?></p>
    <?php endif; ?>

    <form method="POST">
        Start Date: <input type="date" name="start_date" required><br><br>
        End Date: <input type="date" name="end_date" required><br><br>
        Duration (days): <input type="number" name="duration" required><br><br>
        Reason for Travel: <input type="text" name="reason" required><br><br>
        Destination: <input type="text" name="destination" required><br><br>
        <input type="checkbox" name="is_international"> International Travel<br><br>
        Contact Person Name: <input type="text" name="contact_name"><br><br>
        Contact Person No: <input type="text" name="contact_no"><br><br>
        <button type="submit">Submit</button>
    </form>
</body>
</html>
