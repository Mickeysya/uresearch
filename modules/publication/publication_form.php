<?php
include 'db_connect.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Step 1: create the shared application record first
    $student_id = 1; // temporary test value, real login comes later
    $module_type = "publication";
    $status = "pending";

    $stmt = $conn->prepare("INSERT INTO applications (student_id, module_type, status, submitted_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iss", $student_id, $module_type, $status);
    $stmt->execute();

    $application_id = $conn->insert_id;

    // Step 2: save the publication-specific details, linked by application_id
    $type_of_request = $_POST['type_of_request'];
    $title_of_paper = $_POST['title_of_paper'];
    $conference_or_journal = $_POST['conference_or_journal'];
    $organizer_publisher = $_POST['organizer_publisher'];
    $currency_type = $_POST['currency_type'];
    $cost = $_POST['cost'];
    $wants_lou = isset($_POST['wants_lou']) ? 1 : 0;
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    $stmt2 = $conn->prepare("INSERT INTO publication_details (application_id, type_of_request, title_of_paper, conference_or_journal, organizer_publisher, currency_type, cost_to_be_utilized, wants_letter_of_undertaking, conference_start_date, conference_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt2->bind_param("isssssdiss", $application_id, $type_of_request, $title_of_paper, $conference_or_journal, $organizer_publisher, $currency_type, $cost, $wants_lou, $start_date, $end_date);
    $stmt2->execute();

    $publication_id = $conn->insert_id;

    // Step 3: save the author, linked by publication_id
    $author_name = $_POST['author_name'];
    $organisation = $_POST['organisation'];
    $role_contribution = $_POST['role_contribution'];

    $stmt3 = $conn->prepare("INSERT INTO publication_authors (publication_id, author_name, organisation, role_contribution) VALUES (?, ?, ?, ?)");
    $stmt3->bind_param("isss", $publication_id, $author_name, $organisation, $role_contribution);
    $stmt3->execute();

    $message = "Application submitted successfully. Application ID: " . $application_id;
}
?>

<!DOCTYPE html>
<html>
<head><title>Publication Application</title></head>
<body>
    <h2>Publication Application Form</h2>

    <?php if ($message): ?>
        <p style="color: green;"><?php echo $message; ?></p>
    <?php endif; ?>

    <form method="POST">
        Type of Request:
        <select name="type_of_request">
            <option value="publication_conference">Conference</option>
            <option value="publication_journal">Journal</option>
        </select><br><br>

        Title of Paper: <input type="text" name="title_of_paper" required><br><br>
        Conference/Journal Name: <input type="text" name="conference_or_journal" required><br><br>
        Organizer/Publisher: <input type="text" name="organizer_publisher"><br><br>
        Currency: <input type="text" name="currency_type" placeholder="e.g. MYR"><br><br>
        Cost: <input type="number" step="0.01" name="cost"><br><br>
        <input type="checkbox" name="wants_lou"> Requesting Letter of Undertaking<br><br>
        Conference/Submission Start Date: <input type="date" name="start_date"><br><br>
        Conference/Submission End Date: <input type="date" name="end_date"><br><br>

        <h3>Author</h3>
        Author Name: <input type="text" name="author_name" required><br><br>
        Organisation: <input type="text" name="organisation"><br><br>
        Role/Contribution: <input type="text" name="role_contribution"><br><br>

        <button type="submit">Submit</button>
    </form>
</body>
</html>
