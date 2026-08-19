<?php
include 'db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$student_id = $_SESSION['user_id'];

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Step 1: create the shared application record first
    $module_type = "claims_student";
    $status = "pending";

    $stmt = $conn->prepare("INSERT INTO applications (student_id, module_type, status, submitted_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iss", $student_id, $module_type, $status);
    $stmt->execute();

    $application_id = $conn->insert_id;

    // Step 2: save the claim totals, linked by application_id
    $purpose = $_POST['purpose'];
    $bank_account_no = $_POST['bank_account_no'];
    $total_claim_amount = $_POST['total_claim_amount'];
    $less_cash_advance = $_POST['less_cash_advance'];
    $claim_balance = $total_claim_amount - $less_cash_advance;

    $stmt2 = $conn->prepare("INSERT INTO claims_details (application_id, purpose_of_claim, bank_account_no, total_claim_amount, less_cash_advance, claim_balance) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt2->bind_param("issddd", $application_id, $purpose, $bank_account_no, $total_claim_amount, $less_cash_advance, $claim_balance);
    $stmt2->execute();

    $claim_id = $conn->insert_id;

    // Step 3: loop through each expense item row and insert one row per item
    $item_dates = $_POST['item_date'];
    $travel_froms = $_POST['travel_from'];
    $travel_tos = $_POST['travel_to'];
    $flight_amounts = $_POST['flight_amount'];
    $meal_amounts = $_POST['meal_amount'];
    $lodging_amounts = $_POST['lodging_amount'];
    $misc_amounts = $_POST['misc_amount'];

    for ($i = 0; $i < count($item_dates); $i++) {
        // skip empty rows if the student didn't fill every row in
        if ($item_dates[$i] == "") continue;

        $stmt3 = $conn->prepare("INSERT INTO claims_items (claim_id, item_date, travel_from, travel_to, flight_train_amount, meal_allowance, lodging_amount, misc_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt3->bind_param("isssdddd", $claim_id, $item_dates[$i], $travel_froms[$i], $travel_tos[$i], $flight_amounts[$i], $meal_amounts[$i], $lodging_amounts[$i], $misc_amounts[$i]);
        $stmt3->execute();
    }

    $message = "Application submitted successfully. Application ID: " . $application_id;
}
?>

<!DOCTYPE html>
<html>
<head><title>Claims Application</title></head>
<body>
    <h2>Student Claims Application Form</h2>

    <?php if ($message): ?>
        <p style="color: green;"><?php echo $message; ?></p>
    <?php endif; ?>

    <form method="POST">
        <h3>Claim Details</h3>
        Purpose of Claim: <input type="text" name="purpose" required><br><br>
        Bank Account No: <input type="text" name="bank_account_no" required><br><br>
        Total Claim Amount: <input type="number" step="0.01" name="total_claim_amount" required><br><br>
        Less Cash Advance: <input type="number" step="0.01" name="less_cash_advance" value="0"><br><br>

        <h3>Expense Item 1</h3>
        Date: <input type="date" name="item_date[]"><br><br>
        Travel From: <input type="text" name="travel_from[]"><br><br>
        Travel To: <input type="text" name="travel_to[]"><br><br>
        Flight/Train Amount: <input type="number" step="0.01" name="flight_amount[]" value="0"><br><br>
        Meal Allowance: <input type="number" step="0.01" name="meal_amount[]" value="0"><br><br>
        Lodging: <input type="number" step="0.01" name="lodging_amount[]" value="0"><br><br>
        Miscellaneous: <input type="number" step="0.01" name="misc_amount[]" value="0"><br><br>

        <h3>Expense Item 2</h3>
        Date: <input type="date" name="item_date[]"><br><br>
        Travel From: <input type="text" name="travel_from[]"><br><br>
        Travel To: <input type="text" name="travel_to[]"><br><br>
        Flight/Train Amount: <input type="number" step="0.01" name="flight_amount[]" value="0"><br><br>
        Meal Allowance: <input type="number" step="0.01" name="meal_amount[]" value="0"><br><br>
        Lodging: <input type="number" step="0.01" name="lodging_amount[]" value="0"><br><br>
        Miscellaneous: <input type="number" step="0.01" name="misc_amount[]" value="0"><br><br>

        <button type="submit">Submit</button>
    </form>
</body>
</html>
