<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "assignment_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// File upload handling
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, "r");

    // Create temporary table
    $createTempTable = "
        CREATE TEMPORARY TABLE temp_import (
            id INT AUTO_INCREMENT PRIMARY KEY,
            voucher_number VARCHAR(255),
            trans_date DATE,
            admission_number VARCHAR(255),
            display_receipt_number VARCHAR(255),
            roll_number VARCHAR(255),
            paid_date DATE,
            due_amount DECIMAL(15,2),
            paid_amount DECIMAL(15,2),
            concession DECIMAL(15,2),
            scholarship DECIMAL(15,2),
            refund DECIMAL(15,2)
        )
    ";
    $conn->query($createTempTable);

    // Import CSV data into temporary table
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $stmt = $conn->prepare("
            INSERT INTO temp_import (
                voucher_number, trans_date, admission_number, display_receipt_number, roll_number, paid_date,
                due_amount, paid_amount, concession, scholarship, refund
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssssssdddd",
            $data[0],
            $data[1],
            $data[2],
            $data[3],
            $data[4],
            $data[5],
            $data[6],
            $data[7],
            $data[8],
            $data[9],
            $data[10]
        );
        $stmt->execute();
    }
    fclose($handle);

    // Verify imported data
    $result = $conn->query("SELECT COUNT(*) as total_records, SUM(due_amount) as total_due, SUM(paid_amount) as total_paid, SUM(concession) as total_concession, SUM(scholarship) as total_scholarship, SUM(refund) as total_refund FROM temp_import");
    $row = $result->fetch_assoc();

    echo "Total Records: " . $row['total_records'] . "<br>";
    echo "Due Amount: " . $row['total_due'] . "<br>";
    echo "Paid Amount: " . $row['total_paid'] . "<br>";
    echo "Concession: " . $row['total_concession'] . "<br>";
    echo "Scholarship: " . $row['total_scholarship'] . "<br>";
    echo "Refund: " . $row['total_refund'] . "<br>";

    // Distribute data to relevant tables
    $conn->query("
        INSERT INTO financial_trans (voucher_number, trans_date, admission_number, amount)
        SELECT voucher_number, trans_date, admission_number, SUM(due_amount + paid_amount + concession + scholarship + refund)
        FROM temp_import
        WHERE voucher_number IN ('DUE', 'SCHOLARSHIP', 'CONCESSION')
        GROUP BY voucher_number, trans_date, admission_number
    ");

    $conn->query("
        INSERT INTO common_fee_collection (display_receipt_number, admission_number, roll_number, paid_date, amount)
        SELECT display_receipt_number, admission_number, roll_number, paid_date, SUM(due_amount + paid_amount + concession + scholarship + refund)
        FROM temp_import
        WHERE voucher_number NOT IN ('DUE', 'SCHOLARSHIP', 'CONCESSION')
        GROUP BY display_receipt_number, admission_number, roll_number, paid_date
    ");

    echo "Data distribution completed.";
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Data Import</title>
    <!-- Bootstrap CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card mt-5">
                    <div class="card-header">
                        <h3 class="text-center">Upload CSV File</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="csv_file">Choose CSV File</label>
                                <input type="file" name="csv_file" id="csv_file" class="form-control-file" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">Upload</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Bootstrap JS and dependencies -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>

</html>