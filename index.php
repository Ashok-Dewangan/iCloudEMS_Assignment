<?php
// Show all errors
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

    // Start transaction
    $conn->begin_transaction();

    // Create table without indexes
    $createTable = "
        CREATE TABLE temp_import (
            sr_no INT,
            tranDate DATE,
            acadYear VARCHAR(15),
            financialYear VARCHAR(15),
            category VARCHAR(50),
            Entrymode VARCHAR(50),
            voucherno VARCHAR(50),
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
    $conn->query($createTable);

    try {
        $handle = fopen($file, "r");
        if (!$handle) die("Error opening file!");

        // Skip the first 6 lines (headers)
        for ($i = 0; $i < 6; $i++) {
            fgetcsv($handle, 1000, ",");
        }

        // Batch Processing Setup
        $batchSize = 50000;  // Adjust batch size if needed
        $batchData = [];

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Convert large numbers to strings to prevent scientific notation (3.02E+11 issue)

            if (is_numeric($data[15]) && strpos($data[15], 'E+') !== false) {
                $data[15] = sprintf("%.0f", $data[15]);
            }

            $data[1] = date('Y-m-d', strtotime($data[1]));

            // Escape strings to prevent SQL injection
            $data = array_map([$conn, "real_escape_string"], $data);

            // Add to batch
            $batchData[] = "('" . implode("', '", $data) . "')";

            // Execute batch when it reaches the limit
            if (count($batchData) >= $batchSize) {
                $sql = "INSERT INTO temp_import (
                sr_no, tranDate, acadYear, financialYear, category, Entrymode, voucherno, rollno, admno, status, fee_category, branch_name, program, department, batch, receiptId, f_name, due_amount, paid_amount, concession_amount, scholarship_amount, rev_concession_amount, write_off_amount, adjusted_amount, refund_amount, fund_transfer_amount, remarks
            ) VALUES " . implode(", ", $batchData);

                $conn->query($sql); // Single Query Execution
                $batchData = []; // Reset batch
            }
        }

        // Insert any remaining rows
        if (!empty($batchData)) {
            $sql = "INSERT INTO temp_import (
            sr_no, tranDate, acadYear, financialYear, category, Entrymode, voucherno, rollno, admno, status, fee_category, branch_name, program, department, batch, receiptId, f_name, due_amount, paid_amount, concession_amount, scholarship_amount, rev_concession_amount, write_off_amount, adjusted_amount, refund_amount, fund_transfer_amount, remarks
        ) VALUES " . implode(", ", $batchData);

            $conn->query($sql);
        }

        fclose($handle);

        // Add indexes after data insertion
        $conn->query("
            ALTER TABLE temp_import
            ADD INDEX (sr_no),
            ADD INDEX (tranDate),
            ADD INDEX (acadYear),
            ADD INDEX (financialYear),
            ADD INDEX (category),
            ADD INDEX (Entrymode),
            ADD INDEX (voucherno),
            ADD INDEX (rollno),
            ADD INDEX (admno),
            ADD INDEX (status),
            ADD INDEX (fee_category),
            ADD INDEX (branch_name),
            ADD INDEX (program),
            ADD INDEX (department),
            ADD INDEX (batch),
            ADD INDEX (receiptId),
            ADD INDEX (f_name)
        ");

        // Verify imported data
        $result = $conn->query("
            SELECT 
                COUNT(*) AS total_records,
                SUM(due_amount) AS total_due,
                SUM(paid_amount) AS total_paid,
                SUM(concession_amount) AS total_concession,
                SUM(scholarship_amount) AS total_scholarship,
                SUM(refund_amount) AS total_refund            
            FROM temp_import
        ");

        $row = $result->fetch_assoc();

        echo "Total Records: " . $row['total_records'] . "<br>";
        echo "Due Amount: " . $row['total_due'] . "<br>";
        echo "Paid Amount: " . $row['total_paid'] . "<br>";
        echo "Concession: " . $row['total_concession'] . "<br>";
        echo "Scholarship: " . $row['total_scholarship'] . "<br>";
        echo "Refund: " . $row['total_refund'] . "<br>";


        // Distribute data to relevant tables

        // table- feecategory
        $conn->query("
            INSERT INTO feecategory (fee_category, br_id)
            SELECT DISTINCT temp_import.fee_category, branches.id 
            FROM temp_import
            CROSS JOIN branches
            LEFT JOIN feecategory 
                ON feecategory.fee_category = temp_import.fee_category 
                AND feecategory.br_id = branches.id
            WHERE temp_import.fee_category <> '' 
            AND feecategory.fee_category IS NULL
        ");

        // table- feetypes
        $fname_seq_ids = [];
        $ftypes = $conn->query("SELECT f_name, Seq_id FROM feetypes GROUP BY f_name, Seq_id");
        while ($row = $ftypes->fetch_assoc()) {
            $fname_seq_ids[$row['f_name']] = $row['Seq_id'];
        }

        $modules = [];
        $moduleTypes = $conn->query("SELECT ModuleID, Module FROM module");
        while ($row = $moduleTypes->fetch_assoc()) {
            $modules[$row['Module']] = $row['ModuleID'];
        }

        $branches = [];
        $branchTypes = $conn->query("SELECT id, branch_name FROM branches");
        while ($row = $branchTypes->fetch_assoc()) {
            $branches[$row['branch_name']] = $row['id'];
        }

        $entryModes = [];
        $entryModeIds = [];
        $entryModeTypes = $conn->query("SELECT Entry_modename, crdr, entrymodeno FROM entrymode");
        while ($row = $entryModeTypes->fetch_assoc()) {
            $entryModes[$row['Entry_modename']] = $row['crdr'];
            $entryModeIds[$row['Entry_modename']] = $row['entrymodeno'];
        }

        // Fetch all distinct f_name values from temp_import
        $result = $conn->query("SELECT DISTINCT f_name FROM temp_import WHERE f_name <> ''");

        while ($row = $result->fetch_assoc()) {

            if (isset($fname_seq_ids[$row['f_name']])) {
                $Seq_id = $fname_seq_ids[$row['f_name']];
            } else {
                $Seq_id = empty($fname_seq_ids) ? 1 : max($fname_seq_ids) + 1;
                $fname_seq_ids[$row['f_name']] = $Seq_id;
            }

            $module_id = $modules['academic'];

            if (strpos($row['f_name'], 'Fine') !== false) {
                $module_id = $modules['academicmisc'];
            } elseif (strpos($row['f_name'], 'Mess') !== false) {
                $module_id = $modules['hostel'];
            }

            // Efficiently insert only non-existing records
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
                LEFT JOIN feetypes ON feetypes.f_name = '{$row['f_name']}' AND feetypes.br_id = branches.id
                WHERE feetypes.f_name IS NULL
            ");
        }

        // $greenEntryModes = ['RCPT', 'REVRCPT', 'JV', 'REVJV', 'PMT', 'REVPMT', 'Fundtransfer'];
        // $redEntryModes = ['DUE', 'REVDUE', 'SCHOLARSHIP', 'SCHOLARSHIPREV/REVCONCESSION', 'CONCESSION'];

        $commonFeeCollection = $conn->query("
            SELECT 
                receiptId, admno, rollno, tranDate, voucherno, acadYear, financialYear, branch_name, Entrymode, f_name,
                SUM(paid_amount + adjusted_amount + refund_amount + fund_transfer_amount) AS amount,
                CASE 
                    WHEN Entrymode IN ('RCPT', 'JV', 'PMT') THEN 0
                    WHEN Entrymode IN ('REVRCPT', 'REVJV', 'REVPMT') THEN 1
                    ELSE NULL
                END AS inactive
            FROM temp_import 
            WHERE Entrymode IN ('RCPT', 'REVRCPT', 'JV', 'REVJV', 'PMT', 'REVPMT', 'Fundtransfer')
            GROUP BY receiptId, admno, rollno, tranDate
        ");

        while ($row = $commonFeeCollection->fetch_assoc()) {
            $brId = $branches[$row['branch_name']] ?? 0;

            $row['Entrymode'] = in_array($row['Entrymode'], ['REVSCHOLARSHIP', 'REVCONCESSION']) ? 'SCHOLARSHIPREV/REVCONCESSION' : $row['Entrymode'];

            $entrymode = $entryModeIds[$row['Entrymode']] ?? 0;
            $transid = uniqid(mt_rand(), true);

            $module_id = $modules['academic'];
            if (strpos($row['f_name'], 'Fine') !== false) {
                $module_id = $modules['academicmisc'];
            } elseif (strpos($row['f_name'], 'Mess') !== false) {
                $module_id = $modules['hostel'];
            }

            $result = $conn->query("
                INSERT INTO commonfeecollection (moduleId, transId, admno, rollno, amount, brId, acadamicYear, financialYear, displayReceiptNo, Entrymode, PaidDate, inactive) 
                VALUES (
                    '{$module_id}',
                    '{$transid}',
                    '{$row['admno']}',
                    '{$row['rollno']}',
                    '{$row['amount']}',
                    '{$brId}',
                    '{$row['acadYear']}',
                    '{$row['financialYear']}',
                    '{$row['receiptId']}',
                    '{$entrymode}',
                    '{$row['tranDate']}',
                    '{$row['inactive']}'
                )
            ");

            if ($result) {
                $insert_id = $conn->insert_id;

                $commonFeeCollectionHeadWise = $conn->query("
                    SELECT 
                        f_name, 
                        SUM(paid_amount + adjusted_amount + refund_amount + fund_transfer_amount) AS amount
                    FROM temp_import
                    WHERE receiptId = '{$row['receiptId']}' AND admno = '{$row['admno']}' AND rollno = '{$row['rollno']}' AND tranDate = '{$row['tranDate']}' AND Entrymode IN ('RCPT', 'REVRCPT', 'JV', 'REVJV', 'PMT', 'REVPMT', 'Fundtransfer')
                    GROUP BY f_name
                ");

                while ($rowChild = $commonFeeCollectionHeadWise->fetch_assoc()) {

                    $module_id = $modules['academic'];
                    if (strpos($rowChild['f_name'], 'Fine') !== false) {
                        $module_id = $modules['academicmisc'];
                    } elseif (strpos($rowChild['f_name'], 'Mess') !== false) {
                        $module_id = $modules['hostel'];
                    }

                    $conn->query("
                        INSERT INTO commonfeecollectionheadwise (moduleId, receiptId, headId, headName, brid, amount)
                        SELECT 
                            '{$module_id}' AS moduleId,
                            '{$insert_id}' AS receiptId,
                            feetypes.id AS headId,
                            '{$rowChild['f_name']}' AS headName,
                            '{$brId}' AS brid,
                            '{$rowChild['amount']}' AS amount
                        FROM feetypes
                        WHERE feetypes.f_name = '{$rowChild['f_name']}' AND feetypes.br_id = '{$brId}'
                    ");
                }
            }
        }


        // table financialtran - Red
        $financialTransaction = $conn->query("
            SELECT 
                voucherno, tranDate, admno, branch_name, acadYear, Entrymode, f_name,
                SUM(due_amount + concession_amount + scholarship_amount + write_off_amount + rev_concession_amount) AS amount,
                CASE 
                    WHEN SUM(concession_amount) > 0 THEN 1
                    WHEN SUM(scholarship_amount) > 0 THEN 2
                    ELSE NULL
                END AS Typeofconcession
            FROM temp_import 
            WHERE Entrymode IN ('DUE', 'REVDUE', 'SCHOLARSHIP', 'REVSCHOLARSHIP', 'REVCONCESSION', 'CONCESSION')
            GROUP BY voucherno, tranDate, admno 
        ");

        while ($row = $financialTransaction->fetch_assoc()) {
            $brId = $branches[$row['branch_name']] ?? 0;

            $row['Entrymode'] = in_array($row['Entrymode'], ['REVSCHOLARSHIP', 'REVCONCESSION']) ? 'SCHOLARSHIPREV/REVCONCESSION' : $row['Entrymode'];
            $crdr = $entryModes[$row['Entrymode']] ?? '';
            $entrymode = $entryModeIds[$row['Entrymode']] ?? 0;

            $transid = uniqid(mt_rand(), true);

            $module_id = $modules['academic'];
            if (strpos($row['f_name'], 'Fine') !== false) {
                $module_id = $modules['academicmisc'];
            } elseif (strpos($row['f_name'], 'Mess') !== false) {
                $module_id = $modules['hostel'];
            }

            $result = $conn->query("
                INSERT INTO financialtran 
                    (moduleid, transid, admno, amount, crdr, tranDate, acadYear, Entrymode, voucherno, brid, Typeofconcession)
                VALUES (
                    '{$module_id}',
                    '{$transid}',
                    '{$row['admno']}',
                    '{$row['amount']}',
                    '{$crdr}',
                    '{$row['tranDate']}',
                    '{$row['acadYear']}',
                    '{$entrymode}',
                    '{$row['voucherno']}',
                    '{$brId}',
                    '{$row['Typeofconcession']}'
                )
            ");

            if ($result) {
                $insert_id = $conn->insert_id;

                $financialTransactionHeadWise = $conn->query("
                    SELECT 
                        f_name, 
                        SUM(due_amount + concession_amount + scholarship_amount + write_off_amount + rev_concession_amount) AS amount
                    FROM temp_import
                    WHERE voucherno = '{$row['voucherno']}' AND admno = '{$row['admno']}' AND tranDate = '{$row['tranDate']}' AND Entrymode IN ('DUE', 'REVDUE', 'SCHOLARSHIP', 'REVSCHOLARSHIP', 'REVCONCESSION', 'CONCESSION')
                    GROUP BY f_name
                ");

                while ($rowChild = $financialTransactionHeadWise->fetch_assoc()) {

                    $module_id = $modules['academic'];
                    if (strpos($rowChild['f_name'], 'Fine') !== false) {
                        $module_id = $modules['academicmisc'];
                    } elseif (strpos($rowChild['f_name'], 'Mess') !== false) {
                        $module_id = $modules['hostel'];
                    }

                    $conn->query("
                        INSERT INTO financialtrandetail (financialTranId, moduleId, amount, headId, crcd, brid, head_name)
                        SELECT 
                            '{$insert_id}' AS financialTranId,
                            '{$module_id}' AS moduleId,
                            '{$rowChild['amount']}' AS amount,
                            feetypes.id AS headId,
                            '{$crdr}' AS crcd,
                            '{$brId}' AS brid,
                            '{$rowChild['f_name']}' AS head_name
                        FROM feetypes
                        WHERE feetypes.f_name = '{$rowChild['f_name']}' AND feetypes.br_id = '{$brId}'
                    ");
                }
            }
        }


        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
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