<?php
// show all errors
error_reporting(E_ALL);
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
            sr_no INT,
            tranDate DATE,
            acadYear VARCHAR(15),
            financialYear VARCHAR(15),
            category VARCHAR(50),
            Entrymode INT,
            voucherno INT,
            rollno VARCHAR(50),
            admno VARCHAR(50),
            status VARCHAR(50),
            fee_category VARCHAR(100),
            branch_name VARCHAR(100),
            program VARCHAR(100),
            department VARCHAR(100),
            batch VARCHAR(100),
            receiptId VARCHAR(50),
            f_name VARCHAR(255),
            due_amount DECIMAL(12,2),
            paid_amount DECIMAL(12,2),
            concession_amount DECIMAL(12,2),
            scholarship_amount DECIMAL(12,2),
            rev_concession_amount DECIMAL(12,2),
            write_off_amount DECIMAL(12,2),
            adjusted_amount DECIMAL(12,2),
            refund_amount DECIMAL(12,2),
            fund_transfer_amount DECIMAL(12,2),
            remarks VARCHAR(255)
        )
    ";
    $conn->query($createTempTable);

    // Import CSV data into temporary table
    // Skip the first 6 lines
    for ($i = 0; $i < 6; $i++) {
        fgetcsv($handle, 1000, ",");
    }

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $stmt = $conn->prepare("
            INSERT INTO temp_import (
                sr_no,
                tranDate,
                acadYear,
                financialYear,
                category,
                Entrymode,
                voucherno,
                rollno,
                admno,
                status,
                fee_category,
                branch_name,
                program,
                department,
                batch,
                receiptId,
                f_name,
                due_amount,
                paid_amount,
                concession_amount,
                scholarship_amount,
                rev_concession_amount,
                write_off_amount,
                adjusted_amount,
                refund_amount,
                fund_transfer_amount,
                remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssssisissssssssssssddddddds",
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
            $data[10],
            $data[11],
            $data[12],
            $data[13],
            $data[14],
            $data[15],
            $data[16],
            $data[17],
            $data[18],
            $data[19],
            $data[20],
            $data[21],
            $data[22],
            $data[23],
            $data[24],
            $data[25],
            $data[26]
        );

        $stmt->execute();
    }

    fclose($handle);

    // Verify imported data
    $result = $conn->query("
        SELECT 
            COUNT(*) AS total_records,
            SUM(due_amount) AS total_due,
            SUM(paid_amount) AS total_paid,
            SUM(concession_amount) AS total_concession,
            SUM(scholarship_amount) AS total_scholarship,
            SUM(refund_amount) AS total_refund            
        FROM temp_import");

    $row = $result->fetch_assoc();

    echo "Total Records: " . $row['total_records'] . "<br>";
    echo "Due Amount: " . $row['total_due'] . "<br>";
    echo "Paid Amount: " . $row['total_paid'] . "<br>";
    echo "Concession: " . $row['total_concession'] . "<br>";
    echo "Scholarship: " . $row['total_scholarship'] . "<br>";
    echo "Refund: " . $row['total_refund'] . "<br>";

    // Distribute data to relevant tables

    // $conn->query("
    //     INSERT INTO financial_trans (voucher_number, trans_date, admission_number, amount)
    //     SELECT voucher_number, trans_date, admission_number, SUM(due_amount + paid_amount + concession + scholarship + refund)
    //     FROM temp_import
    //     WHERE voucher_number IN ('DUE', 'SCHOLARSHIP', 'CONCESSION')
    //     GROUP BY voucher_number, trans_date, admission_number
    // ");

    // table- feecategory
    $conn->query("
        INSERT INTO feecategory (fee_category, br_id)
        SELECT DISTINCT temp_import.fee_category, branches.id 
        FROM temp_import
        CROSS JOIN branches
        WHERE temp_import.fee_category <> '' AND NOT EXISTS (
            SELECT 1 
            FROM feecategory 
            WHERE feecategory.fee_category = temp_import.fee_category 
            AND feecategory.br_id = branches.id
        )
    ");

    // table- feetypes

    // Get distinct f_name, Seq_id 
    $fname_seq_ids = [];
    $ftypes = $conn->query("SELECT f_name, Seq_id  FROM feetypes GROUP BY f_name, Seq_id");
    while ($row = $ftypes->fetch_assoc()) {
        $fname_seq_ids[$row['f_name']] = $row['Seq_id'];
    }

    $modules = [];
    $moduleTypes = $conn->query("SELECT ModuleID, Module FROM module");
    while ($row = $moduleTypes->fetch_assoc()) {
        $modules[$row['Module']] = $row['ModuleID'];
    }


    foreach ($conn->query("SELECT DISTINCT f_name FROM temp_import WHERE f_name <> ''") as $row) {
        if (isset($fname_seq_ids[$row['f_name']])) {
            $Seq_id = $fname_seq_ids[$row['f_name']];
        } else {
            if (empty($fname_seq_ids)) {
                $Seq_id = 1;
            } else {
                $Seq_id = max($fname_seq_ids) + 1;
            }

            $fname_seq_ids[$row['f_name']] = $Seq_id;
        }

        $module_id = $modules['academic'];

        if (strpos($row['f_name'], 'Fine') !== false) {
            $module_id = $modules['academicmisc'];
        } elseif (strpos($row['f_name'], 'Mess') !== false) {
            $module_id = $modules['hostel'];
        }

        $conn->query("
            INSERT INTO feetypes (fee_category, f_name, Collection_id, br_id, Seq_id, Fee_type_ledger, Fee_headtype)
            SELECT 
                '1' AS fee_category,
                '{$row['f_name']}' AS f_name,
                '1' AS Collection_id,
                branches.id AS br_id,
                '{$Seq_id}' AS Seq_id,
                '{$row['f_name']}' AS Fee_type_ledger,
                '{$module_id}' AS Fee_headtype
            FROM branches
            WHERE NOT EXISTS (
                SELECT 1 
                FROM feetypes 
                WHERE feetypes.f_name = '{$row['f_name']}' 
                AND feetypes.br_id = branches.id
            )
        ");
    }


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
                        <h3 class="text-center">Upload Excel File</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="csv_file">Excel File</label>
                                <input type="file" name="csv_file" id="csv_file" class="form-control-file" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">Import</button>
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